<?php

namespace Resofire\Picks\Confidence;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Flarum\User\User;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Week;

/**
 * @property int $id
 * @property int $user_id
 * @property int $season_id
 * @property int|null $week_id
 * @property string $scope
 * @property int $total_points
 * @property int $total_picks
 * @property int $correct_picks
 * @property float $accuracy
 * @property int|null $tiebreak_diff
 * @property int|null $previous_rank
 * @property int|null $current_rank
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read User|null $user
 */
class ConfidenceScore extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_confidence_scores';

    protected $fillable = ['user_id', 'season_id', 'week_id', 'scope', 'total_points', 'total_picks', 'correct_picks', 'accuracy', 'tiebreak_diff', 'previous_rank', 'current_rank'];

    protected $casts = ['user_id' => 'integer', 'season_id' => 'integer', 'week_id' => 'integer', 'total_points' => 'integer', 'total_picks' => 'integer', 'correct_picks' => 'integer', 'accuracy' => 'float', 'tiebreak_diff' => 'integer', 'previous_rank' => 'integer', 'current_rank' => 'integer'];

    /** @return BelongsTo<Week, $this> */
    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PickEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(PickEvent::class, 'event_id');
    }
}
