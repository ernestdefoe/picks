<?php

namespace Resofire\Picks\Search;

use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Resofire\Picks\PickEvent;

/**
 * Lists games for the picks-events index. Who may list them is the endpoint's
 * `picks.view` check; every game is public to those who may.
 */
class EventSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        // The week is eager-loaded so `canPick` never lazy-loads it per row.
        return PickEvent::query()->with('week')->select('picks_events.*');
    }
}
