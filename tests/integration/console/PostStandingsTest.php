<?php

namespace Resofire\Picks\Tests\integration\console;

use Carbon\Carbon;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The weekly standings discussion: the right week, its own thread, and a
 * discussion core and Tags treat like any other.
 */
class PostStandingsTest extends ConsoleTestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-picks');

        $last = Carbon::now()->subYear();
        $now = Carbon::now()->subDays(3);

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'tags' => [['id' => 1, 'name' => 'Pick\'em', 'slug' => 'pickem', 'position' => 0]],
            'picks_seasons' => [
                ['id' => 1, 'name' => '2025', 'slug' => '2025', 'year' => 2025],
                ['id' => 2, 'name' => '2026', 'slug' => '2026', 'year' => 2026],
            ],
            'picks_weeks' => [
                ['id' => 1, 'season_id' => 1, 'name' => 'Week 15', 'week_number' => 15],
                ['id' => 2, 'season_id' => 2, 'name' => 'Week 1', 'week_number' => 1],
                ['id' => 3, 'season_id' => 1, 'name' => 'Week 1', 'week_number' => 1],
            ],
            'picks_teams' => [
                ['id' => 1, 'name' => 'A', 'slug' => 'a'],
                ['id' => 2, 'name' => 'B', 'slug' => 'b'],
            ],
            'picks_events' => [
                ['id' => 1, 'week_id' => 1, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $last, 'cutoff_date' => $last, 'status' => 'finished', 'result' => 'home'],
                ['id' => 2, 'week_id' => 2, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $now, 'cutoff_date' => $now, 'status' => 'finished', 'result' => 'home'],
                ['id' => 3, 'week_id' => 3, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $last, 'cutoff_date' => $last, 'status' => 'finished', 'result' => 'away'],
            ],
            'picks_picks' => [
                ['user_id' => 2, 'event_id' => 1, 'selected_outcome' => 'home', 'is_correct' => true],
                ['user_id' => 2, 'event_id' => 2, 'selected_outcome' => 'home', 'is_correct' => true],
                ['user_id' => 2, 'event_id' => 3, 'selected_outcome' => 'home', 'is_correct' => false],
            ],
            'picks_user_scores' => [
                ['user_id' => 2, 'season_id' => 2, 'week_id' => null, 'total_points' => 1, 'total_picks' => 1, 'correct_picks' => 1, 'accuracy' => 100],
                ['user_id' => 2, 'season_id' => 2, 'week_id' => 2, 'total_points' => 1, 'total_picks' => 1, 'correct_picks' => 1, 'accuracy' => 100],
            ],
            // A member's own thread that happens to carry the same title.
            'discussions' => [['id' => 50, 'title' => "Week 1 pick'em standings", 'slug' => 'week-1-pickem-standings', 'user_id' => 2, 'created_at' => $now, 'first_post_id' => 50, 'comment_count' => 1]],
            'posts' => [['id' => 50, 'discussion_id' => 50, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Mine</p></t>', 'created_at' => $now, 'number' => 1]],
        ]);
    }

    private function standingsThreads()
    {
        // By author: the console test translator has no locale loaded, so the title is the key.
        return $this->database()->table('discussions')->where('user_id', 1)->get();
    }

    #[Test]
    public function this_seasons_latest_week_is_published_as_a_new_tagged_discussion()
    {
        $output = $this->runCommand(['command' => 'picks:post-standings', '--tag' => 'pickem']);

        $threads = $this->standingsThreads();
        $this->assertCount(1, $threads, $output);
        $id = (int) $threads[0]->id;

        $this->assertSame($id, (int) $this->database()->table('picks_weeks')->where('id', 2)->value('standings_discussion_id'), 'the week remembers its thread');
        $this->assertSame([1], $this->database()->table('discussion_tag')->where('discussion_id', $id)->pluck('tag_id')->map(fn ($t) => (int) $t)->all());
        $this->assertSame(1, (int) $this->database()->table('tags')->where('id', 1)->value('discussion_count'), 'Tags counted it, so its listeners ran');
        $this->assertSame('<t><p>Mine</p></t>', $this->database()->table('posts')->where('id', 50)->value('content'), "a member's thread was edited");
    }

    #[Test]
    public function a_rerun_edits_the_same_discussion()
    {
        $this->runCommand(['command' => 'picks:post-standings']);
        $first = $this->standingsThreads();

        $this->database()->table('picks_user_scores')->whereNull('week_id')->update(['total_picks' => 2, 'accuracy' => 50]);
        $this->runCommand(['command' => 'picks:post-standings']);

        $after = $this->standingsThreads();
        $this->assertCount(1, $after);
        $this->assertSame((int) $first[0]->id, (int) $after[0]->id);
        $this->assertNotNull($this->database()->table('posts')->where('id', $after[0]->first_post_id)->value('edited_at'));
    }
}
