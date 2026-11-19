<?php

namespace Resofire\Picks\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\Service\SyncScoresService;
use Resofire\Picks\Tests\integration\SeedsPicks;

/**
 * Every class that used to look its services up with resolve() now has them
 * injected. Each test runs one of those paths end to end, so a wiring mistake
 * shows up as a failure here, not on a live board.
 */
class InjectedServicesTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsPicks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks');
        $this->seedPicks();
        $this->setting('ernestdefoe-picks.confidence10_enabled', '1');
        $this->setting('ernestdefoe-picks.confidence10_games', '5');
        $this->setting('ernestdefoe-picks.confidence10_penalty', 'half');
    }

    #[Test]
    public function the_forum_payload_carries_the_confidence_contest_settings()
    {
        $response = $this->call('GET', '/api', 2);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $attributes = $this->body($response)['data']['attributes'];
        $this->assertSame(5, $attributes['picksC10Games']);
        $this->assertSame('half', $attributes['picksC10Penalty']);
    }

    #[Test]
    public function the_week_list_marks_the_current_week()
    {
        $response = $this->call('GET', '/api/picks-weeks', 2);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $current = collect($this->body($response)['data'])->mapWithKeys(fn ($w) => [(int) $w['id'] => $w['attributes']['isCurrent']])->all();
        $this->assertSame([1 => true, 2 => false], $current);
    }

    #[Test]
    public function opening_a_week_chooses_its_confidence_games()
    {
        $response = $this->call('POST', '/api/picks/weeks/2/open', 1, ['is_open' => true]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([3], $this->database()->table('picks_confidence_games')->where('week_id', 2)->pluck('event_id')->map(fn ($id) => (int) $id)->all());
    }

    #[Test]
    public function an_auto_unlocked_week_gets_its_confidence_games()
    {
        $this->setting('ernestdefoe-picks.auto_unlock_weeks', '1');
        $this->database()->table('picks_events')->where('week_id', 1)->update(['status' => 'finished', 'match_date' => Carbon::now()->subDays(2)]);

        $opened = $this->app()->getContainer()->make(SyncScoresService::class)->unlockDueWeeks();

        $this->assertSame([2], $opened);
        $this->assertSame([3], $this->database()->table('picks_confidence_games')->where('week_id', 2)->pluck('event_id')->map(fn ($id) => (int) $id)->all());
    }
}
