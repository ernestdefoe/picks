<?php

namespace Resofire\Picks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\Confidence\Scoring;
use Resofire\Picks\Tests\integration\SeedsPicks;

/**
 * Scoring a game writes every picker's rows a scope at a time. The totals are
 * checked here against a member-by-member count straight from the picks — the
 * rule the per-member loop applied — with confidence points and a penalty on,
 * and with no repeated-query exemption: the request must not grow per picker.
 */
class BatchScoringTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsPicks;

    private const MEMBERS = [2, 3, 4, 5, 6, 7, 8, 9];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks');
        $this->seedPicks();
        $this->setting('ernestdefoe-picks.confidence_mode', '1');
        $this->setting('ernestdefoe-picks.confidence_penalty', 'half');
        $this->setting('ernestdefoe-picks.confidence10_enabled', '1');
        $this->setting('ernestdefoe-picks.confidence10_penalty', 'full');

        $past = Carbon::now()->subDays(8);
        $users = [];
        $picks = [];
        $c10 = [];
        $entries = [];

        foreach (self::MEMBERS as $i => $id) {
            if ($id > 3) {
                $users[] = ['id' => $id, 'username' => 'player'.$id, 'email' => "p{$id}@machine.local", 'is_email_confirmed' => 1];
            }

            // Last season's game already scored, so the season and all-time scopes differ.
            $picks[] = ['user_id' => $id, 'event_id' => 9, 'selected_outcome' => $i % 2 ? 'home' : 'away', 'is_correct' => $i % 2 === 1, 'confidence' => $i + 1];
            // This week's game 2, scored by the request below (one member skips it).
            if ($id !== 9) {
                $picks[] = ['user_id' => $id, 'event_id' => 2, 'selected_outcome' => $i % 3 ? 'home' : 'away', 'is_correct' => null, 'confidence' => 8 - $i];
            }

            $c10[] = ['user_id' => $id, 'week_id' => 1, 'event_id' => 2, 'selected_outcome' => $i % 2 ? 'home' : 'away', 'confidence' => 1];
            $entries[] = ['user_id' => $id, 'week_id' => 1, 'tiebreaker_total' => 30 + $i * 4];
        }

        $this->prepareDatabase([
            User::class => $users,
            'picks_seasons' => [['id' => 2, 'name' => '2025', 'slug' => '2025', 'year' => 2025]],
            'picks_weeks' => [['id' => 9, 'season_id' => 2, 'name' => 'Week 9', 'week_number' => 9]],
            'picks_events' => [['id' => 9, 'week_id' => 9, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $past, 'cutoff_date' => $past, 'status' => 'finished', 'result' => 'home']],
            'picks_picks' => $picks,
            // A stale row for one member, so both the update and insert paths run.
            'picks_user_scores' => [['user_id' => 2, 'season_id' => 1, 'week_id' => 1, 'total_points' => 99, 'total_picks' => 99, 'correct_picks' => 99, 'accuracy' => 100]],
            'picks_confidence_games' => [['week_id' => 1, 'event_id' => 2, 'position' => 1]],
            'picks_confidence_picks' => $c10,
            'picks_confidence_entries' => $entries,
        ]);
    }

    #[Test]
    public function every_pickers_rows_match_a_count_from_their_picks()
    {
        $response = $this->call('POST', '/api/picks/events/2/result', 1, ['homeScore' => 24, 'awayScore' => 17]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $db = $this->database();
        $scored = $db->table('picks_picks')
            ->join('picks_events', 'picks_events.id', '=', 'picks_picks.event_id')
            ->join('picks_weeks', 'picks_weeks.id', '=', 'picks_events.week_id')
            ->whereNotNull('picks_picks.is_correct')
            ->get(['picks_picks.user_id', 'picks_picks.is_correct', 'picks_picks.confidence', 'picks_events.week_id', 'picks_weeks.season_id']);

        $expect = function (callable $in) use ($scored) {
            $mine = $scored->filter($in);
            $correct = $mine->filter(fn ($p) => (bool) $p->is_correct);
            $earned = $correct->sum(fn ($p) => $p->confidence ?? 1);
            $penalty = $mine->reject(fn ($p) => (bool) $p->is_correct)->sum(fn ($p) => intdiv((int) ($p->confidence ?? 0), 2));

            return [$mine->count(), $correct->count(), max(0, $earned - $penalty), $mine->count() ? round($correct->count() / $mine->count() * 100, 2) : 0.0];
        };

        $pickers = array_values(array_diff(self::MEMBERS, [9]));

        foreach ($pickers as $id) {
            foreach ([[1, 1], [null, 1], [null, null]] as [$week, $season]) {
                $row = $db->table('picks_user_scores')->where('user_id', $id)
                    ->where(fn ($q) => $week === null ? $q->whereNull('week_id') : $q->where('week_id', $week))
                    ->where(fn ($q) => $season === null ? $q->whereNull('season_id') : $q->where('season_id', $season))
                    ->get();

                $this->assertCount(1, $row, "member {$id}, week {$week}, season {$season}: one row");
                $want = $expect(fn ($p) => (int) $p->user_id === $id && ($week === null || (int) $p->week_id === $week) && ($season === null || (int) $p->season_id === $season));
                $this->assertSame($want, [(int) $row[0]->total_picks, (int) $row[0]->correct_picks, (int) $row[0]->total_points, round((float) $row[0]->accuracy, 2)], "member {$id}, week {$week}, season {$season}");
            }
        }

        // The Confidence contest, the same game: points, and the tiebreak against the 41-point final.
        foreach (self::MEMBERS as $i => $id) {
            $picks = $db->table('picks_confidence_picks')->where('user_id', $id)->where('week_id', 1)->get(['is_correct', 'confidence'])
                ->map(fn ($p) => ['is_correct' => $p->is_correct === null ? null : (bool) $p->is_correct, 'confidence' => (int) $p->confidence])->all();
            $total = Scoring::total($picks, 'full');

            foreach (['w1', 's1'] as $scope) {
                $row = $db->table('picks_confidence_scores')->where('user_id', $id)->where('scope', $scope)->first();
                $this->assertNotNull($row, "confidence {$scope} for {$id}");
                $this->assertSame([$total['points'], $total['picks'], $total['correct']], [(int) $row->total_points, (int) $row->total_picks, (int) $row->correct_picks], "confidence {$scope} for {$id}");
                $this->assertSame(Scoring::tiebreakDiff(30 + $i * 4, 24, 17), (int) $row->tiebreak_diff, "tiebreak {$scope} for {$id}");
                $this->assertNotNull($row->current_rank, "rank {$scope} for {$id}");
            }
        }
    }
}
