<?php

namespace Resofire\Picks\Confidence;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Week;

class ConfidenceEntry extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_confidence_entries';

    protected $fillable = ['user_id', 'week_id', 'tiebreaker_total'];

    protected $casts = ['user_id' => 'integer', 'week_id' => 'integer', 'tiebreaker_total' => 'integer'];

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
