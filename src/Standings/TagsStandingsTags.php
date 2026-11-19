<?php

namespace Resofire\Picks\Standings;

use Flarum\Tags\Tag;

/**
 * Registered only while flarum/tags is enabled (extend.php), so Tag is never
 * named on a forum without it.
 */
class TagsStandingsTags extends StandingsTags
{
    public function relationship(string $slug): ?array
    {
        $id = $slug === '' ? null : Tag::query()->where('slug', $slug)->value('id');

        return $id === null ? null : ['data' => [['type' => 'tags', 'id' => (string) $id]]];
    }

    public function missing(string $slug): bool
    {
        return $slug !== '' && ! Tag::query()->where('slug', $slug)->exists();
    }
}
