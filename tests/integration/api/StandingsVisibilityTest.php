<?php

namespace Resofire\Picks\Tests\integration\api;

use Ernestdefoe\PageBuilder\Block\AbstractBlock;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\Block\LeaderboardBlock;
use Resofire\Picks\Tests\integration\SeedsPicks;

/**
 * Whose scores a viewer may read, and which season "this season" means.
 */
class StandingsVisibilityTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsPicks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks');
        $this->seedPicks();
        $this->prepareDatabase([
            'picks_seasons' => [['id' => 2, 'name' => '2025', 'slug' => '2025', 'year' => 2025]],
            'picks_user_scores' => [
                // Member 3 played last season and this one; member 2 only this one.
                ['user_id' => 3, 'season_id' => 2, 'week_id' => null, 'total_points' => 90, 'total_picks' => 100, 'correct_picks' => 90, 'accuracy' => 90],
                ['user_id' => 3, 'season_id' => 1, 'week_id' => null, 'total_points' => 2, 'total_picks' => 4, 'correct_picks' => 2, 'accuracy' => 50],
                ['user_id' => 2, 'season_id' => 1, 'week_id' => null, 'total_points' => 3, 'total_picks' => 4, 'correct_picks' => 3, 'accuracy' => 75],
                ['user_id' => 3, 'season_id' => null, 'week_id' => null, 'total_points' => 92, 'total_picks' => 104, 'correct_picks' => 92, 'accuracy' => 88],
            ],
        ]);
    }

    #[Test]
    public function a_member_reads_their_own_score_cards()
    {
        $response = $this->call('GET', '/api/picks/user-scores', 2, null, ['user_id' => 2]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    #[Test]
    public function another_members_score_cards_are_refused_without_the_history_permission()
    {
        $this->assertSame(403, $this->call('GET', '/api/picks/user-scores', 2, null, ['user_id' => 3])->getStatusCode());
    }

    #[Test]
    public function another_members_score_cards_are_shown_with_the_history_permission()
    {
        $this->prepareDatabase(['group_permission' => [['group_id' => Group::MEMBER_ID, 'permission' => 'picks.viewHistory']]]);

        $this->assertSame(200, $this->call('GET', '/api/picks/user-scores', 2, null, ['user_id' => 3])->getStatusCode());
    }

    #[Test]
    public function the_season_block_lists_this_season_only()
    {
        // Page Builder is optional and not installed here: its two base types, as PHPStan sees them.
        if (! class_exists(AbstractBlock::class)) {
            require_once __DIR__.'/../../phpstan/page-builder.php';
        }

        $block = $this->app()->getContainer()->make(LeaderboardBlock::class);
        $rows = $block->resolve(['scope' => 'season', 'limit' => 10], User::findOrFail(2))['rows'];

        // Season 1 has an unfinished game, so it is the current one: each member once, ranked on it.
        $this->assertSame([2, 3], array_map(fn ($r) => (int) $r['id'], $rows));
    }
}
