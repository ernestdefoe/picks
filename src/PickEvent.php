<?php

namespace Resofire\Picks;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property int|null    $week_id
 * @property int         $home_team_id
 * @property int         $away_team_id
 * @property int|null    $cfbd_id
 * @property string|null $external_id
 * @property bool        $neutral_site
 * @property \Carbon\Carbon $match_date
 * @property \Carbon\Carbon $cutoff_date
 * @property string      $status
 * @property int|null    $home_score
 * @property int|null    $away_score
 * @property string|null $result
 * @property int         $home_rank
 * @property int         $away_rank
 * @property string      $home_record
 * @property string      $away_record
 * @property string      $venue
 * @property string      $venue_city
 * @property bool        $time_tbd
 * @property string      $broadcast
 * @property array|null  $broadcasts
 * @property array|null  $highlights
 * @property \Carbon\Carbon|null $highlights_checked_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property int $period
 * @property string $clock
 * @property string $clock_detail
 * @property int $clock_at
 * @property string $possession
 * @property string $down_distance
 * @property bool $red_zone
 * @property string $ball_on
 * @property-read Week|null $week
 * @property-read Team|null $homeTeam
 * @property-read Team|null $awayTeam
 */
class PickEvent extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_events';

    const STATUS_SCHEDULED   = 'scheduled';
    const STATUS_CLOSED      = 'closed';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_FINISHED    = 'finished';

    const RESULT_HOME = 'home';
    const RESULT_AWAY = 'away';

    protected $fillable = [
        'week_id',
        'home_team_id',
        'away_team_id',
        'cfbd_id',
        'external_id',
        'neutral_site',
        'match_date',
        'cutoff_date',
        'status',
        'home_score',
        'away_score',
        'result',
        // In-play state; see the clock migration for why clock_at is separate.
        'period',
        'clock',
        'clock_detail',
        'clock_at',
        'possession',
        'down_distance',
        'ball_on',
        'red_zone',
        // The lead-in; see the migration for why a rank belongs to the GAME.
        'home_rank',
        'away_rank',
        'home_record',
        'away_record',
        'venue',
        'venue_city',
        'broadcast',
        // Every listing + the watch link; see its migration.
        'broadcasts',
        // Highlight clip ids + captions; see its migration.
        'highlights',
        'highlights_checked_at',
        // Kickoff date known, time not yet announced; see its migration.
        'time_tbd',
    ];

    protected $casts = [
        'match_date'  => 'datetime',
        'cutoff_date' => 'datetime',
        'neutral_site' => 'boolean',
        'home_score'  => 'integer',
        'away_score'  => 'integer',
        'period'      => 'integer',
        'clock_at'    => 'integer',
        'red_zone'    => 'boolean',
        'home_rank'   => 'integer',
        'away_rank'   => 'integer',
        'time_tbd'    => 'boolean',
        'highlights'  => 'array',
        'highlights_checked_at' => 'datetime',
    ];

    /**
     * The full listing, decoded. Null when the feed never sent one.
     *
     * @return array{listings: list<array{name: string, type: string, market: string}>, watch: string, premium: bool}|null
     */
    public function getBroadcastsAttribute(mixed $value): ?array
    {
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 🚨 The watch link SURVIVES a payload that does not carry one.
     *
     * ESPN puts it on the event only while the game is being played, and drops
     * it between polls as often as not. A writer that simply replaced the
     * column would make the Watch button point somewhere different every
     * minute, so the last link seen is kept until a new one arrives. Done here,
     * once, because three different syncs write this column.
     *
     * An empty listing with no link stores NULL: nothing known is not a fact.
     */
    public function setBroadcastsAttribute(mixed $value): void
    {
        $new = is_string($value) ? json_decode($value, true) : $value;
        $new = is_array($new) ? $new : [];

        $old = $this->getBroadcastsAttribute($this->attributes['broadcasts'] ?? null) ?? [];

        $listings = array_values((array) ($new['listings'] ?? []));
        $watch = (string) ($new['watch'] ?? '');
        $premium = (bool) ($new['premium'] ?? false);

        if ($watch === '' && ($old['watch'] ?? '') !== '') {
            $watch = (string) $old['watch'];
            $premium = (bool) ($old['premium'] ?? false);
        }

        $this->attributes['broadcasts'] = $listings === [] && $watch === ''
            ? null
            : json_encode(['listings' => $listings, 'watch' => $watch, 'premium' => $premium], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected static function booted(): void
    {
        static::saving(function (PickEvent $event) {
            // Auto-calculate result when both scores are present.
            if (
                $event->isDirty(['home_score', 'away_score'])
                && $event->home_score !== null
                && $event->away_score !== null
            ) {
                $event->result = $event->calculateResult();

                if ($event->result !== null && $event->status === self::STATUS_SCHEDULED) {
                    $event->status = self::STATUS_FINISHED;
                }
            }

            /*
             * 🚨 A lock that moved LATER reopens the game it had closed.
             *
             * A fixture synced before its kickoff was announced carries a
             * placeholder lock of noon UTC on game day (see Kickoff). Any save
             * after that — a lead-in refresh, a re-sync — closed it, and when
             * the real 7:30pm kickoff arrived the lock moved but the status did
             * not, so the game stayed locked all afternoon. Nothing else sets
             * `closed`, and a closed game with a lock still ahead has not
             * kicked off, so reopening it is always right.
             */
            if (
                $event->status === self::STATUS_CLOSED
                && $event->result === null
                && $event->cutoff_date !== null
                && Carbon::now()->isBefore($event->cutoff_date)
            ) {
                $event->status = self::STATUS_SCHEDULED;
            }

            // Auto-close when the cutoff has passed and the event is still scheduled.
            if (
                $event->status === self::STATUS_SCHEDULED
                && $event->cutoff_date !== null
                && Carbon::now()->isAfter($event->cutoff_date)
            ) {
                $event->status = self::STATUS_CLOSED;
            }
        });
    }

    /** @return BelongsTo<Week, $this> */
    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    /** @return HasMany<Pick, $this> */
    public function picks(): HasMany
    {
        return $this->hasMany(Pick::class, 'event_id');
    }

    public function canPick(): bool
    {
        // Game must be scheduled and before cutoff
        if ($this->status !== self::STATUS_SCHEDULED) {
            return false;
        }

        if (! Carbon::now()->isBefore($this->cutoff_date)) {
            return false;
        }

        // Week must be open for picking
        if ($this->week_id !== null) {
            $week = $this->week;
            if ($week && ! $week->is_open) {
                return false;
            }
        }

        return true;
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    public function hasScores(): bool
    {
        return $this->home_score !== null && $this->away_score !== null;
    }

    public function calculateResult(): ?string
    {
        if (! $this->hasScores()) {
            return null;
        }

        if ($this->home_score > $this->away_score) {
            return self::RESULT_HOME;
        }

        if ($this->away_score > $this->home_score) {
            return self::RESULT_AWAY;
        }

        // College football does not end in draws; if scores are somehow equal
        // at the point of data entry we leave result null until corrected.
        return null;
    }
}
