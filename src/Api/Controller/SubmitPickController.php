<?php

namespace Resofire\Picks\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Pick;
use Resofire\Picks\PickEvent;

class SubmitPickController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('picks.makePicks');

        $body       = $request->getParsedBody() ?? [];
        $eventId    = (int) Arr::get($body, 'event_id');
        $outcome    = Arr::get($body, 'selected_outcome');
        $confidence = Arr::get($body, 'confidence');

        if (! $eventId || ! in_array($outcome, ['home', 'away'], true)) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => 'event_id and selected_outcome (home or away) are required.',
            ], 422);
        }

        // Confidence only applies when confidence mode is enabled. When it's off
        // we ignore any client-supplied value so a stale/forged confidence can't
        // influence scoring; when it's on, the value (if present) is range-checked.
        $confidenceMode = (bool) $this->settings->get('ernestdefoe-picks.confidence_mode', false);

        if (! $confidenceMode) {
            $confidence = null;
        } elseif ($confidence !== null) {
            $confidence = (int) $confidence;
            if ($confidence < 1 || $confidence > 10) {
                return new JsonResponse([
                    'status'  => 'error',
                    'message' => 'Confidence must be between 1 and 10.',
                ], 422);
            }
        }

        $event = PickEvent::find($eventId);

        if (! $event) {
            return new JsonResponse(['status' => 'error', 'message' => 'Game not found.'], 404);
        }

        if (! $event->canPick()) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => 'This game is no longer open for picks.',
            ], 422);
        }

        // Upsert — allow changing pick until cutoff
        $pick = Pick::where('user_id', $actor->id)
            ->where('event_id', $eventId)
            ->first();

        if ($pick) {
            $pick->selected_outcome = $outcome;
            if ($confidence !== null) {
                $pick->confidence = $confidence;
            }
        } else {
            $pick = new Pick();
            $pick->user_id          = $actor->id;
            $pick->event_id         = $eventId;
            $pick->selected_outcome = $outcome;
            $pick->confidence       = $confidence;
        }

        $pick->save();

        return new JsonResponse([
            'status'           => 'success',
            'pick_id'          => $pick->id,
            'event_id'         => $eventId,
            'selected_outcome' => $pick->selected_outcome,
            'confidence'       => $pick->confidence,
        ]);
    }
}
