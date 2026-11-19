<?php

namespace Resofire\Picks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Service\CurrentSeasonService;
use Resofire\Picks\Service\CurrentWeek;
use Resofire\Picks\Week;

/**
 * The board week is now worked out from one summary row per week instead of
 * every game. Same answer as before, proved here against the old loop over a
 * schedule with every awkward case in it and at every hour of a game week.
 */
class BoardWeekTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks');

        $at = fn (string $t) => Carbon::parse($t, 'UTC');

        $this->prepareDatabase([
            'picks_seasons' => [
                ['id' => 1, 'name' => '2025', 'slug' => '2025', 'year' => 2025],
                ['id' => 2, 'name' => '2026', 'slug' => '2026', 'year' => 2026],
            ],
            'picks_weeks' => [
                ['id' => 1, 'season_id' => 1, 'name' => 'Week 1', 'week_number' => 1, 'is_open' => true],
                ['id' => 2, 'season_id' => 2, 'name' => 'Week 1', 'week_number' => 1, 'is_open' => true],
                ['id' => 3, 'season_id' => 2, 'name' => 'Week 2', 'week_number' => 2, 'is_open' => true],
                ['id' => 4, 'season_id' => 2, 'name' => 'Week 3', 'week_number' => 3, 'is_open' => false],
                ['id' => 5, 'season_id' => 2, 'name' => 'Bowls', 'week_number' => 1, 'season_type' => 'postseason', 'is_open' => false],
                ['id' => 6, 'season_id' => 2, 'name' => 'Empty', 'week_number' => 4, 'is_open' => false],
            ],
            'picks_teams' => [
                ['id' => 1, 'name' => 'A', 'slug' => 'a'],
                ['id' => 2, 'name' => 'B', 'slug' => 'b'],
            ],
            'picks_events' => [
                // Last season, all final.
                ['id' => 1, 'week_id' => 1, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $at('2025-09-06 16:00:00'), 'cutoff_date' => $at('2025-09-06 16:00:00'), 'status' => 'finished'],
                // Week 1: one final, one stuck "closed" that never reported.
                ['id' => 2, 'week_id' => 2, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $at('2026-10-03 16:00:00'), 'cutoff_date' => $at('2026-10-03 16:00:00'), 'status' => 'finished'],
                ['id' => 3, 'week_id' => 2, 'home_team_id' => 2, 'away_team_id' => 1, 'match_date' => $at('2026-10-04 00:30:00'), 'cutoff_date' => $at('2026-10-04 00:30:00'), 'status' => 'closed'],
                // Week 2: scheduled, an unannounced 04:00Z placeholder, one late game.
                ['id' => 4, 'week_id' => 3, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $at('2026-10-10 16:00:00'), 'cutoff_date' => $at('2026-10-10 16:00:00'), 'status' => 'scheduled'],
                ['id' => 5, 'week_id' => 3, 'home_team_id' => 2, 'away_team_id' => 1, 'match_date' => $at('2026-10-10 04:00:00'), 'cutoff_date' => $at('2026-10-10 04:00:00'), 'status' => 'scheduled'],
                ['id' => 6, 'week_id' => 3, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $at('2026-10-11 02:00:00'), 'cutoff_date' => $at('2026-10-11 02:00:00'), 'status' => 'in_progress'],
                // Week 3 and the bowls.
                ['id' => 7, 'week_id' => 4, 'home_team_id' => 2, 'away_team_id' => 1, 'match_date' => $at('2026-10-17 16:00:00'), 'cutoff_date' => $at('2026-10-17 16:00:00'), 'status' => 'scheduled'],
                ['id' => 8, 'week_id' => 5, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $at('2026-12-20 20:00:00'), 'cutoff_date' => $at('2026-12-20 20:00:00'), 'status' => 'scheduled'],
            ],
        ]);
    }

    #[Test]
    public function the_board_week_matches_the_old_loop_at_every_hour()
    {
        $service = $this->app()->getContainer()->make(CurrentSeasonService::class);

        $checked = 0;
        for ($now = Carbon::parse('2026-10-01 00:00:00', 'UTC'); $now->lt(Carbon::parse('2026-10-20 00:00:00', 'UTC')); $now->addHours(5)) {
            $this->assertSame($this->oldBoardWeek($now), $service->getBoardWeekId($now), 'at '.$now->toDateTimeString());
            $checked++;
        }

        $this->assertGreaterThan(80, $checked);

        // And with every week closed, which takes the other branch.
        Week::query()->update(['is_open' => false]);
        foreach (['2026-10-04 12:00:00', '2026-10-12 12:00:00', '2026-12-21 12:00:00', '2027-01-30 12:00:00'] as $t) {
            $now = Carbon::parse($t, 'UTC');
            $this->assertSame($this->oldBoardWeek($now), $service->getBoardWeekId($now), 'closed weeks at '.$t);
        }
    }

    /** The loop over every game that getBoardWeekId() replaced, verbatim. */
    private function oldBoardWeek(Carbon $now): ?int
    {
        $weeks = Week::query()
            ->join('picks_seasons', 'picks_seasons.id', '=', 'picks_weeks.season_id')
            ->orderBy('picks_seasons.year')
            ->orderBy('picks_weeks.season_id')
            ->orderByRaw(Week::seasonTypeOrder('regular'))
            ->orderBy('picks_weeks.week_number')
            ->orderBy('picks_weeks.id')
            ->get(['picks_weeks.id', 'picks_weeks.is_open']);

        $rows = [];
        foreach ($weeks as $week) {
            $rows[(int) $week->id] = ['id' => (int) $week->id, 'is_open' => (bool) $week->is_open, 'unfinished' => [], 'last_kickoff' => null];
        }

        foreach (PickEvent::query()->whereIn('week_id', array_keys($rows))->get(['week_id', 'match_date', 'status']) as $event) {
            $id = (int) $event->week_id;
            $raw = $event->getRawOriginal('match_date');
            $kickoff = $raw === null || $raw === '' ? null : Carbon::parse((string) $raw, 'UTC')->utc()->format('Y-m-d H:i:s');

            if ($event->status !== PickEvent::STATUS_FINISHED) {
                $rows[$id]['unfinished'][] = $kickoff;
            }

            if ($kickoff !== null && ($rows[$id]['last_kickoff'] === null || $kickoff > $rows[$id]['last_kickoff'])) {
                $rows[$id]['last_kickoff'] = $kickoff;
            }
        }

        return CurrentWeek::pick(array_values($rows), $now);
    }
}
