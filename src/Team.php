<?php

namespace Resofire\Picks;

use Flarum\Database\AbstractModel;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Str;

/**
 * @property int         $id
 * @property string      $name
 * @property string      $slug
 * @property string|null $abbreviation
 * @property string|null $conference
 * @property int|null    $cfbd_id
 * @property int|null    $espn_id
 * @property string|null $logo_path
 * @property string|null $logo_dark_path
 * @property bool        $logo_custom
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Team extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'picks_teams';

    protected $fillable = [
        'name',
        'slug',
        'abbreviation',
        'conference',
        'cfbd_id',
        'espn_id',
        'logo_path',
        'logo_dark_path',
        'logo_custom',
    ];

    protected $casts = [
        'logo_custom' => 'boolean',
        'cfbd_id'     => 'integer',
        'espn_id'     => 'integer',
    ];

    /**
     * Returns the full public URL for the standard logo, or null if none set.
     */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->resolveLogoUrl($this->logo_path);
    }

    /**
     * Returns the full public URL for the dark logo, falling back to the
     * standard logo URL if no dark variant is stored.
     */
    public function getLogoDarkUrlAttribute(): ?string
    {
        return $this->resolveLogoUrl($this->logo_dark_path)
            ?? $this->resolveLogoUrl($this->logo_path);
    }

    private function resolveLogoUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $baseUrl = resolve(SettingsRepositoryInterface::class)->get('url');

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
