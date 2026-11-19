<?php

namespace Resofire\Picks\Api\Controller\Confidence;

use Psr\Http\Message\ServerRequestInterface;
use Resofire\Picks\Week;

/** GET /picks/confidence/weeks/{id}: the chosen games and every other game in the week. */
class ShowSelectionController extends AbstractSelectionController
{
    protected function change(Week $week, ServerRequestInterface $request): ?string
    {
        return null;
    }
}
