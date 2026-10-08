<?php

namespace Resofire\Picks;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property int         $season_id
 * @property string      $name
 * @property int|null    $week_number
 * @property string      $season_type
 * @property string|null $start_date
 * @property string|null $end_date
 * @property bool        $is_open
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Season|null $season
 */
class Week extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_weeks';

    protected $fillable = [
        'season_id',
        'name',
        'week_number',
        'season_type',
        'start_date',
        'end_date',
        'is_open',
    ];

    protected $casts = [
        'is_open' => 'boolean',
        'season_id' => 'integer',
        'week_number' => 'integer',
    ];

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return HasMany<PickEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PickEvent::class, 'week_id');
    }

    /** @return HasMany<UserScore, $this> */
    public function userScores(): HasMany
    {
        return $this->hasMany(UserScore::class);
    }
}
