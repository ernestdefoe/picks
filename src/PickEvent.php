<?php

namespace Resofire\Picks;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

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
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
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
    ];

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

    public function week()
    {
        return $this->belongsTo(Week::class);
    }

    public function homeTeam()
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam()
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function picks()
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

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
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

    public function getPickForUser(int $userId): ?Pick
    {
        return $this->picks()->where('user_id', $userId)->first();
    }
}
