<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Pick;
use Resofire\Picks\PickEvent;

class DeletePickController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('picks.makePicks');

        $eventId = (int) Arr::get($request->getAttribute('routeParameters'), 'id', 0);

        $event = PickEvent::find($eventId);

        if (! $event) {
            return new JsonResponse(['status' => 'error', 'message' => 'Game not found.'], 404);
        }

        if (! $event->canPick()) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => 'This game is locked — pick cannot be removed.',
            ], 422);
        }

        $pick = Pick::where('user_id', $actor->id)
            ->where('event_id', $eventId)
            ->first();

        if (! $pick) {
            return new JsonResponse(['status' => 'error', 'message' => 'No pick found.'], 404);
        }

        $pick->delete();

        return new JsonResponse(['status' => 'success', 'event_id' => $eventId]);
    }
}
