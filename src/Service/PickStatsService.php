<?php

namespace Resofire\Picks\Service;

use Resofire\Picks\Pick;

/**
 * Shared pick-statistics queries used by both the admin StatsController and the
 * public PublicStatsController, so the logic lives in one place.
 */
class PickStatsService
{
    /**
     * The single most-picked team across all events (home + away tallies).
     *
     * @return array{0: int|null, 1: int} [teamId, pickCount] — teamId null when
     *                                     there are no picks yet.
     */
    public function mostPickedTeam(): array
    {
        $homeTop = Pick::query()
            ->join('picks_events', 'picks_picks.event_id', '=', 'picks_events.id')
            ->where('picks_picks.selected_outcome', 'home')
            ->groupBy('picks_events.home_team_id')
            ->selectRaw('picks_events.home_team_id as team_id, COUNT(*) as cnt')
            ->orderByDesc('cnt')->first();

        $awayTop = Pick::query()
            ->join('picks_events', 'picks_picks.event_id', '=', 'picks_events.id')
            ->where('picks_picks.selected_outcome', 'away')
            ->groupBy('picks_events.away_team_id')
            ->selectRaw('picks_events.away_team_id as team_id, COUNT(*) as cnt')
            ->orderByDesc('cnt')->first();

        $topTeamId  = null;
        $topTeamCnt = 0;

        if ($homeTop && $homeTop->cnt > $topTeamCnt) {
            $topTeamId  = (int) $homeTop->team_id;
            $topTeamCnt = (int) $homeTop->cnt;
        }
        if ($awayTop && $awayTop->cnt > $topTeamCnt) {
            $topTeamId  = (int) $awayTop->team_id;
            $topTeamCnt = (int) $awayTop->cnt;
        }

        return [$topTeamId, $topTeamCnt];
    }
}
