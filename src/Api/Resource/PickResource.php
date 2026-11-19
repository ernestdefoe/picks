<?php

namespace Resofire\Picks\Api\Resource;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Resofire\Picks\Pick;
use Resofire\Picks\PickEvent;
use Tobyz\JsonApiServer\Context;

/**
 * A member's own pick on one game.
 *
 *   GET    /picks-picks?filter[week]=<id>   your picks on that week's games
 *   POST   /picks-picks                     pick (or change the pick on) a game
 *   PATCH  /picks-picks/{id}                change the outcome or confidence
 *   DELETE /picks-picks/{id}                take the pick back
 *
 * 🚨 Only ever the actor's own: everything here is scoped to them, and a guest
 * has none. Other members' picks are read through the history endpoints,
 * behind picks.viewHistory.
 *
 * @extends AbstractDatabaseResource<Pick>
 */
class PickResource extends AbstractDatabaseResource
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator
    ) {
    }

    public function type(): string
    {
        return 'picks-picks';
    }

    public function model(): string
    {
        return Pick::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        $actor = $context->getActor();

        $query->where('user_id', $actor->isGuest() ? 0 : $actor->id);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()->can('picks.view')->paginate(50, 200),
            Endpoint\Create::make()->authenticated()->can('picks.makePicks'),
            Endpoint\Update::make()->authenticated()->can('picks.makePicks'),
            Endpoint\Delete::make()->authenticated()->can('picks.makePicks'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('eventId')
                ->writableOnCreate()
                ->requiredOnCreate(),

            Schema\Str::make('selectedOutcome')
                ->writable()
                ->requiredOnCreate()
                ->in(['home', 'away']),

            /*
             * Only counts while confidence mode is on; with it off a value sent
             * anyway is dropped, so a stale or forged one cannot touch scoring.
             * Left out of a request, the pick keeps the value it had.
             */
            Schema\Integer::make('confidence')
                ->nullable()
                ->writable()
                ->min(1)
                ->max(10)
                ->set(function (Pick $pick, $value) {
                    $pick->confidence = $this->settings->get('ernestdefoe-picks.confidence_mode') && $value !== null ? (int) $value : null;
                }),

            Schema\Boolean::make('isCorrect')
                ->nullable(),
        ];
    }

    /**
     * 🚨 A pick on a game the actor already picked CHANGES that pick. A double
     * click or a page left open across a pick would otherwise hit the unique
     * (user, game) key and answer a 500.
     */
    public function newModel(Context $context): object
    {
        $actor = $context->getActor();
        $eventId = (int) Arr::get($context->body(), 'data.attributes.eventId', 0);

        $existing = $context->creating(self::class) && $eventId > 0
            ? Pick::query()->where('user_id', $actor->id)->where('event_id', $eventId)->first()
            : null;

        $pick = $existing ?? new Pick();
        $pick->user_id = $actor->id;

        return $pick;
    }

    /** @param Pick $model */
    public function saving(object $model, Context $context): ?object
    {
        $this->assertPickable($model, 'game_closed');

        return $model;
    }

    /** @param Pick $model */
    public function deleting(object $model, Context $context): void
    {
        $this->assertPickable($model, 'game_locked_remove');
    }

    /** Picks change only until the game locks. */
    private function assertPickable(Pick $pick, string $closedKey): void
    {
        $event = PickEvent::query()->find($pick->event_id);

        if ($event === null) {
            throw new ValidationException(['eventId' => $this->translator->trans('ernestdefoe-picks.api.errors.game_not_found')]);
        }

        if (! $event->canPick()) {
            throw new ValidationException(['eventId' => $this->translator->trans('ernestdefoe-picks.api.errors.'.$closedKey)]);
        }
    }
}
