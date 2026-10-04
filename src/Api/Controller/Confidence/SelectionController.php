<?php

namespace Resofire\Picks\Api\Controller\Confidence;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Confidence\ConfidenceContest;
use Resofire\Picks\Confidence\ConfidenceGame;
use Resofire\Picks\Confidence\Selector;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Week;

/**
 * The admin's view of a week's Confidence games.
 *
 *   GET  /picks/confidence/weeks/{id}       the chosen games and every other game in the week
 *   POST /picks/confidence/weeks/{id}       { event_ids: [...] } — the new games, in order
 *   POST /picks/confidence/weeks/{id}/auto  choose them again by the automatic rule
 */
class SelectionController implements RequestHandlerInterface
{
    public function __construct(protected ConfidenceContest $contest)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        $week = Week::find((int) Arr::get($request->getAttribute('routeParameters'), 'id', 0));

        if (! $week) {
            return new JsonResponse(['status' => 'error', 'code' => 'no_week'], 404);
        }

        if ($request->getMethod() === 'POST') {
            $auto = str_ends_with(rtrim($request->getUri()->getPath(), '/'), '/auto');

            if ($auto) {
                $error = $this->contest->autoSelect($week);
            } else {
                $ids   = Arr::get((array) $request->getParsedBody(), 'event_ids', []);
                $error = is_array($ids) ? $this->contest->setSelection($week, $ids) : 'bad_request';
            }

            if ($error !== null) {
                return new JsonResponse(['status' => 'error', 'code' => $error, 'data' => $this->view($week)], 422);
            }
        }

        return new JsonResponse(['data' => $this->view($week)]);
    }

    private function view(Week $week): array
    {
        $selection = $this->contest->selection($week->id);
        $chosen    = $selection->pluck('event_id')->map(fn ($id) => (int) $id)->all();

        $events = PickEvent::with(['homeTeam', 'awayTeam', 'week'])->where('week_id', $week->id)->get();
        $order  = array_flip(Selector::order($events->map(fn ($e) => ConfidenceContest::row($e))->all(), Carbon::now('UTC')));

        // Biggest first, then everything that has already started, by kickoff.
        $others = $events
            ->reject(fn (PickEvent $e) => in_array((int) $e->id, $chosen, true))
            ->sortBy(fn (PickEvent $e) => [isset($order[$e->id]) ? 0 : 1, $order[$e->id] ?? 0, $e->match_date?->getTimestamp() ?? 0])
            ->values();

        return [
            'week_id'    => (int) $week->id,
            'week_name'  => $week->name,
            'week_open'  => (bool) $week->is_open,
            'enabled'    => $this->contest->enabled(),
            'size'       => $this->contest->size(),
            'frozen'     => $this->contest->isFrozen($selection),
            'selection'  => $selection->map(fn (ConfidenceGame $g) => ConfidenceContest::gamePayload($g->event) + ['position' => (int) $g->position])->values()->all(),
            'candidates' => $others->map(fn (PickEvent $e) => ConfidenceContest::gamePayload($e))->all(),
        ];
    }
}
