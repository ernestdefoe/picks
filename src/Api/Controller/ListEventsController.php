<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Team;

class ListEventsController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        $params  = $request->getQueryParams();
        $page    = max(1, (int) Arr::get($params, 'page', 1));
        $perPage = max(1, min(100, (int) Arr::get($params, 'per_page', 50)));
        $search  = trim(Arr::get($params, 'search', ''));
        $weekId  = Arr::get($params, 'week_id', '');
        $status  = Arr::get($params, 'status', '');
        $sort    = Arr::get($params, 'sort', 'date_asc');

        $query = PickEvent::with(['homeTeam', 'awayTeam', 'week']);

        if ($weekId !== '') {
            $query->where('week_id', (int) $weekId);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('homeTeam', fn ($t) => $t->where('name', 'like', "%$search%"))
                  ->orWhereHas('awayTeam', fn ($t) => $t->where('name', 'like', "%$search%"));
            });
        }

        match ($sort) {
            'date_desc' => $query->orderByDesc('match_date'),
            'status'    => $query->orderBy('status')->orderBy('match_date'),
            default     => $query->orderBy('match_date'),
        };

        $total = (clone $query)->count();
        $items = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return new JsonResponse([
            'data' => $items->map(fn (PickEvent $e) => [
                'id'         => $e->id,
                'cfbd_id'    => $e->cfbd_id,
                'week_id'    => $e->week_id,
                'week_name'  => $e->week?->name,
                'status'     => $e->status,
                'match_date' => $e->match_date?->toIso8601String(),
                'cutoff_date'=> $e->cutoff_date?->toIso8601String(),
                'neutral_site' => $e->neutral_site,
                'home_score' => $e->home_score,
                'away_score' => $e->away_score,
                'result'     => $e->result,
                /*
                 * 🚨 On the GAME, beside the score, not folded into the team.
                 * A rank belongs to the week the fixture was played in — see
                 * the lead-in migration — and a board that read it off the club
                 * would relabel every finished game each time the poll moved.
                 * Nesting it under `home_team` would say the opposite, to
                 * whoever reads this payload next.
                 */
                'home_rank'  => (int) $e->home_rank ?: null,
                'away_rank'  => (int) $e->away_rank ?: null,
                'home_team'  => $this->serializeTeam($e->homeTeam),
                'away_team'  => $this->serializeTeam($e->awayTeam),
            ])->values()->toArray(),
            'meta' => [
                'total'        => $total,
                'current_page' => $page,
                'per_page'     => $perPage,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    private function serializeTeam(?Team $team): ?array
    {
        if (! $team) {
            return null;
        }


        return [
            'id'           => $team->id,
            'name'         => $team->name,
            'abbreviation' => $team->abbreviation,
            'conference'   => $team->conference,
            'logo_path'    => $team->logo_path,
/*
             * 🚨 Through the MODEL, never by gluing the forum URL onto the
             * stored path. `logo_path` is not always relative: a team synced
             * from ESPN — every league but college football — stores the
             * provider's own absolute URL, and prefixing that produced
             * `https://forum.example/https://a.espncdn.com/...`, which 404s and
             * renders as a broken crest on every card. `Team::logo_url` has
             * always handled both; four controllers were quietly rebuilding it.
             */
            'logo_url'      => $team->logo_url,
            'logo_dark_url' => $team->logo_dark_url,
        ];
    }
}
