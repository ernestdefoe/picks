<?php

namespace Resofire\Picks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\Service\LogoService;
use Resofire\Picks\Service\TeamSyncService;
use Resofire\Picks\Team;
use Resofire\Picks\Tests\integration\SeedsPicks;

/**
 * ESPN numbers teams per league, so the college crest path is for college
 * teams only. Every URL a logo pass would fetch is recorded, nothing is
 * downloaded.
 */
class TeamLogoTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsPicks;

    /** @var list<array{0: ?string, 1: ?string}> */
    public static array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$fetched = [];
        $this->extension('ernestdefoe-picks');

        $at = Carbon::now()->addDay();

        $this->prepareDatabase([
            'picks_seasons' => [
                ['id' => 1, 'name' => '2026', 'slug' => '2026', 'year' => 2026, 'league' => 'cfb'],
                ['id' => 2, 'name' => 'NFL 2026', 'slug' => 'nfl-2026', 'year' => 2026, 'league' => 'nfl'],
            ],
            'picks_weeks' => [
                ['id' => 1, 'season_id' => 1, 'name' => 'Week 1', 'week_number' => 1],
                ['id' => 2, 'season_id' => 2, 'name' => 'Week 1', 'week_number' => 1],
            ],
            'picks_teams' => [
                ['id' => 1, 'name' => 'Auburn', 'slug' => 'auburn', 'espn_id' => 2],
                ['id' => 2, 'name' => 'Alabama', 'slug' => 'alabama', 'espn_id' => 333],
                ['id' => 3, 'name' => 'Buffalo Bills', 'slug' => 'buffalo-bills', 'espn_id' => 4],
                ['id' => 4, 'name' => 'Miami Dolphins', 'slug' => 'miami-dolphins', 'espn_id' => 15],
                ['id' => 5, 'name' => 'Kansas City Chiefs', 'slug' => 'kansas-city-chiefs', 'espn_id' => 12, 'logo_path' => 'https://a.espncdn.com/i/teamlogos/nfl/500/kc.png'],
            ],
            'picks_events' => [
                ['id' => 1, 'week_id' => 1, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $at, 'cutoff_date' => $at, 'status' => 'scheduled'],
                ['id' => 2, 'week_id' => 2, 'home_team_id' => 3, 'away_team_id' => 4, 'match_date' => $at, 'cutoff_date' => $at, 'status' => 'scheduled'],
                ['id' => 3, 'week_id' => 2, 'home_team_id' => 5, 'away_team_id' => 3, 'match_date' => $at, 'cutoff_date' => $at, 'status' => 'scheduled'],
            ],
        ]);

        $this->app()->getContainer()->instance(LogoService::class, new class extends LogoService {
            public function __construct()
            {
            }

            public function downloadFromUrls(?string $logoUrl, ?string $logoDarkUrl, string $slug): array
            {
                TeamLogoTest::$fetched[] = [$logoUrl, $logoDarkUrl];

                // Alabama's download always fails, as a blocked CDN would.
                return ['logo_path' => $slug === 'alabama' ? null : 'assets/picks-logos/'.$slug.'.webp', 'logo_dark_path' => null];
            }
        });
    }

    #[Test]
    public function only_college_teams_are_fetched_from_the_college_path()
    {
        $result = $this->app()->getContainer()->make(TeamSyncService::class)->syncLogos();

        $this->assertSame([
            ['https://a.espncdn.com/i/teamlogos/ncaa/500/2.png', 'https://a.espncdn.com/i/teamlogos/ncaa/500-dark/2.png'],
            ['https://a.espncdn.com/i/teamlogos/ncaa/500/333.png', 'https://a.espncdn.com/i/teamlogos/ncaa/500-dark/333.png'],
        ], self::$fetched);
        // Alabama's download failed; the two NFL teams have nothing to fetch from.
        $this->assertSame(['saved' => 1, 'failed' => 3, 'remaining' => 3], array_intersect_key($result, array_flip(['saved', 'failed', 'remaining'])));
        $this->assertNull(Team::findOrFail(3)->logo_path, 'an NFL team was given a college crest');
    }

    #[Test]
    public function a_team_with_a_crest_url_is_refreshed_from_that_url()
    {
        $service = $this->app()->getContainer()->make(TeamSyncService::class);

        $this->assertTrue($service->refreshLogos(Team::findOrFail(5)));
        $this->assertFalse($service->refreshLogos(Team::findOrFail(4)));
        $this->assertSame([
            ['https://a.espncdn.com/i/teamlogos/nfl/500/kc.png', 'https://a.espncdn.com/i/teamlogos/nfl/500-dark/kc.png'],
        ], self::$fetched);
    }

    #[Test]
    public function the_logo_sync_is_queued_and_reports_one_pass_over_the_missing_crests()
    {
        $response = $this->call('POST', '/api/picks/sync/logos', 1, []);
        $this->assertSame(202, $response->getStatusCode(), (string) $response->getBody());

        // The test queue runs the job at once, so the first poll has the answer.
        $status = $this->body($this->call('GET', '/api/picks/sync/logos/status', 1));
        $this->assertSame('done', $status['status']);
        $this->assertSame(['saved' => 1, 'failed' => 3, 'remaining' => 3], $status['result'], 'the failing teams were tried once, not forever');
        $this->assertCount(2, self::$fetched);
    }

    #[Test]
    public function a_single_logo_refresh_is_queued()
    {
        $this->assertSame(202, $this->call('POST', '/api/picks/teams/5/refresh-logo', 1, [])->getStatusCode());

        $status = $this->body($this->call('GET', '/api/picks/sync/logo-5/status', 1));
        $this->assertSame(['status' => 'done', 'result' => ['saved' => true]], $status);
    }

    #[Test]
    public function a_failed_sync_reports_why()
    {
        // No CollegeFootballData key is set, so the teams sync cannot run.
        $this->assertSame(202, $this->call('POST', '/api/picks/sync/teams', 1, [])->getStatusCode());

        $status = $this->body($this->call('GET', '/api/picks/sync/teams/status', 1));
        $this->assertSame('failed', $status['status']);
        $this->assertNotEmpty($status['result']['message']);
    }

    #[Test]
    public function the_espn_sync_refuses_a_college_season_without_queueing()
    {
        $response = $this->call('POST', '/api/picks/sync/espn', 1, ['season_id' => 1]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('idle', $this->body($this->call('GET', '/api/picks/sync/espn/status', 1))['status']);
    }
}
