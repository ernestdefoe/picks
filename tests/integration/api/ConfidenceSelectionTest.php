<?php

namespace Resofire\Picks\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\Tests\integration\SeedsPicks;

/**
 * The admin's three Confidence selection handlers: show, save, choose again.
 */
class ConfidenceSelectionTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsPicks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks');
        $this->seedPicks();
        $this->setting('ernestdefoe-picks.confidence10_enabled', '1');
        $this->setting('ernestdefoe-picks.confidence10_games', '3');
    }

    private function chosen(): array
    {
        return $this->database()->table('picks_confidence_games')->where('week_id', 1)->orderBy('position')->pluck('event_id')->map(fn ($id) => (int) $id)->all();
    }

    #[Test]
    public function showing_a_week_changes_nothing()
    {
        $response = $this->call('GET', '/api/picks/confidence/weeks/1', 1);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, $this->body($response)['data']['week_id']);
        $this->assertSame([], $this->chosen());
    }

    #[Test]
    public function saving_sets_the_games_in_order()
    {
        $response = $this->call('POST', '/api/picks/confidence/weeks/1', 1, ['event_ids' => [1]]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([1], $this->chosen());
    }

    #[Test]
    public function a_bad_save_is_refused()
    {
        $response = $this->call('POST', '/api/picks/confidence/weeks/1', 1, ['event_ids' => 'all']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('bad_request', $this->body($response)['code']);
    }

    #[Test]
    public function auto_chooses_the_pickable_games()
    {
        $response = $this->call('POST', '/api/picks/confidence/weeks/1/auto', 1, []);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([1], $this->chosen(), 'game 2 is past its cutoff');
    }

    #[Test]
    public function a_missing_week_is_404()
    {
        $this->assertSame(404, $this->call('POST', '/api/picks/confidence/weeks/99/auto', 1, [])->getStatusCode());
    }
}
