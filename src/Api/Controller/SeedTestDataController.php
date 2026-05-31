<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Foundation\Config;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Service\TestDataSeeder;

/**
 * POST /picks/seed-test-data
 *
 * Body: { "action": "seed2026" | "seedFake2025" | "cleanFake" | "wipeAll" }
 *
 * Thin dispatcher for the admin Testing tab — the seeding engine lives in
 * TestDataSeeder. Admin-only AND gated behind debug mode, so this dev-only
 * test-data tooling isn't a permanent, attackable admin API on production
 * marketplace installs.
 */
class SeedTestDataController implements RequestHandlerInterface
{
    public function __construct(
        protected TestDataSeeder $seeder,
        protected Config $config
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        // Dev-only surface: behaves as if the route doesn't exist unless the
        // forum is running in debug mode (config.php 'debug' => true).
        if (! $this->config->inDebugMode()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Not found.'], 404);
        }

        $action = Arr::get($request->getParsedBody() ?? [], 'action', '');

        $result = match ($action) {
            'seed2026'     => $this->seeder->seed2026(),
            'seedFake2025' => $this->seeder->seedFake2025(),
            'cleanFake'    => $this->seeder->cleanFake(),
            'wipeAll'      => $this->seeder->wipeAll(),
            default        => null,
        };

        if ($result === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Unknown action.'], 422);
        }

        return new JsonResponse($result);
    }
}
