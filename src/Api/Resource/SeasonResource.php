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
            /*
             * 🚨 `can('picks.view')`, not `authenticated()`.
             *
             * The permission is registered with `allowGuest: true` and offered
             * in the admin as grantable to guests — and `authenticated()` made
             * that impossible, so a board that ticked the box still showed
             * visitors an empty schedule and a "you do not have permission"
             * toast. fbsfb.com had exactly that, having been a public pick'em
             * before it moved.
             *
             * It was broken in the other direction too: any signed-in member
             * could read all of this whether or not they had `picks.view`, so
             * the permission neither let anybody in nor kept anybody out.
             */
            Endpoint\Index::make()->can('picks.view'),
            Endpoint\Show::make()->can('picks.view'),
            /*
             * 🚨 Creatable, or multi-sport is unreachable. The college schedule
             * sync makes its own season row and nothing else ever did, so every
             * league but that one could be stored, read, synced and scored —
             * and had no way for anybody to bring a season into existence. The
             * whole feature was one missing endpoint from being ornamental.
             */
            Endpoint\Create::make()->authenticated()->can('picks.manage'),
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

            /*
             * 🚨 Writable, because a season for a league nobody syncs from a
             * calendar has to get its year from somewhere. The college sync
             * derives it; every other league is created by hand.
             */
            Schema\Integer::make('year')
                ->writable()
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
