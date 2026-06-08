<?php

namespace Resofire\Picks\Api\Resource;

use Flarum\Api\Context as FlarumContext;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Resofire\Picks\PickEvent;
use Tobyz\JsonApiServer\Context;

/**
 * @extends AbstractDatabaseResource<PickEvent>
 */
class EventResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'picks-events';
    }

    public function model(): string
    {
        return PickEvent::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        // Eager-load the week so the `canPick` field never lazy-loads it
        // per-row during serialization (an N+1 on the index endpoint).
        $query->with('week')->orderBy('match_date', 'asc');
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->defaultInclude(['homeTeam', 'awayTeam', 'week'])
                ->paginate(),
            Endpoint\Show::make()
                ->authenticated()
                ->defaultInclude(['homeTeam', 'awayTeam', 'week']),
            Endpoint\Update::make()
                ->authenticated()
                ->can('picks.manage'),
            Endpoint\Delete::make()
                ->authenticated()
                ->can('picks.manage'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('weekId')
                ->nullable()
                ->get(fn (PickEvent $e) => $e->week_id),

            Schema\Integer::make('homeTeamId')
                ->get(fn (PickEvent $e) => $e->home_team_id),

            Schema\Integer::make('awayTeamId')
                ->get(fn (PickEvent $e) => $e->away_team_id),

            Schema\Integer::make('cfbdId')
                ->nullable()
                ->get(fn (PickEvent $e) => $e->cfbd_id),

            Schema\Boolean::make('neutralSite')
                ->get(fn (PickEvent $e) => $e->neutral_site),

            Schema\DateTime::make('matchDate')
                ->get(fn (PickEvent $e) => $e->match_date),

            Schema\DateTime::make('cutoffDate')
                ->writable()
                ->get(fn (PickEvent $e) => $e->cutoff_date),

            Schema\Str::make('status')
                ->writable()
                ->get(fn (PickEvent $e) => $e->status),

            Schema\Integer::make('homeScore')
                ->nullable()
                ->writable()
                ->get(fn (PickEvent $e) => $e->home_score)
                ->set(fn (PickEvent $e, $v) => $e->home_score = $v),

            Schema\Integer::make('awayScore')
                ->nullable()
                ->writable()
                ->get(fn (PickEvent $e) => $e->away_score)
                ->set(fn (PickEvent $e, $v) => $e->away_score = $v),

            Schema\Str::make('result')
                ->nullable()
                ->get(fn (PickEvent $e) => $e->result),

            Schema\Boolean::make('canPick')
                ->get(fn (PickEvent $e) => $e->canPick()),

            Schema\Relationship\ToOne::make('week')
                ->includable()
                ->type('picks-weeks'),

            Schema\Relationship\ToOne::make('homeTeam')
                ->includable()
                ->type('picks-teams'),

            Schema\Relationship\ToOne::make('awayTeam')
                ->includable()
                ->type('picks-teams'),
        ];
    }
}
