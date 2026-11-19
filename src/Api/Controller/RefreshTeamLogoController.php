<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Sync\AdminSync;
use Resofire\Picks\Team;

/**
 * POST /picks/teams/{id}/refresh-logo: queue a fresh download of one team's
 * crest; the admin polls /picks/sync/logo-{id}/status for the result.
 */
class RefreshTeamLogoController implements RequestHandlerInterface
{
    public function __construct(
        protected AdminSync $sync,
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        $id = (int) Arr::get($request->getAttribute('routeParameters'), 'id', 0);
        $team = Team::findOrFail($id);

        if ($team->logo_custom) {
            return new JsonResponse([
                'status' => 'skipped',
                'message' => $this->translator->trans('ernestdefoe-picks.api.errors.custom_logo'),
            ]);
        }

        // Nothing to fetch it from: no ESPN id and no crest URL of its own.
        if (! $team->espn_id && ! preg_match('#^https?://#i', (string) $team->logo_path)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $this->translator->trans('ernestdefoe-picks.api.errors.no_espn_id'),
            ], 422);
        }

        // Queued: the crest is fetched from ESPN, off the request.
        return new JsonResponse($this->sync->start('logo-'.$team->id, ['team_id' => (int) $team->id]), 202);
    }
}
