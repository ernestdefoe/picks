<?php

namespace Resofire\Picks\Search;

use Flarum\Search\AbstractFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchState;
use Resofire\Picks\Team;

/**
 * filter[q]: games where either side's name, slug or abbreviation starts with
 * the words typed. A prefix match, so the team indexes are used.
 *
 * @extends AbstractFulltextFilter<DatabaseSearchState>
 */
class EventFulltext extends AbstractFulltextFilter
{
    public function search(SearchState $state, string $value): void
    {
        $value = trim($value);
        $teams = Team::query()->select('id')->where(fn ($q) => $q
            ->where('name', 'like', $value.'%')
            ->orWhere('slug', 'like', $value.'%')
            ->orWhere('abbreviation', $value));

        $state->getQuery()->where(fn ($q) => $q
            ->whereIn('picks_events.home_team_id', $teams)
            ->orWhereIn('picks_events.away_team_id', clone $teams));
    }
}
