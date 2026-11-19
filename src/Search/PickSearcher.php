<?php

namespace Resofire\Picks\Search;

use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Resofire\Picks\Pick;

/**
 * Lists the actor's OWN picks. A guest has none, and nobody lists anybody
 * else's here: other members' picks are seen through the history endpoints,
 * behind picks.viewHistory.
 */
class PickSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        return Pick::query()->where('picks_picks.user_id', $actor->isGuest() ? 0 : $actor->id)->select('picks_picks.*');
    }
}
