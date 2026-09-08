<?php

namespace Resofire\Picks\Api\Resource;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Resofire\Picks\Season;
use Resofire\Picks\Service\Leagues\Leagues;
use Tobyz\JsonApiServer\Context;

/**
 * @extends AbstractDatabaseResource<Season>
 */
class SeasonResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'picks-seasons';
    }

    public function model(): string
    {
        return Season::class;
    }

    public function scope(Builder $query, Context $context): void
    {
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()->authenticated(),
            Endpoint\Show::make()->authenticated(),
            Endpoint\Update::make()->authenticated()->can('picks.manage'),
            Endpoint\Delete::make()->authenticated()->can('picks.manage'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->writable()
                ->maxLength(100),

            Schema\Str::make('slug')
                ->writable()
                ->maxLength(100),

            Schema\Integer::make('year')
                ->get(fn (Season $s) => $s->year),

            /*
             * 🚨 Validated against the registry on the way in. A season whose
             * league is a typo syncs nothing and explains nothing about why —
             * refusing the value is the one moment anybody is looking at the
             * screen and can fix it.
             */
            Schema\Str::make('league')
                ->writable()
                ->get(fn (Season $s) => (string) ($s->league ?: Leagues::DEFAULT))
                ->set(function (Season $s, $value) {
                    $leagues = new Leagues();

                    $s->league = $leagues->has((string) $value) ? (string) $value : Leagues::DEFAULT;
                }),

            /* What the league means, so a client need not carry its own copy. */
            Schema\Str::make('sport')
                ->get(fn (Season $s) => $s->leagueDefinition()->sport),

            Schema\Str::make('leagueName')
                ->get(fn (Season $s) => $s->leagueDefinition()->name),

            Schema\Str::make('startDate')
                ->nullable()
                ->get(fn (Season $s) => $s->start_date),

            Schema\Str::make('endDate')
                ->nullable()
                ->get(fn (Season $s) => $s->end_date),
        ];
    }
}
