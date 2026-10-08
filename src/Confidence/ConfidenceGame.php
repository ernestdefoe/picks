<?php

namespace Resofire\Picks\Confidence;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Week;

/**
 * @property int $id
 * @property int $week_id
 * @property int $event_id
 * @property int $position
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read PickEvent|null $event
 */
class ConfidenceGame extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_confidence_games';

    protected $fillable = ['week_id', 'event_id', 'position'];

    protected $casts = ['week_id' => 'integer', 'event_id' => 'integer', 'position' => 'integer'];

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
