<?php

namespace Resofire\Picks\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;
use Resofire\Picks\PickEvent;

/**
 * filter[status]=scheduled|in_progress|closed|finished. Anything else matches
 * nothing rather than reaching the query.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class EventStatusFilter implements FilterInterface
{
    use ValidateFilterTrait;

    private const STATUSES = ['scheduled', 'in_progress', 'closed', PickEvent::STATUS_FINISHED];

    public function getFilterKey(): string
    {
        return 'status';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $status = $this->asString($value);

        if (! in_array($status, self::STATUSES, true)) {
            $state->getQuery()->whereRaw('1 = 0');

            return;
        }

        $state->getQuery()->where('picks_events.status', $negate ? '!=' : '=', $status);
    }
}
