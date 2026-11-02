<?php

namespace Resofire\Picks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\Tests\integration\SeedsPicks;

class PicksTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsPicks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks');
        $this->seedPicks();
    }

    private function picks(): array
    {
        return $this->database()->table('picks_picks')->orderBy('id')->get(['user_id', 'event_id', 'selected_outcome'])
            ->map(fn ($p) => [(int) $p->user_id, (int) $p->event_id, $p->selected_outcome])->all();
    }

    #[Test]
    public function the_forum_carries_the_nav_label_and_week_view()
    {
        $this->setting('ernestdefoe-picks.nav_label', 'Pick em');

        $forum = $this->body($this->call('GET', '/api'))['data']['attributes'];

        $this->assertSame('Pick em', $forum['picksNavLabel']);
        $this->assertSame('current', $forum['picksDefaultWeekView']);
    }

    #[Test]
    public function a_member_picks_an_open_game_and_may_change_it()
    {
        $response = $this->call('POST', '/api/picks/submit', 2, ['event_id' => 1, 'selected_outcome' => 'home']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->call('POST', '/api/picks/submit', 2, ['event_id' => 1, 'selected_outcome' => 'away']);

        $this->assertSame([[2, 1, 'away']], $this->picks(), 'One pick per game, the latest choice');
    }

    #[Test]
    public function no_pick_after_the_cutoff_or_in_a_locked_week()
    {
        $this->assertSame(422, $this->call('POST', '/api/picks/submit', 2, ['event_id' => 2, 'selected_outcome' => 'home'])->getStatusCode(), 'Past the cutoff');
        $this->assertSame(422, $this->call('POST', '/api/picks/submit', 2, ['event_id' => 3, 'selected_outcome' => 'home'])->getStatusCode(), 'Week not open');
        $this->assertSame(404, $this->call('POST', '/api/picks/submit', 2, ['event_id' => 99, 'selected_outcome' => 'home'])->getStatusCode());
        $this->assertSame(422, $this->call('POST', '/api/picks/submit', 2, ['event_id' => 1, 'selected_outcome' => 'draw'])->getStatusCode());
        $this->assertSame([], $this->picks());
    }

    #[Test]
    public function a_confidence_value_counts_only_in_confidence_mode()
    {
        $this->call('POST', '/api/picks/submit', 2, ['event_id' => 1, 'selected_outcome' => 'home', 'confidence' => 7]);

        $this->assertNull($this->database()->table('picks_picks')->value('confidence'));
    }

    #[Test]
    public function a_member_without_the_permission_cannot_pick()
    {
        $this->database()->table('group_permission')->where('permission', 'picks.makePicks')->delete();

        $this->assertSame(403, $this->call('POST', '/api/picks/submit', 2, ['event_id' => 1, 'selected_outcome' => 'home'])->getStatusCode());
        $this->assertSame([], $this->picks());
    }

    #[Test]
    public function a_guest_cannot_pick()
    {
        $this->assertSame(401, $this->call('POST', '/api/picks/submit', null, ['event_id' => 1, 'selected_outcome' => 'home'])->getStatusCode());
    }

    #[Test]
    public function a_pick_can_be_withdrawn_before_the_cutoff_only()
    {
        $this->prepareDatabase(['picks_picks' => [
            ['user_id' => 2, 'event_id' => 1, 'selected_outcome' => 'home'],
            ['user_id' => 2, 'event_id' => 2, 'selected_outcome' => 'home'],
        ]]);

        $this->assertSame(200, $this->call('DELETE', '/api/picks/events/1/pick', 2)->getStatusCode());
        $this->assertSame(422, $this->call('DELETE', '/api/picks/events/2/pick', 2)->getStatusCode());
        $this->assertSame([[2, 2, 'home']], $this->picks());
    }

    #[Test]
    public function a_member_sees_their_own_picks_and_nobody_elses()
    {
        $this->prepareDatabase(['picks_picks' => [
            ['user_id' => 2, 'event_id' => 1, 'selected_outcome' => 'home'],
            ['user_id' => 3, 'event_id' => 2, 'selected_outcome' => 'away'],
        ]]);

        $response = $this->call('GET', '/api/picks/my-picks', 2, null, ['week_id' => '1']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $games = array_column($this->body($response)['data'], null, 'id');
        $this->assertTrue($games[1]['can_pick']);
        $this->assertFalse($games[2]['can_pick']);
        $this->assertSame('home', $games[1]['my_pick']['selected_outcome']);
        $this->assertNull($games[2]['my_pick']);
    }

    #[Test]
    public function the_board_is_closed_without_the_view_permission()
    {
        $this->database()->table('group_permission')->where('permission', 'picks.view')->delete();

        $this->assertSame(403, $this->call('GET', '/api/picks/my-picks', 2, null, ['week_id' => '1'])->getStatusCode());
        $this->assertSame(403, $this->call('GET', '/api/picks/leaderboard', 2, null, ['scope' => 'alltime'])->getStatusCode());
    }

    #[Test]
    public function the_leaderboard_ranks_by_points_then_correct_picks()
    {
        $this->prepareDatabase(['picks_user_scores' => [
            ['user_id' => 2, 'season_id' => null, 'week_id' => null, 'total_points' => 10, 'total_picks' => 12, 'correct_picks' => 8, 'accuracy' => 66.67],
            ['user_id' => 3, 'season_id' => null, 'week_id' => null, 'total_points' => 10, 'total_picks' => 12, 'correct_picks' => 9, 'accuracy' => 75],
            ['user_id' => 1, 'season_id' => null, 'week_id' => null, 'total_points' => 4, 'total_picks' => 0, 'correct_picks' => 0, 'accuracy' => 0],
        ]]);

        $response = $this->call('GET', '/api/picks/leaderboard', 2, null, ['scope' => 'alltime']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->body($response);
        $this->assertSame([3, 2], array_column($body['data'], 'user_id'), 'Nobody without a pick');
        $this->assertSame(2, $body['meta']['my_rank']);
    }

    #[Test]
    public function a_week_with_nothing_decided_has_no_accuracy_in_the_history()
    {
        // Week 1 half played (one decided pick of one), week 2 not played at
        // all; a stored zero row for it must not read as 0%.
        $this->prepareDatabase(['picks_user_scores' => [
            ['user_id' => 2, 'season_id' => 1, 'week_id' => 1, 'total_points' => 1, 'total_picks' => 1, 'correct_picks' => 1, 'accuracy' => 100],
            ['user_id' => 2, 'season_id' => 1, 'week_id' => 2, 'total_points' => 0, 'total_picks' => 0, 'correct_picks' => 0, 'accuracy' => 0],
        ]]);

        $response = $this->call('GET', '/api/picks/user-history', 2, null, ['user_id' => '2']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $weeks = array_column($this->body($response)['seasons'][0]['weeks'], 'accuracy', 'week_id');
        $this->assertSame([2 => null, 1 => 100], $weeks, 'Decided picks only; nothing decided = no accuracy');
    }

    #[Test]
    public function the_history_counts_the_longest_run_of_correct_picks_in_match_order()
    {
        // By id the run is 2 then 3; by match date it is 5 then 1 (the
        // undecided pick is skipped, not a break). The streak follows dates.
        $events = [];
        $picks = [];
        foreach ([[10, 1, true], [11, 2, true], [12, 6, false], [13, 3, true], [14, 4, true], [15, 5, true], [16, 7, null], [17, 8, true]] as [$id, $day, $correct]) {
            $events[] = ['id' => $id, 'week_id' => 2, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => Carbon::create(2026, 9, $day), 'cutoff_date' => Carbon::create(2026, 9, $day), 'status' => 'finished'];
            $picks[] = ['user_id' => 2, 'event_id' => $id, 'selected_outcome' => 'home', 'is_correct' => $correct];
        }

        $this->prepareDatabase([
            'picks_events' => $events,
            'picks_picks' => $picks,
            'picks_user_scores' => [
                ['user_id' => 2, 'season_id' => null, 'week_id' => null, 'total_points' => 5, 'total_picks' => 7, 'correct_picks' => 5, 'accuracy' => 71],
            ],
        ]);

        $response = $this->call('GET', '/api/picks/user-history', 2, null, ['user_id' => '2']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(5, $this->body($response)['alltime']['longest_streak']);
    }

    #[Test]
    public function saving_settings_with_auto_unlock_on_still_saves()
    {
        $response = $this->call('POST', '/api/settings', 1, ['ernestdefoe-picks.auto_unlock_weeks' => '1']);

        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('1', $this->database()->table('settings')->where('key', 'ernestdefoe-picks.auto_unlock_weeks')->value('value'));
    }

    #[Test]
    public function a_week_costs_the_same_queries_however_many_games_it_holds()
    {
        $week = fn () => $this->call('GET', '/api/picks/my-picks', 2, null, ['week_id' => '1']);

        $week();
        $few = $this->queries($week);

        for ($n = 10; $n <= 15; $n++) {
            $this->database()->table('picks_teams')->insert([
                ['id' => $n * 2, 'name' => "Home $n", 'slug' => "home-$n"],
                ['id' => $n * 2 + 1, 'name' => "Away $n", 'slug' => "away-$n"],
            ]);
            $this->database()->table('picks_events')->insert(['id' => $n, 'week_id' => 1, 'home_team_id' => $n * 2, 'away_team_id' => $n * 2 + 1, 'match_date' => Carbon::now()->addDay(), 'cutoff_date' => Carbon::now()->addDay(), 'status' => 'scheduled']);
            $this->database()->table('picks_picks')->insert(['user_id' => 2, 'event_id' => $n, 'selected_outcome' => 'home']);
        }

        $this->assertSame($few, $this->queries($week));
    }

    private function queries(callable $request): int
    {
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        $response = $request();
        $db->disableQueryLog();
        $this->assertSame(200, $response->getStatusCode());

        return count($db->getQueryLog());
    }
}
