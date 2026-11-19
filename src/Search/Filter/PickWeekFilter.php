<?php

namespace Resofire\Picks\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;
use Resofire\Picks\PickEvent;

/**
 * filter[week]=<id>: picks on the games of one week.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class PickWeekFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'week';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $games = PickEvent::query()->select('id')->where('week_id', $this->asInt($value));

        $state->getQuery()->{$negate ? 'whereNotIn' : 'whereIn'}('picks_picks.event_id', $games);
    }
}
