<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Pick;
use Resofire\Picks\Service\CurrentSeasonService;
use Resofire\Picks\Team;
use Resofire\Picks\UserScore;

class PublicStatsController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected CurrentSeasonService $currentSeason
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.view');

        // ── Current week ─────────────────────────────────────────────────────
        // Shared with the other picks endpoints via CurrentSeasonService.
        $currentWeek     = $this->currentSeason->getCurrentWeek();
        $currentWeekId   = $currentWeek?->id;
        $currentWeekName = $currentWeek?->name;
        $currentWeekNum  = $currentWeek?->week_number;

        // ── Participation ─────────────────────────────────────────────────────
        // Total eligible players = all registered users (including admins).
        // No email confirmation filter — admins may bypass confirmation
        // but should still count toward participation totals.
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

        if ($scoredPicks->count() > 0) {
            $correct = (clone $scoredPicks)->where('is_correct', true)->count();
            $total   = $scoredPicks->count();
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
        // Top user by total_points in the all-time scope (week_id = null, season_id = null)
        $seasonLeader     = null;
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

        $homeTop = Pick::join('picks_events', 'picks_picks.event_id', '=', 'picks_events.id')
            ->where('picks_picks.selected_outcome', 'home')
            ->groupBy('picks_events.home_team_id')
            ->selectRaw('picks_events.home_team_id as team_id, COUNT(*) as cnt')
            ->orderByDesc('cnt')
            ->first();

        $awayTop = Pick::join('picks_events', 'picks_picks.event_id', '=', 'picks_events.id')
            ->where('picks_picks.selected_outcome', 'away')
            ->groupBy('picks_events.away_team_id')
            ->selectRaw('picks_events.away_team_id as team_id, COUNT(*) as cnt')
            ->orderByDesc('cnt')
            ->first();

        $topTeamId  = null;
        $topTeamCnt = 0;

        if ($homeTop && $homeTop->cnt > $topTeamCnt) {
            $topTeamId  = $homeTop->team_id;
            $topTeamCnt = $homeTop->cnt;
        }
        if ($awayTop && $awayTop->cnt > $topTeamCnt) {
            $topTeamId  = $awayTop->team_id;
            $topTeamCnt = $awayTop->cnt;
        }

        if ($topTeamId) {
            $team = Team::find($topTeamId);
            if ($team) {
                $baseUrl = rtrim($this->settings->get('url', ''), '/');
                $mostPickedTeam = [
                    'name'         => $team->name,
                    'abbreviation' => $team->abbreviation,
                    'logo_url'     => $team->logo_path
                        ? $baseUrl . '/' . ltrim($team->logo_path, '/')
                        : null,
                    'logo_dark_url' => $team->logo_dark_path
                        ? $baseUrl . '/' . ltrim($team->logo_dark_path, '/')
                        : null,
                    'picks'        => $topTeamCnt,
                ];
            }
        }

        // ── Most picked games this week (top 5 by pick volume) ───────────────
        $mostPickedGames = [];
        if ($currentWeekId) {
            $gameCounts = Pick::join('picks_events', 'picks_picks.event_id', '=', 'picks_events.id')
                ->where('picks_events.week_id', $currentWeekId)
                ->groupBy('picks_picks.event_id', 'picks_events.home_team_id', 'picks_events.away_team_id')
                ->selectRaw('picks_picks.event_id, picks_events.home_team_id, picks_events.away_team_id, COUNT(*) as total_picks')
                ->orderByDesc('total_picks')
                ->limit(5)
                ->get();

            $baseUrl = rtrim($this->settings->get('url', ''), '/');

            foreach ($gameCounts as $row) {
                $homeTeam = Team::find($row->home_team_id);
                $awayTeam = Team::find($row->away_team_id);

                $mostPickedGames[] = [
                    'event_id'    => $row->event_id,
                    'total_picks' => (int) $row->total_picks,
                    'home_team'   => $homeTeam ? [
                        'name'          => $homeTeam->name,
                        'abbreviation'  => $homeTeam->abbreviation,
                        'logo_url'      => $homeTeam->logo_path
                            ? $baseUrl . '/' . ltrim($homeTeam->logo_path, '/')
                            : null,
                        'logo_dark_url' => $homeTeam->logo_dark_path
                            ? $baseUrl . '/' . ltrim($homeTeam->logo_dark_path, '/')
                            : null,
                    ] : null,
                    'away_team'   => $awayTeam ? [
                        'name'          => $awayTeam->name,
                        'abbreviation'  => $awayTeam->abbreviation,
                        'logo_url'      => $awayTeam->logo_path
                            ? $baseUrl . '/' . ltrim($awayTeam->logo_path, '/')
                            : null,
                        'logo_dark_url' => $awayTeam->logo_dark_path
                            ? $baseUrl . '/' . ltrim($awayTeam->logo_dark_path, '/')
                            : null,
                    ] : null,
                ];
            }
        }

        // ── Most followed teams (top 5 by fan count on users table) ──────────
        // Defensive — the football_team column is added by the Team extension.
        // If that extension isn't installed, return an empty array gracefully.
        $mostFollowedTeams = [];
        try {
            $hasColumn = User::query()->getConnection()->getSchemaBuilder()->hasColumn('users', 'football_team');

            if ($hasColumn) {
                $fanCounts = User::query()
                    ->whereNotNull('football_team')
                    ->where('football_team', '!=', '')
                    ->groupBy('football_team')
                    ->selectRaw('football_team, COUNT(*) as fan_count')
                    ->orderByDesc('fan_count')
                    ->limit(10)
                    ->get();

                $baseUrl = rtrim($this->settings->get('url', ''), '/');

                foreach ($fanCounts as $row) {
                    // Look up the team record by slug/abbreviation
                    $team = Team::where('slug', $row->football_team)
                        ->orWhere('abbreviation', $row->football_team)
                        ->first();

                    $mostFollowedTeams[] = [
                        'football_team' => $row->football_team,
                        'fan_count'     => (int) $row->fan_count,
                        'name'          => $team?->name ?? $row->football_team,
                        'abbreviation'  => $team?->abbreviation ?? $row->football_team,
                        'logo_url'      => $team?->logo_path
                            ? $baseUrl . '/' . ltrim($team->logo_path, '/')
                            : null,
                        'logo_dark_url' => $team?->logo_dark_path
                            ? $baseUrl . '/' . ltrim($team->logo_dark_path, '/')
                            : null,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Team extension not installed or column missing — return empty
            $mostFollowedTeams = [];
        }

        return new JsonResponse([
            'current_week' => [
                'id'          => $currentWeekId,
                'name'        => $currentWeekName,
                'week_number' => $currentWeekNum,
            ],
            'participation' => [
                'total_players'        => $totalPlayers,
                'pickers_this_week'    => $uniquePickersThisWeek,
                'participation_rate'   => $participationRate,
            ],
            'accuracy' => [
                'avg_accuracy_all_time'  => $avgAccuracyAllTime,
                'avg_accuracy_this_week' => $avgAccuracyThisWeek,
            ],
            'season_leader'      => $seasonLeader,
            'most_picked_team'   => $mostPickedTeam,
            'most_picked_games'  => $mostPickedGames,
            'most_followed_teams' => $mostFollowedTeams,
        ]);
    }
}
