<?php

namespace Resofire\Picks\Tests\integration\api;

use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as Cache;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\Service\CfbdPausedException;
use Resofire\Picks\Service\CfbdService;
use RuntimeException;

/**
 * The free CFBD plan is about a thousand calls a month (fbsfb, 2026-10-29: the
 * hourly box-score job ran it out). After a 429, nothing more is asked until
 * the pause is over.
 */
class CfbdPauseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks');
        $this->setting('ernestdefoe-picks.cfbd_api_key', 'test-key');
    }

    private function service(MockHandler $mock): CfbdService
    {
        $c = $this->app()->getContainer();

        return new CfbdService(
            $c->make(SettingsRepositoryInterface::class),
            new Client(['handler' => HandlerStack::create($mock)]),
            $c->make(TranslatorInterface::class),
            $c->make(Cache::class)
        );
    }

    #[Test]
    public function a_429_pauses_every_call_and_honours_retry_after(): void
    {
        $mock = new MockHandler([new Response(429, ['Retry-After' => '120'])]);
        $cfbd = $this->service($mock);

        try {
            $cfbd->fetchTeamBoxScores(2026, 'regular', 9);
            $this->fail('A 429 must not pass as an answer.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(CfbdPausedException::class, $e, 'the refusal itself is a real error, logged once');
        }

        // Paused: the second call never reaches the network (the mock has no answer left).
        $this->expectException(CfbdPausedException::class);
        $cfbd->fetchPlayerBoxScores(2026, 'regular', 9);
    }

    #[Test]
    public function without_retry_after_the_pause_is_hours_not_minutes(): void
    {
        $cfbd = $this->service(new MockHandler([new Response(429)]));

        try {
            $cfbd->fetchTeamBoxScores(2026, 'regular', 9);
        } catch (RuntimeException) {
        }

        $until = (int) $this->app()->getContainer()->make(Cache::class)->get('picks.cfbd.paused_until');
        $this->assertGreaterThan(time() + 5 * 3600, $until);
    }
}
