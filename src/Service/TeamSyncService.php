<?php

namespace Resofire\Picks\Service;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Resofire\Picks\Team;

class TeamSyncService
{
    /**
     * Valid FBS conference abbreviations used to filter teams returned by CFBD.
     * The /teams endpoint does not support server-side classification filtering,
     * so we filter client-side by checking the classification field on each team.
     */
    protected const FBS_CLASSIFICATION = 'fbs';

    public function __construct(
        protected CfbdService $cfbd,
        protected LogoService $logoService,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * Sync FBS teams from CFBD into the picks_teams table.
     *
     * Logo downloads are done in a separate pass to keep the sync fast and
     * avoid PHP execution timeout on large datasets. Pass $downloadLogos = true
     * to also download logos in the same request (only safe for small batches).
     */
    public function sync(bool $downloadLogos = false): array
    {
        $apiTeams = $this->cfbd->fetchTeams();

        // Filter to FBS only — CFBD /teams returns all classifications
        $apiTeams = array_filter($apiTeams, function (array $team) {
            $classification = strtolower((string) Arr::get($team, 'classification', ''));
            return $classification === self::FBS_CLASSIFICATION;
        });

        $created = 0;
        $updated = 0;
        $logos   = 0;
        $errors  = [];

        foreach ($apiTeams as $apiTeam) {
            $cfbdId     = Arr::get($apiTeam, 'id');
            $name       = Arr::get($apiTeam, 'school');
            $abbrev     = Arr::get($apiTeam, 'abbreviation');
            $conference = Arr::get($apiTeam, 'conference');

            // CFBD returns logos as an array of URLs directly.
            // Index 0 = standard, index 1 = dark variant.
            $logoUrls    = Arr::get($apiTeam, 'logos', []);
            $logoUrl     = $logoUrls[0] ?? null;
            $logoDarkUrl = $logoUrls[1] ?? null;

            // Extract the ESPN ID from the logo URL for storage reference.
            // e.g. "https://a.espncdn.com/i/teamlogos/ncaa/500/333.png" -> 333
            $espnId = null;
            if ($logoUrl && preg_match('/\/(\d+)\.png$/', $logoUrl, $matches)) {
                $espnId = (int) $matches[1];
            }

            if (! $cfbdId || ! $name) {
                continue;
            }

            $slug = Str::slug($name);

            $team = Team::where('cfbd_id', $cfbdId)->first()
                ?? Team::where('slug', $slug)->first();

            $isNew = $team === null;

            if ($isNew) {
                $team       = new Team();
                $team->slug = $slug;
            }

            $team->name         = $name;
            $team->abbreviation = $abbrev;
            $team->conference   = $conference;
            $team->cfbd_id      = $cfbdId;

            if ($espnId) {
                $team->espn_id = $espnId;
            }

            $team->save();

            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }

            // Download logos when explicitly requested and team doesn't have one yet
            if (
                $downloadLogos
                && ($logoUrl || $logoDarkUrl)
                && ! $team->logo_custom
                && ($isNew || ! $team->logo_path)
            ) {
                try {
                    $paths = $this->logoService->downloadFromUrls(
                        $logoUrl,
                        $logoDarkUrl,
                        $team->slug
                    );

                    $dirty = false;

                    if ($paths['logo_path'] !== null) {
                        $team->logo_path = $paths['logo_path'];
                        $dirty = true;
                    }

                    if ($paths['logo_dark_path'] !== null) {
                        $team->logo_dark_path = $paths['logo_dark_path'];
                        $dirty = true;
                    }

                    if ($dirty) {
                        $team->save();
                        $logos++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Logo download failed for {$name}: " . $e->getMessage();
                }
            }
        }

        $this->settings->set(
            'ernestdefoe-picks.last_teams_sync',
            Carbon::now()->toIso8601String()
        );

        return compact('created', 'updated', 'logos', 'errors');
    }

    /**
     * Download logos for FBS teams that are missing them, in batches.
     * Re-fetches the team list from CFBD to get the logo URLs.
     */
    public function syncLogos(int $batchSize = 20): array
    {
        // Get teams that need logos, with their ESPN IDs so we can build URLs
        $teams = Team::whereNull('logo_path')
            ->whereNotNull('espn_id')
            ->where('logo_custom', false)
            ->limit($batchSize)
            ->get();

        $saved     = 0;
        $failed    = 0;

        foreach ($teams as $team) {
            // Reconstruct the ESPN CDN URLs from the stored espn_id
            $logoUrl     = 'https://a.espncdn.com/i/teamlogos/ncaa/500/' . $team->espn_id . '.png';
            $logoDarkUrl = 'https://a.espncdn.com/i/teamlogos/ncaa/500-dark/' . $team->espn_id . '.png';

            try {
                $paths = $this->logoService->downloadFromUrls($logoUrl, $logoDarkUrl, $team->slug);

                $dirty = false;

                if ($paths['logo_path'] !== null) {
                    $team->logo_path = $paths['logo_path'];
                    $dirty = true;
                }

                if ($paths['logo_dark_path'] !== null) {
                    $team->logo_dark_path = $paths['logo_dark_path'];
                    $dirty = true;
                }

                if ($dirty) {
                    $team->save();
                    $saved++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $remaining = Team::whereNull('logo_path')
            ->whereNotNull('espn_id')
            ->where('logo_custom', false)
            ->count();

        return compact('saved', 'failed', 'remaining');
    }

    /**
     * Re-download logos for a single team. Respects logo_custom flag.
     */
    public function refreshLogos(Team $team): bool
    {
        if ($team->logo_custom || ! $team->espn_id) {
            return false;
        }

        $logoUrl     = 'https://a.espncdn.com/i/teamlogos/ncaa/500/' . $team->espn_id . '.png';
        $logoDarkUrl = 'https://a.espncdn.com/i/teamlogos/ncaa/500-dark/' . $team->espn_id . '.png';

        $paths = $this->logoService->downloadFromUrls($logoUrl, $logoDarkUrl, $team->slug);

        $saved = false;

        if ($paths['logo_path'] !== null) {
            $team->logo_path = $paths['logo_path'];
            $saved = true;
        }

        if ($paths['logo_dark_path'] !== null) {
            $team->logo_dark_path = $paths['logo_dark_path'];
        }

        if ($saved) {
            $team->save();
        }

        return $saved;
    }
}
