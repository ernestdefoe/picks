<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Pick;
use Resofire\Picks\PickEvent;

class DeletePickController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('picks.makePicks');

        $eventId = (int) Arr::get($request->getAttribute('routeParameters'), 'id', 0);

        $event = PickEvent::find($eventId);

        if (! $event) {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('ernestdefoe-picks.api.errors.game_not_found')], 404);
        }

        if (! $event->canPick()) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $this->translator->trans('ernestdefoe-picks.api.errors.game_locked_remove'),
            ], 422);
        }

        $pick = Pick::where('user_id', $actor->id)
            ->where('event_id', $eventId)
            ->first();

        if (! $pick) {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('ernestdefoe-picks.api.errors.no_pick')], 404);
        }

        $pick->delete();

        return new JsonResponse(['status' => 'success', 'event_id' => $eventId]);
    }
}
