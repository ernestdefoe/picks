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
use Flarum\Settings\SettingsRepositoryInterface;

class ListPicksController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('picks.view');

        $params = $request->getQueryParams();
        $weekId = Arr::get($params, 'week_id');

        if (! $weekId) {
            return new JsonResponse(['status' => 'error', 'message' => 'week_id is required.'], 422);
        }

        $baseUrl = rtrim($this->settings->get('url', ''), '/');

        // All events for this week with home/away teams
        $events = PickEvent::with(['homeTeam', 'awayTeam', 'week'])
            ->where('week_id', (int) $weekId)
            ->orderBy('match_date')
            ->get();

        // Current user's picks for this week (keyed by event_id)
        $myPicks = [];
        if (! $actor->isGuest()) {
            $picks = Pick::where('user_id', $actor->id)
                ->whereIn('event_id', $events->pluck('id'))
                ->get()
                ->keyBy('event_id');

            foreach ($picks as $eventId => $pick) {
                $myPicks[$eventId] = [
                    'id'               => $pick->id,
                    'selected_outcome' => $pick->selected_outcome,
                    'is_correct'       => $pick->is_correct,
                    'confidence'       => $pick->confidence,
                ];
            }
        }

        $data = $events->map(function (PickEvent $e) use ($myPicks, $baseUrl) {
            $home = $e->homeTeam;
            $away = $e->awayTeam;
            $pick = $myPicks[$e->id] ?? null;

            return [
                'id'          => $e->id,
                'status'      => $e->status,
                'can_pick'    => $e->canPick(),
                'match_date'  => $e->match_date?->toIso8601String(),
                'cutoff_date' => $e->cutoff_date?->toIso8601String(),
                'neutral_site'=> $e->neutral_site,
                'home_score'  => $e->home_score,
                'away_score'  => $e->away_score,
                'result'      => $e->result,
                'home_team'   => $home ? [
                    'id'           => $home->id,
                    'name'         => $home->name,
                    'abbreviation' => $home->abbreviation,
                    'conference'   => $home->conference,
                    'logo_url'     => $home->logo_path ? $baseUrl . '/' . ltrim($home->logo_path, '/') : null,
                    'logo_dark_url'=> $home->logo_dark_path ? $baseUrl . '/' . ltrim($home->logo_dark_path, '/') : null,
                ] : null,
                'away_team'   => $away ? [
                    'id'           => $away->id,
                    'name'         => $away->name,
                    'abbreviation' => $away->abbreviation,
                    'conference'   => $away->conference,
                    'logo_url'     => $away->logo_path ? $baseUrl . '/' . ltrim($away->logo_path, '/') : null,
                    'logo_dark_url'=> $away->logo_dark_path ? $baseUrl . '/' . ltrim($away->logo_dark_path, '/') : null,
                ] : null,
                'my_pick'     => $pick,
            ];
        });

        // Reuse the week already eager-loaded on each event (PickEvent::with([… 'week']))
        // instead of firing a separate SELECT for the same row.
        $weekObj = $events->first()?->week;

        return new JsonResponse([
            'data' => $data->values()->toArray(),
            'meta' => [
                'week_id'    => (int) $weekId,
                'week_open'  => $weekObj ? (bool) $weekObj->is_open : false,
                'total'      => $events->count(),
                'picked'     => count($myPicks),
            ],
        ]);
    }
}
