<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Week;

class WeekOpenController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        $weekId = (int) Arr::get($request->getAttribute('routeParameters'), 'id', 0);
        $week   = Week::find($weekId);

        if (! $week) {
            return new JsonResponse(['status' => 'error', 'message' => 'Week not found.'], 404);
        }

        $body   = $request->getParsedBody() ?? [];
        $isOpen = (bool) Arr::get($body, 'is_open', false);

        $week->is_open = $isOpen;
        $week->save();

        return new JsonResponse([
            'status'  => 'success',
            'week_id' => $week->id,
            'is_open' => $week->is_open,
        ]);
    }
}
