<?php

namespace Resofire\Picks\Confidence;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Week;

class ConfidencePick extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_confidence_picks';

    protected $fillable = ['user_id', 'week_id', 'event_id', 'selected_outcome', 'confidence', 'is_correct'];

    protected $casts = ['user_id' => 'integer', 'week_id' => 'integer', 'event_id' => 'integer', 'confidence' => 'integer', 'is_correct' => 'boolean'];

    public function week()
    {
        return $this->belongsTo(Week::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function event()
    {
        return $this->belongsTo(PickEvent::class, 'event_id');
    }
}
