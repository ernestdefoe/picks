<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Pick;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Service\PickStatsService;
use Resofire\Picks\Team;

class StatsController implements RequestHandlerInterface
{
    public function __construct(
        protected PickStatsService $pickStats
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        $weekId = Arr::get($request->getQueryParams(), 'week_id');
        $weekId = $weekId !== null ? (int) $weekId : null;

        // Pick counts per event per outcome in ONE aggregated query. The upset
        // and consensus passes read home/away counts from this map instead of
        // firing two COUNT queries per finished/picked event (260+ queries on a
        // full FBS season).
        $pickCountsByEvent = Pick::query()
            ->groupBy('event_id', 'selected_outcome')
            ->selectRaw('event_id, selected_outcome, COUNT(*) as cnt')
            ->get()
            ->groupBy('event_id');

        return new JsonResponse([
            'participation' => $this->participationStats($weekId),
            'accuracy'      => array_merge(
                $this->accuracyStats($weekId),
                [
                    'upset_rate'       => $this->upsetRate($pickCountsByEvent),
                    'most_picked_team' => $this->mostPickedTeam(),
                ]
            ),
            'coverage'      => $this->coverage($pickCountsByEvent),
        ]);
    }

    private function participationStats(?int $weekId): array
    {
        $totalPlayers = Pick::query()->distinct('user_id')->count('user_id');

        $pickedThisWeek = fn () => Pick::query()
            ->whereHas('event', fn ($q) => $q->where('week_id', $weekId));

        $totalGamesThisWeek = $weekId ? PickEvent::query()->where('week_id', $weekId)->count() : 0;
        $picksThisWeek      = $weekId ? $pickedThisWeek()->count() : 0;
        $uniquePickers      = $weekId ? $pickedThisWeek()->distinct('user_id')->count('user_id') : 0;

        return [
            'total_players'              => $totalPlayers,
            'unique_pickers_this_week'   => $uniquePickers,
            'picks_this_week'            => $picksThisWeek,
            'total_games_this_week'      => $totalGamesThisWeek,
            'participation_rate'         => ($totalPlayers > 0 && $weekId)
                ? round($uniquePickers / $totalPlayers * 100, 1) : null,
            'users_not_picked_this_week' => $weekId ? max(0, $totalPlayers - $uniquePickers) : null,
        ];
    }

    private function accuracyStats(?int $weekId): array
    {
        $avgAccuracyAllTime = null;
        $scoredTotal = Pick::query()->whereNotNull('is_correct')->count();
        if ($scoredTotal > 0) {
            $correct = Pick::query()->where('is_correct', true)->count();
            $avgAccuracyAllTime = round($correct / $scoredTotal * 100, 1);
        }

        $avgAccuracyThisWeek = null;
        if ($weekId) {
            $scopedScored = fn () => Pick::query()
                ->whereNotNull('is_correct')
                ->whereHas('event', fn ($q) => $q->where('week_id', $weekId));

            $weekTotal = $scopedScored()->count();
            if ($weekTotal > 0) {
                $weekCorrect = $scopedScored()->where('is_correct', true)->count();
                $avgAccuracyThisWeek = round($weekCorrect / $weekTotal * 100, 1);
            }
        }

        return [
            'avg_accuracy_all_time'  => $avgAccuracyAllTime,
            'avg_accuracy_this_week' => $avgAccuracyThisWeek,
        ];
    }

    /** The team picked most across all events (home + away tallies). */
    private function mostPickedTeam(): ?array
    {
        [$topTeamId, $topTeamCnt] = $this->pickStats->mostPickedTeam();

        if (! $topTeamId) {
            return null;
        }

        $team = Team::find($topTeamId);

        return $team
            ? ['name' => $team->name, 'abbreviation' => $team->abbreviation, 'picks' => $topTeamCnt]
            : null;
    }

    /** % of finished games where the majority picked the loser. */
    private function upsetRate(Collection $pickCountsByEvent): ?float
    {
        $upsets            = 0;
        $finishedWithPicks = 0;

        $finishedEvents = PickEvent::query()
            ->where('status', PickEvent::STATUS_FINISHED)
            ->whereNotNull('result')
            ->get(['id', 'result']);

        foreach ($finishedEvents as $event) {
            [$home, $away] = $this->outcomeCounts($pickCountsByEvent, $event->id);
            if ($home + $away === 0) {
                continue;
            }

            $finishedWithPicks++;
            $majorityPicked = $home >= $away ? 'home' : 'away';
            if ($majorityPicked !== $event->result) {
                $upsets++;
            }
        }

        return $finishedWithPicks > 0 ? round($upsets / $finishedWithPicks * 100, 1) : null;
    }

    /** Game coverage + consensus / most-contested games. */
    private function coverage(Collection $pickCountsByEvent): array
    {
        $consensusCount = 0;
        $contested      = [];

        $eventsWithPicks = PickEvent::query()->has('picks')->get(['id', 'home_team_id', 'away_team_id']);

        foreach ($eventsWithPicks as $event) {
            [$home, $away] = $this->outcomeCounts($pickCountsByEvent, $event->id);
            $total = $home + $away;
            if ($total === 0) {
                continue;
            }

            $homePct = $home / $total;
            if ($homePct === 1.0 || $homePct === 0.0) {
                $consensusCount++;
            }

            $contested[] = [
                'event_id'     => $event->id,
                'home_team_id' => $event->home_team_id,
                'away_team_id' => $event->away_team_id,
                'home_pct'     => round($homePct * 100, 1),
                'away_pct'     => round((1 - $homePct) * 100, 1),
                'total'        => $total,
                'split'        => abs($homePct - 0.5), // closest to 50/50 = most contested
            ];
        }

        // Most contested = closest to a 50/50 split. Resolve team names only for
        // the top 3 we actually return (instead of every picked event), and in a
        // single whereIn batch rather than two Team::find() calls per game.
        usort($contested, fn ($a, $b) => $a['split'] <=> $b['split']);
        $top = array_slice($contested, 0, 3);

        $teamIds = [];
        foreach ($top as $g) {
            $teamIds[] = $g['home_team_id'];
            $teamIds[] = $g['away_team_id'];
        }
        $teams = Team::whereIn('id', array_values(array_unique(array_filter($teamIds))))
            ->get()
            ->keyBy('id');

        $mostContested = array_map(function ($g) use ($teams) {
            return [
                'event_id'  => $g['event_id'],
                'home_team' => $teams->get($g['home_team_id'])?->abbreviation ?? '?',
                'away_team' => $teams->get($g['away_team_id'])?->abbreviation ?? '?',
                'home_pct'  => $g['home_pct'],
                'away_pct'  => $g['away_pct'],
                'total'     => $g['total'],
            ];
        }, $top);

        return [
            'total_finished'  => PickEvent::query()->where('status', PickEvent::STATUS_FINISHED)->count(),
            'total_scheduled' => PickEvent::query()->where('status', PickEvent::STATUS_SCHEDULED)->count(),
            'games_no_picks'  => PickEvent::query()->whereDoesntHave('picks')->count(),
            'consensus_games' => $consensusCount,
            'most_contested'  => $mostContested,
        ];
    }

    /**
     * Home/away pick counts for one event, read from the pre-aggregated map.
     *
     * @return array{0:int,1:int} [home, away]
     */
    private function outcomeCounts(Collection $pickCountsByEvent, int $eventId): array
    {
        $rows = $pickCountsByEvent->get($eventId, collect());

        return [
            (int) (optional($rows->firstWhere('selected_outcome', 'home'))->cnt ?? 0),
            (int) (optional($rows->firstWhere('selected_outcome', 'away'))->cnt ?? 0),
        ];
    }
}
