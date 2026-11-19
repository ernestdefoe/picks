<?php

namespace Resofire\Picks\Api\Controller\Confidence;

use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Resofire\Picks\Week;

/** POST /picks/confidence/weeks/{id}: { event_ids: [...] }, the new games in order. */
class SaveSelectionController extends AbstractSelectionController
{
    protected function change(Week $week, ServerRequestInterface $request): ?string
    {
        $ids = Arr::get((array) $request->getParsedBody(), 'event_ids', []);

        return is_array($ids) ? $this->contest->setSelection($week, $ids) : 'bad_request';
    }
}
