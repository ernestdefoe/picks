<?php

namespace Resofire\Picks\Standings;

/**
 * The tags a standings discussion is posted into, as a JSON:API relationship.
 * Without flarum/tags there are none: see TagsStandingsTags for the other side.
 */
class StandingsTags
{
    /** @return array{data: list<array{type: string, id: string}>}|null null when there is nothing to tag */
    public function relationship(string $slug): ?array
    {
        return null;
    }

    /** Whether a slug was asked for that is not a tag on this forum. */
    public function missing(string $slug): bool
    {
        return false;
    }
}
