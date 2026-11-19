<?php

namespace Resofire\Picks\Api\Controller\Confidence;

use Psr\Http\Message\ServerRequestInterface;
use Resofire\Picks\Week;

/** POST /picks/confidence/weeks/{id}/auto: choose the games again by the automatic rule. */
class AutoSelectionController extends AbstractSelectionController
{
    protected function change(Week $week, ServerRequestInterface $request): ?string
    {
        return $this->contest->autoSelect($week);
    }
}
