<?php

namespace Resofire\Picks\Api\Controller\Confidence;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Confidence\ConfidenceContest;
use Resofire\Picks\Confidence\ConfidenceScore;
use Resofire\Picks\Season;
use Resofire\Picks\Service\CurrentSeasonService;

/**
 * GET /picks/confidence/history — final Confidence standings of every past
 * season, in the same shape as /picks/leaderboard-history. The season being
 * played is left out, as it is there: it belongs to the live table.
 */
class HistoryController implements RequestHandlerInterface
{
    public function __construct(
        protected ConfidenceContest $contest,
        protected CurrentSeasonService $currentSeason
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('picks.view');

        $currentSeasonId = $this->currentSeason->getCurrentWeek()?->season_id;

        $played = ConfidenceScore::whereNull('week_id')->distinct()->pluck('season_id')->all();

        $seasons = Season::query()
            ->whereIn('id', $played ?: [0])
            ->when($currentSeasonId, fn ($q) => $q->where('id', '!=', $currentSeasonId))
            ->orderByDesc('year')
            ->get();

        $data = [];
        foreach ($seasons as $season) {
            $data[] = [
                'season_id' => (int) $season->id,
                'name' => $season->name,
                'year' => (int) $season->year,
                'standings' => $this->contest->standings('s'.$season->id, $actor, 50),
            ];
        }

        return new JsonResponse(['seasons' => $data]);
    }
}
