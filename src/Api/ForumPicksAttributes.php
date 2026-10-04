<?php

namespace Resofire\Picks\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Invokable fields class for Extend\ApiResource(ForumResource::class)->fields().
 *
 * Serializes actor-aware permission flags to forum JS.
 * Read in JS as app.forum.attribute('picksCanView') etc.
 */
class ForumPicksAttributes
{
    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

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
                    (bool) $this->settings->get('ernestdefoe-picks.confidence_mode', false)
                ),

            Schema\Str::make('picksConfidencePenalty')
                ->get(fn (object $model, Context $context) =>
                    $this->settings->get('ernestdefoe-picks.confidence_penalty', 'none')
                ),

            // The Confidence contest, beside the full board.
            Schema\Boolean::make('picksC10Enabled')
                ->get(fn (object $model, Context $context) =>
                    (bool) $this->settings->get('ernestdefoe-picks.confidence10_enabled', false)
                ),

            Schema\Integer::make('picksC10Games')
                ->get(fn (object $model, Context $context) =>
                    resolve(\Resofire\Picks\Confidence\ConfidenceContest::class)->size()
                ),

            Schema\Str::make('picksC10Penalty')
                ->get(fn (object $model, Context $context) =>
                    resolve(\Resofire\Picks\Confidence\ConfidenceContest::class)->penalty()
                ),

            // ── New: whether the current actor can view other members' pick history ──
            Schema\Boolean::make('picksCanViewHistory')
                ->get(fn (object $model, Context $context) =>
                    $context->getActor()->hasPermission('picks.viewHistory')
                ),
        ];
    }
}
