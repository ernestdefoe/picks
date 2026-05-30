<?php

namespace Resofire\Picks\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;

/**
 * Invokable fields class for Extend\ApiResource(ForumResource::class)->fields().
 *
 * Serializes actor-aware permission flags to forum JS.
 * Read in JS as app.forum.attribute('picksCanView') etc.
 */
class ForumPicksAttributes
{
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('picksCanView')
                ->get(fn (object $model, Context $context) =>
                    $context->getActor()->hasPermission('picks.view')
                ),

            Schema\Boolean::make('picksCanMakePicks')
                ->get(fn (object $model, Context $context) =>
                    $context->getActor()->hasPermission('picks.makePicks')
                ),

            Schema\Boolean::make('picksCanManage')
                ->get(fn (object $model, Context $context) =>
                    $context->getActor()->hasPermission('picks.manage')
                ),

            Schema\Boolean::make('picksConfidenceMode')
                ->get(fn (object $model, Context $context) =>
                    (bool) app('flarum.settings')->get('ernestdefoe-picks.confidence_mode', false)
                ),

            Schema\Str::make('picksConfidencePenalty')
                ->get(fn (object $model, Context $context) =>
                    app('flarum.settings')->get('ernestdefoe-picks.confidence_penalty', 'none')
                ),

            // ── New: whether the current actor can view other members' pick history ──
            Schema\Boolean::make('picksCanViewHistory')
                ->get(fn (object $model, Context $context) =>
                    $context->getActor()->hasPermission('picks.viewHistory')
                ),
        ];
    }
}
