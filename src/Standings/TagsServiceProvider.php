<?php

namespace Resofire\Picks\Standings;

use Flarum\Foundation\AbstractServiceProvider;

/** Tags the standings discussion; registered only while flarum/tags is enabled. */
class TagsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(StandingsTags::class, TagsStandingsTags::class);
    }
}
