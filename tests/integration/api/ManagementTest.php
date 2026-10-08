<?php

namespace Resofire\Picks\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\Tests\integration\SeedsPicks;

/**
 * Syncing, results, resets and the admin views are for those who manage the
 * game. Members who may view and pick get none of them.
 */
class ManagementTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsPicks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks');
        $this->seedPicks();
    }

    public static function managementRoutes(): array
    {
        return [
            'events' => ['GET', '/api/picks/events'],
            'stats' => ['GET', '/api/picks/stats'],
            'sync status' => ['GET', '/api/picks/sync/scores/status'],
            'sync teams' => ['POST', '/api/picks/sync/teams'],
            'sync logos' => ['POST', '/api/picks/sync/logos'],
            'sync schedule' => ['POST', '/api/picks/sync/schedule'],
            'sync scores' => ['POST', '/api/picks/sync/scores'],
            'sync espn' => ['POST', '/api/picks/sync/espn'],
            'open week' => ['POST', '/api/picks/weeks/2/open'],
            'enter result' => ['POST', '/api/picks/events/1/result'],
            'refresh logo' => ['POST', '/api/picks/teams/1/refresh-logo'],
            'reset' => ['POST', '/api/picks/reset'],
            'seed test data' => ['POST', '/api/picks/seed-test-data'],
            'confidence selection' => ['GET', '/api/picks/confidence/weeks/1'],
        ];
    }

    #[Test]
    #[DataProvider('managementRoutes')]
    public function a_member_is_refused(string $method, string $path)
    {
        $response = $this->call($method, $path, 2, $method === 'GET' ? null : ['scope' => 'all', 'homeScore' => 1, 'awayScore' => 0]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(3, $this->database()->table('picks_events')->count(), 'Nothing reset');
        $this->assertSame(0, (int) $this->database()->table('picks_weeks')->where('id', 2)->value('is_open'));
    }

    #[Test]
    public function a_reset_of_the_schedule_keeps_the_teams()
    {
        $response = $this->call('POST', '/api/picks/reset', 1, ['scope' => 'schedule']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, $this->database()->table('picks_events')->count());
        $this->assertSame(4, $this->database()->table('picks_teams')->count());
    }
}
