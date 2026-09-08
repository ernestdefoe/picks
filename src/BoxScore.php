<?php

namespace Resofire\Picks;

use Flarum\Database\AbstractModel;

/**
 * A finished game's box score, normalised.
 *
 * @property int    $id
 * @property int    $event_id
 * @property string $payload
 * @property \Carbon\Carbon $fetched_at
 */
class BoxScore extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_box_scores';

    protected $fillable = ['event_id', 'payload', 'fetched_at'];

    protected $casts = ['fetched_at' => 'datetime'];

    public function event()
    {
        return $this->belongsTo(PickEvent::class, 'event_id');
    }

    /**
     * The document, decoded.
     *
     * @return array<string, mixed>
     */
    public function getDocumentAttribute(): array
    {
        $decoded = json_decode((string) $this->payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
