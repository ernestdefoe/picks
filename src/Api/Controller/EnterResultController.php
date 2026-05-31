<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Jobs\ScorePicksJob;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Service\SyncScoresService;

class EnterResultController implements RequestHandlerInterface
{
    public function __construct(
        protected Queue $queue,
        protected SyncScoresService $syncScoresService
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        $id    = (int) Arr::get($request->getAttribute('routeParameters'), 'id', 0);
        $event = PickEvent::findOrFail($id);

        $body      = $request->getParsedBody() ?? [];
        $homeScore = Arr::get($body, 'homeScore');
        $awayScore = Arr::get($body, 'awayScore');

        if ($homeScore === null || $awayScore === null) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => 'homeScore and awayScore are required.',
            ], 422);
        }

        $event->home_score = (int) $homeScore;
        $event->away_score = (int) $awayScore;
        $event->status     = PickEvent::STATUS_FINISHED;
        $event->result     = $event->calculateResult();
        $event->save();

        $this->queue->push(new ScorePicksJob($event->id));

        // Auto-unlock next week via shared service method
        $nextWeekUnlocked = false;
        if ($event->week_id) {
            $nextWeekUnlocked = $this->syncScoresService->maybeUnlockNextWeek($event->week_id);
        }

        return new JsonResponse([
            'status'           => 'success',
            'id'               => $event->id,
            'homeScore'        => $event->home_score,
            'awayScore'        => $event->away_score,
            'result'           => $event->result,
            'gameStatus'       => $event->status,
            'nextWeekUnlocked' => $nextWeekUnlocked,
        ]);
    }
}
