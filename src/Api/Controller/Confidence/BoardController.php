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

/** GET /picks/confidence?week_id= — a week's Confidence board for the viewer. */
class BoardController implements RequestHandlerInterface
{
    public function __construct(protected ConfidenceContest $contest)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('picks.view');

        $week = Week::find((int) Arr::get($request->getQueryParams(), 'week_id'));

        if (! $week) {
            return new JsonResponse(['status' => 'error', 'code' => 'no_week'], 404);
        }

        return new JsonResponse(['data' => $this->contest->board($week, $actor)]);
    }
}
