<?php

namespace Resofire\Picks;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * @property int         $id
 * @property int         $user_id
 * @property int         $event_id
 * @property string      $selected_outcome
 * @property bool|null   $is_correct
 * @property int|null    $confidence
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Pick extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_picks';

    protected $fillable = [
        'user_id',
        'event_id',
        'selected_outcome',
        'is_correct',
        'confidence',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'confidence' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function event()
    {
        return $this->belongsTo(PickEvent::class, 'event_id');
    }
}
