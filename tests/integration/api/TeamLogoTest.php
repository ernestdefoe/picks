<?php

namespace Resofire\Picks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\Service\LogoService;
use Resofire\Picks\Service\TeamSyncService;
use Resofire\Picks\Team;

/**
 * ESPN numbers teams per league, so the college crest path is for college
 * teams only. Every URL a logo pass would fetch is recorded, nothing is
 * downloaded.
 */
class TeamLogoTest extends TestCase
{
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

                return ['logo_path' => 'assets/picks-logos/'.$slug.'.webp', 'logo_dark_path' => null];
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
        $this->assertSame(2, $result['saved']);
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
}
