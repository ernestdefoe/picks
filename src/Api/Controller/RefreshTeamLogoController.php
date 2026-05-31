<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Service\TeamSyncService;
use Resofire\Picks\Team;

class RefreshTeamLogoController implements RequestHandlerInterface
{
    public function __construct(
        protected TeamSyncService $teamSyncService
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        $id   = (int) Arr::get($request->getAttribute('routeParameters'), 'id', 0);
        $team = Team::findOrFail($id);

        if ($team->logo_custom) {
            return new JsonResponse([
                'status'  => 'skipped',
                'message' => 'Team has a custom logo set. Re-download is disabled.',
            ]);
        }

        if (! $team->espn_id) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => 'Team has no ESPN ID. Cannot download logo.',
            ], 422);
        }

        $saved = $this->teamSyncService->refreshLogos($team);

        if ($saved) {
            return new JsonResponse([
                'status'      => 'success',
                'logo_path'   => $team->logo_path,
                'logo_dark_path' => $team->logo_dark_path,
                'logo_url'    => $team->logo_url,
                'logo_dark_url' => $team->logo_dark_url,
            ]);
        }

        return new JsonResponse([
            'status'  => 'error',
            'message' => 'Logo download failed.',
        ], 422);
    }
}
