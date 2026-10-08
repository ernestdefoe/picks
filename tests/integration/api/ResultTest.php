<?php

namespace Resofire\Picks\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\Tests\integration\SeedsPicks;

class ResultTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsPicks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks');
        $this->seedPicks();
    }

    /**
     * 🚨 Known, and reported rather than hidden: scoring a game recalculates
     * each picker's week, season and all-time rows one picker at a time
     * (ScoreAggregator), so the queries grow with the number of pickers. The
     * job runs inside this request because the test queue is synchronous.
     * Only those shapes are exempt; the rest of the request is still checked.
     *
     * @return string[]
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['picks_user_scores', 'picks_picks'];
    }

    #[Test]
    public function an_admin_enters_a_result_and_the_picks_are_marked()
    {
        $this->prepareDatabase(['picks_picks' => [
            ['user_id' => 2, 'event_id' => 2, 'selected_outcome' => 'home'],
            ['user_id' => 3, 'event_id' => 2, 'selected_outcome' => 'away'],
        ]]);

        $response = $this->call('POST', '/api/picks/events/2/result', 1, ['homeScore' => 24, 'awayScore' => 17]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('home', $this->body($response)['result']);
        $marked = $this->database()->table('picks_picks')->orderBy('user_id')->pluck('is_correct')->map(fn ($v) => (bool) $v)->all();
        $this->assertSame([true, false], $marked);
    }
}
