<?php

namespace Resofire\Picks\Confidence;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Week;

/**
 * @property int $id
 * @property int $user_id
 * @property int $week_id
 * @property int|null $tiebreaker_total
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class ConfidenceEntry extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_confidence_entries';

    protected $fillable = ['user_id', 'week_id', 'tiebreaker_total'];

    protected $casts = ['user_id' => 'integer', 'week_id' => 'integer', 'tiebreaker_total' => 'integer'];

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
