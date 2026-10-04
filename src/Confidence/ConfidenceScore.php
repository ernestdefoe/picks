<?php

namespace Resofire\Picks\Confidence;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Week;

class ConfidenceScore extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_confidence_scores';

    protected $fillable = ['user_id', 'season_id', 'week_id', 'scope', 'total_points', 'total_picks', 'correct_picks', 'accuracy', 'tiebreak_diff', 'previous_rank', 'current_rank'];

    protected $casts = ['user_id' => 'integer', 'season_id' => 'integer', 'week_id' => 'integer', 'total_points' => 'integer', 'total_picks' => 'integer', 'correct_picks' => 'integer', 'accuracy' => 'float', 'tiebreak_diff' => 'integer', 'previous_rank' => 'integer', 'current_rank' => 'integer'];

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
