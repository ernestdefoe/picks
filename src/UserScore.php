<?php

namespace Resofire\Picks;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Flarum\User\User;
use Illuminate\Support\Collection;

/**
 * @property int         $id
 * @property int         $user_id
 * @property int|null    $season_id
 * @property int|null    $week_id
 * @property int         $total_points
 * @property int         $total_picks
 * @property int         $correct_picks
 * @property float       $accuracy
 * @property int|null    $previous_rank
 * @property int|null    $current_rank
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \Flarum\User\User|null $user
 */
class UserScore extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_user_scores';

    protected $fillable = [
        'user_id',
        'season_id',
        'week_id',
        'total_points',
        'total_picks',
        'correct_picks',
        'accuracy',
        'previous_rank',
        'current_rank',
    ];

    protected $casts = [
        'total_points'  => 'integer',
        'total_picks'   => 'integer',
        'correct_picks' => 'integer',
        'accuracy'      => 'float',
        'previous_rank' => 'integer',
        'current_rank'  => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<Week, $this> */
    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    /**
     * The 1-based rank of $points within a pre-loaded collection of score rows
     * (each having a `total_points`), computed in PHP so callers avoid a COUNT
     * query per week / season / scope. Shared by the user-scores and
     * user-history controllers so tie-breaking stays defined in one place.
     */
    public static function rankIn(Collection $scores, int|float $points): int
    {
        return $scores->where('total_points', '>', $points)->count() + 1;
    }
}
