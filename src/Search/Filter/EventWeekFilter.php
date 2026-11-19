<?php

namespace Resofire\Picks\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * filter[week]=<id>: the games of one week.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class EventWeekFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'week';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $state->getQuery()->where('picks_events.week_id', $negate ? '!=' : '=', $this->asInt($value));
    }
}
