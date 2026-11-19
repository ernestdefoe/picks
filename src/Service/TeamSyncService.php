<?php

namespace Resofire\Picks\Service;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Service\Leagues\Leagues;
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
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $log
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
        $logos = 0;
        $errors = [];

        foreach ($apiTeams as $apiTeam) {
            $synced = $this->upsertTeam($apiTeam);

            if ($synced === null) {
                continue;
            }

            [$team, $isNew, $logoUrl, $logoDarkUrl] = $synced;

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
                && $this->downloadSyncedLogos($team, $logoUrl, $logoDarkUrl, $errors)
            ) {
                $logos++;
            }
        }

        $this->settings->set(
            'ernestdefoe-picks.last_teams_sync',
            Carbon::now()->toIso8601String()
        );

        return compact('created', 'updated', 'logos', 'errors');
    }

    /**
     * Download logos for one batch of the teams missing them, those after
     * $afterId in id order.
     *
     * @return array{saved: int, failed: int, remaining: int, tried: int, last_id: int}
     */
    public function syncLogos(int $batchSize = 20, int $afterId = 0): array
    {
        // The next teams that need logos, after the last one tried, with
        // their ESPN IDs so we can build URLs.
        $teams = Team::whereNull('logo_path')
            ->whereNotNull('espn_id')
            ->where('logo_custom', false)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        $elsewhere = $this->nonCollegeTeamIds($teams->pluck('id')->all());

        $saved = 0;
        $failed = 0;

        foreach ($teams as $team) {
            $urls = $this->logoUrls($team, ! in_array((int) $team->id, $elsewhere, true));

            if ($urls === null) {
                $failed++;

                continue;
            }

            try {
                if ($this->download($team, ...$urls)) {
                    $saved++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->log->warning('[picks] logo download failed for '.$team->slug.' from '.$urls[0].': '.$e->getMessage());
                $failed++;
            }
        }

        $remaining = Team::whereNull('logo_path')
            ->whereNotNull('espn_id')
            ->where('logo_custom', false)
            ->count();

        return compact('saved', 'failed', 'remaining') + [
            'tried' => $teams->count(),
            'last_id' => (int) ($teams->last()->id ?? $afterId),
        ];
    }

    /**
     * Re-download logos for a single team. Respects logo_custom flag.
     */
    public function refreshLogos(Team $team): bool
    {
        if ($team->logo_custom) {
            return false;
        }

        $urls = $this->logoUrls($team, $this->nonCollegeTeamIds([(int) $team->id]) === []);

        return $urls !== null && $this->download($team, ...$urls);
    }

    /**
     * Where a team's crest can be fetched from: [standard, dark or null].
     *
     * 🚨 The ESPN college CDN path only for college teams. ESPN numbers its
     * teams PER LEAGUE, so the same number in the college path is somebody
     * else's crest: the Buffalo Bills (NFL team 2) came back as Auburn. A club
     * from another league is fetched from the crest URL its fixture carried, or
     * not at all.
     *
     * @return array{0: string, 1: string|null}|null
     */
    protected function logoUrls(Team $team, bool $college): ?array
    {
        $path = (string) $team->logo_path;

        if (preg_match('#^https?://#i', $path)) {
            $dark = str_contains($path, 'espncdn.com') && str_contains($path, '/500/')
                ? str_replace('/500/', '/500-dark/', $path)
                : null;

            return [$path, $dark];
        }

        if (! $college || ! $team->espn_id) {
            return null;
        }

        return [
            'https://a.espncdn.com/i/teamlogos/ncaa/500/'.$team->espn_id.'.png',
            'https://a.espncdn.com/i/teamlogos/ncaa/500-dark/'.$team->espn_id.'.png',
        ];
    }

    /** Download a team's crest and keep it. True when the standard one arrived. */
    protected function download(Team $team, string $logoUrl, ?string $logoDarkUrl): bool
    {
        $paths = $this->logoService->downloadFromUrls($logoUrl, $logoDarkUrl, $team->slug);

        if ($paths['logo_path'] === null) {
            return false;
        }

        $team->logo_path = $paths['logo_path'];

        if ($paths['logo_dark_path'] !== null) {
            $team->logo_dark_path = $paths['logo_dark_path'];
        }

        $team->save();

        return true;
    }

    /**
     * Of these teams, the ones with a game in a league other than college
     * football. One query for the whole batch.
     *
     * @param int[] $teamIds
     * @return int[]
     */
    protected function nonCollegeTeamIds(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $games = PickEvent::query()
            ->join('picks_weeks', 'picks_weeks.id', '=', 'picks_events.week_id')
            ->join('picks_seasons', 'picks_seasons.id', '=', 'picks_weeks.season_id')
            ->where('picks_seasons.league', '!=', Leagues::DEFAULT)
            ->where(fn ($q) => $q->whereIn('picks_events.home_team_id', $teamIds)->orWhereIn('picks_events.away_team_id', $teamIds))
            ->distinct()
            ->get(['picks_events.home_team_id', 'picks_events.away_team_id']);

        return array_values(array_intersect(
            $teamIds,
            $games->flatMap(fn ($g) => [(int) $g->home_team_id, (int) $g->away_team_id])->unique()->all()
        ));
    }

    /**
     * Create or update one CFBD team row.
     *
     * @param array<string, mixed> $apiTeam
     * @return array{0: Team, 1: bool, 2: string|null, 3: string|null}|null [team, isNew, logoUrl, logoDarkUrl]
     */
    protected function upsertTeam(array $apiTeam): ?array
    {
        $cfbdId = Arr::get($apiTeam, 'id');
        $name = Arr::get($apiTeam, 'school');
        $abbrev = Arr::get($apiTeam, 'abbreviation');
        $conference = Arr::get($apiTeam, 'conference');

        // CFBD returns logos as an array of URLs directly.
        // Index 0 = standard, index 1 = dark variant.
        $logoUrls = Arr::get($apiTeam, 'logos', []);
        $logoUrl = $logoUrls[0] ?? null;
        $logoDarkUrl = $logoUrls[1] ?? null;

        // Extract the ESPN ID from the logo URL for storage reference.
        // e.g. "https://a.espncdn.com/i/teamlogos/ncaa/500/333.png" -> 333
        $espnId = null;
        if ($logoUrl && preg_match('/\/(\d+)\.png$/', $logoUrl, $matches)) {
            $espnId = (int) $matches[1];
        }

        if (! $cfbdId || ! $name) {
            return null;
        }

        $slug = Str::slug($name);

        $team = Team::where('cfbd_id', $cfbdId)->first()
            ?? Team::where('slug', $slug)->first();

        $isNew = $team === null;

        if ($isNew) {
            $team = new Team();
            $team->slug = $slug;
        }

        $team->name = $name;
        $team->abbreviation = $abbrev;
        $team->conference = $conference;
        $team->cfbd_id = $cfbdId;

        if ($espnId) {
            $team->espn_id = $espnId;
        }

        $team->save();

        return [$team, $isNew, $logoUrl, $logoDarkUrl];
    }

    /**
     * Download a synced team's logos. True when either path changed.
     *
     * @param string[] $errors
     */
    protected function downloadSyncedLogos(Team $team, ?string $logoUrl, ?string $logoDarkUrl, array &$errors): bool
    {
        $name = $team->name;

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
            }

            return $dirty;
        } catch (\Throwable $e) {
            $this->log->warning('[picks] logo download failed for '.$name.': '.$e->getMessage());
            $errors[] = "Logo download failed for {$name}: ".$e->getMessage();

            return false;
        }
    }
}
