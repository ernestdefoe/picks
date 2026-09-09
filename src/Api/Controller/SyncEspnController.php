<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Resofire\Picks\Season;
use Resofire\Picks\Service\EspnSyncService;
use Resofire\Picks\Service\Leagues\Leagues;

/**
 * POST /api/picks/sync/espn — fill one ESPN-backed season now.
 *
 * 🚨 One season, named in the request, rather than "sync everything". The
 * scheduled command syncs every ESPN season because that is what a schedule is
 * for; a button on a screen is pressed by somebody looking at one row and
 * waiting for it, and a button that quietly also refetched five other
 * competitions would take a minute to come back and report a number that was
 * about none of them.
 */
class SyncEspnController implements RequestHandlerInterface
{
    public function __construct(
        protected EspnSyncService $sync,
        protected Leagues $leagues,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        $id = (int) Arr::get((array) $request->getParsedBody(), 'season_id', 0);
        $season = $id > 0 ? Season::query()->find($id) : null;

        if ($season === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'No such season.'], 404);
        }

        $league = $this->leagues->get($season->league);

        /*
         * 🚨 Refused rather than attempted. College football is
         * CollegeFootballData's — it has a recruiting class, a transfer portal
         * and a season-by-season history that ESPN's scoreboard does not carry
         * — and running this against it would overwrite that with a fixture
         * list. Saying so is more use than a sync that appears to work.
         */
        if ($league->provider !== 'espn') {
            return new JsonResponse([
                'status' => 'error',
                'message' => $league->name . ' is not synced from ESPN — use the schedule sync.',
            ], 422);
        }

        try {
            $result = $this->sync->sync($season);
        } catch (\Throwable $e) {
            $this->logger->warning('[picks] ESPN sync failed', ['season' => $season->id, 'exception' => $e]);

            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        return new JsonResponse(['status' => 'ok'] + $result);
    }
}
