<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Pick;
use Resofire\Picks\Service\CurrentSeasonService;
use Resofire\Picks\Service\PickStatsService;
use Resofire\Picks\Team;
use Resofire\Picks\UserScore;

class PublicStatsController implements RequestHandlerInterface
{
    /** Public stats are global (not per-actor); cache the whole payload briefly. */
    private const CACHE_TTL = 60;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected CurrentSeasonService $currentSeason,
        protected CacheRepository $cache,
        protected PickStatsService $pickStats
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.view');

        // Shared with the other picks endpoints via CurrentSeasonService.
        $currentWeek   = $this->currentSeason->getCurrentWeek();
        $currentWeekId = $currentWeek?->id;

        // This is a public, every-page-load endpoint that runs 10+ aggregate
        // queries. The data is identical for every viewer, so cache the computed
        // payload for a short window (keyed by the current week).
        $payload = $this->cache->remember(
            'ernestdefoe-picks.public_stats.' . ($currentWeekId ?? 0),
            self::CACHE_TTL,
            fn () => $this->buildPayload($currentWeek)
        );

        return new JsonResponse($payload);
    }

    /**
     * Compute the full public-stats payload. Pure reads; safe to cache.
     *
     * @param  \Resofire\Picks\Week|null  $currentWeek
     */
    private function buildPayload($currentWeek): array
    {
        $currentWeekId   = $currentWeek?->id;
        $currentWeekName = $currentWeek?->name;
        $currentWeekNum  = $currentWeek?->week_number;

        // ── Participation ─────────────────────────────────────────────────────
        // Total eligible players = all registered users (including admins).
        $totalPlayers = User::query()->count();

        $uniquePickersThisWeek = $currentWeekId
            ? Pick::whereHas('event', fn ($q) => $q->where('week_id', $currentWeekId))
                ->distinct('user_id')->count('user_id')
            : 0;

        $participationRate = ($totalPlayers > 0 && $currentWeekId)
            ? round($uniquePickersThisWeek / $totalPlayers * 100, 1)
            : null;

        // ── Accuracy ─────────────────────────────────────────────────────────
        $scoredPicks = Pick::whereNotNull('is_correct');
        $avgAccuracyAllTime = null;

        // Count once and reuse — the builder was previously executed twice.
        $total = $scoredPicks->count();
        if ($total > 0) {
            $correct = (clone $scoredPicks)->where('is_correct', true)->count();
            $avgAccuracyAllTime = round($correct / $total * 100, 1);
        }

        $avgAccuracyThisWeek = null;
        if ($currentWeekId) {
            $weekScored  = Pick::whereNotNull('is_correct')
                ->whereHas('event', fn ($q) => $q->where('week_id', $currentWeekId));
            $weekTotal   = $weekScored->count();
            $weekCorrect = (clone $weekScored)->where('is_correct', true)->count();

            if ($weekTotal > 0) {
                $avgAccuracyThisWeek = round($weekCorrect / $weekTotal * 100, 1);
            }
        }

        // ── Season leader ─────────────────────────────────────────────────────
        // Top user by total_points in the all-time scope.
        $seasonLeader = null;
        $topScore = UserScore::whereNull('week_id')
            ->whereNull('season_id')
            ->where('total_picks', '>', 0)
            ->orderByDesc('total_points')
            ->with('user')
            ->first();

        if ($topScore && $topScore->user) {
            $seasonLeader = [
                'display_name' => $topScore->user->display_name ?? $topScore->user->username,
                'avatar_url'   => $topScore->user->avatarUrl,
                'total_points' => $topScore->total_points,
                'accuracy'     => $topScore->accuracy,
            ];
        }

        // ── Most picked team (all time) ───────────────────────────────────────
        $mostPickedTeam = null;
        [$topTeamId, $topTeamCnt] = $this->pickStats->mostPickedTeam();

        if ($topTeamId) {
            $team = Team::find($topTeamId);
            if ($team) {
                $mostPickedTeam = [
                    'name'         => $team->name,
                    'abbreviation' => $team->abbreviation,
                    /*
                     * 🚨 Through the MODEL, never by gluing the forum URL onto
                     * the stored path — `logo_path` is absolute for every team
                     * synced from ESPN. See ListEventsController.
                     */
                    'logo_url'     => $team->logo_url,
                    'logo_dark_url' => $team->logo_dark_url,
                    'picks'        => $topTeamCnt,
                ];
            }
        }

        // ── Most picked games this week (top 5 by pick volume) ───────────────
        $mostPickedGames = [];
        if ($currentWeekId) {
            /*
             * 🚨 selectRaw() is passed through VERBATIM — the query builder
             * prefixes only the identifiers it wraps itself, so the join,
             * where and groupBy came out correctly prefixed while this select
             * list did not. On a forum with a table prefix that is "Unknown
             * column 'picks_picks.event_id' in 'field list'" — a 500 on the
             * public stats page. Core interpolates the prefix the same way
             * (Flarum\Post\Post::boot()); no prefix configured returns ''.
             */
            $p = (new Pick())->getConnection()->getTablePrefix();

            $gameCounts = Pick::join('picks_events', 'picks_picks.event_id', '=', 'picks_events.id')
                ->where('picks_events.week_id', $currentWeekId)
                ->groupBy('picks_picks.event_id', 'picks_events.home_team_id', 'picks_events.away_team_id')
                ->selectRaw("{$p}picks_picks.event_id, {$p}picks_events.home_team_id, {$p}picks_events.away_team_id, COUNT(*) as total_picks")
                ->orderByDesc('total_picks')
                ->limit(5)
                ->get();


            // Resolve every referenced team in ONE query.
            $teamIds = $gameCounts
                ->flatMap(fn ($row) => [$row->home_team_id, $row->away_team_id])
                ->filter()
                ->unique()
                ->all();
            $teams = Team::whereIn('id', $teamIds)->get()->keyBy('id');

            $teamPayload = function ($teamId) use ($teams) {
                $team = $teams->get($teamId);
                if (! $team) {
                    return null;
                }

                return [
                    'name'          => $team->name,
                    'abbreviation'  => $team->abbreviation,
                    'logo_url'      => $team->logo_url,
                    'logo_dark_url' => $team->logo_dark_url,
                ];
            };

            foreach ($gameCounts as $row) {
                $mostPickedGames[] = [
                    'event_id'    => $row->event_id,
                    'total_picks' => (int) $row->total_picks,
                    'home_team'   => $teamPayload($row->home_team_id),
                    'away_team'   => $teamPayload($row->away_team_id),
                ];
            }
        }

        // ── Most followed teams (top 10 by fan count on users table) ─────────
        // Defensive — the football_team column is added by the Team extension.
        $mostFollowedTeams = [];
        try {
            // Probing information_schema on every request is heavyweight; the
            // column only changes when the Team extension is installed/removed,
            // so cache the result for an hour.
            $hasColumn = $this->cache->remember(
                'picks.has_football_team_column',
                3600,
                fn () => User::query()->getConnection()->getSchemaBuilder()->hasColumn('users', 'football_team')
            );

            if ($hasColumn) {
                $fanCounts = User::query()
                    ->whereNotNull('football_team')
                    ->where('football_team', '!=', '')
                    ->groupBy('football_team')
                    ->selectRaw('football_team, COUNT(*) as fan_count')
                    ->orderByDesc('fan_count')
                    ->limit(10)
                    ->get();


                // Resolve every referenced team in ONE query (slug OR abbreviation).
                $footballTeams = $fanCounts->pluck('football_team')->filter()->unique()->values()->all();
                $teamRecords = ! empty($footballTeams)
                    ? Team::where(function ($q) use ($footballTeams) {
                        $q->whereIn('slug', $footballTeams)
                          ->orWhereIn('abbreviation', $footballTeams);
                    })->get()
                    : collect();
                $teamsBySlug = $teamRecords->keyBy('slug');
                $teamsByAbbr = $teamRecords->keyBy('abbreviation');

                foreach ($fanCounts as $row) {
                    $team = $teamsBySlug->get($row->football_team)
                        ?? $teamsByAbbr->get($row->football_team);

                    $mostFollowedTeams[] = [
                        'football_team' => $row->football_team,
                        'fan_count'     => (int) $row->fan_count,
                        'name'          => $team?->name ?? $row->football_team,
                        'abbreviation'  => $team?->abbreviation ?? $row->football_team,
                        'logo_url'      => $team?->logo_url,
                        'logo_dark_url' => $team?->logo_dark_url,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Team extension not installed or column missing — return empty.
            $mostFollowedTeams = [];
        }

        return [
            'current_week' => [
                'id'          => $currentWeekId,
                'name'        => $currentWeekName,
                'week_number' => $currentWeekNum,
            ],
            'participation' => [
                'total_players'      => $totalPlayers,
                'pickers_this_week'  => $uniquePickersThisWeek,
                'participation_rate' => $participationRate,
            ],
            'accuracy' => [
                'avg_accuracy_all_time'  => $avgAccuracyAllTime,
                'avg_accuracy_this_week' => $avgAccuracyThisWeek,
            ],
            'season_leader'       => $seasonLeader,
            'most_picked_team'    => $mostPickedTeam,
            'most_picked_games'   => $mostPickedGames,
            'most_followed_teams' => $mostFollowedTeams,
        ];
    }
}
