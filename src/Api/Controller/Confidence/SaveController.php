<?php

namespace Resofire\Picks\Api\Controller\Confidence;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Confidence\ConfidenceContest;
use Resofire\Picks\Week;

/**
 * POST /picks/confidence — save the member's picks for one week.
 *
 * Body: { week_id, picks: [{event_id, selected_outcome, confidence}], tiebreaker }
 * `picks` replaces every UNLOCKED pick; leave `tiebreaker` out to keep it.
 * Every rule is in Confidence\Rules; a refusal comes back as a code.
 */
class SaveController implements RequestHandlerInterface
{
    public function __construct(protected ConfidenceContest $contest)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('picks.makePicks');

        $body = (array) ($request->getParsedBody() ?? []);
        $week = Week::find((int) Arr::get($body, 'week_id'));

        if (! $week) {
            return new JsonResponse(['status' => 'error', 'code' => 'no_week'], 404);
        }

        $picks = Arr::get($body, 'picks', []);

        if (! is_array($picks)) {
            return new JsonResponse(['status' => 'error', 'code' => 'bad_request'], 422);
        }

        $tiebreaker = array_key_exists('tiebreaker', $body) ? $body['tiebreaker'] : false;

        $error = $this->contest->save($week, $actor, array_values(array_filter($picks, 'is_array')), $tiebreaker);

        if ($error !== null) {
            return new JsonResponse(['status' => 'error', 'code' => $error['error'], 'event_id' => $error['event_id']], 422);
        }

        return new JsonResponse(['status' => 'success', 'data' => $this->contest->board($week, $actor)]);
    }
}
