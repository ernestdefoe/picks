<?php

namespace Resofire\Picks;

use Flarum\Database\AbstractModel;

/**
 * @property int         $id
 * @property string      $name
 * @property string      $slug
 * @property int         $year
 * @property string      $league
 * @property string|null $start_date
 * @property string|null $end_date
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Season extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_seasons';

    protected $fillable = [
        'name',
        'slug',
        'year',
        'league',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'year' => 'integer',
    ];

    /**
     * Which competition this season is.
     *
     * 🚨 Resolved through the registry rather than read raw, so a season whose
     * league has since been removed — an extension uninstalled, a key renamed —
     * falls back to college football instead of handing a null to everything
     * downstream. A season in the wrong vocabulary is recoverable; a scheduled
     * job that dies on it takes every other league's fixtures with it.
     */
    public function leagueDefinition(): \Resofire\Picks\Service\Leagues\League
    {
        return (new \Resofire\Picks\Service\Leagues\Leagues())->get($this->league);
    }

    public function weeks()
    {
        return $this->hasMany(Week::class);
    }

    public function userScores()
    {
        return $this->hasMany(UserScore::class);
    }
}
