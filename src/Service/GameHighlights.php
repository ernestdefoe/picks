<?php

namespace Resofire\Picks\Service;

use Carbon\Carbon;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Service\Leagues\Leagues;
use Resofire\Picks\Service\Providers\EspnProvider;

/**
 * Fetches a finished game's highlight clips onto its fixture.
 *
 * 🚨 Picks fetches; whoever draws the clips only reads them. This service is
 * the one place a summary is asked for on their behalf, and it decides nothing
 * about WHICH games — Game Day calls it for the games that have a thread, so a
 * Saturday of sixty fixtures is not sixty requests for clips nobody will see.
 *
 * 🚨 Clips arrive minutes to hours after the final whistle, so one look is
 * not enough and a look every minute is far too many. A game is looked at
 * again only once RECHECK_MINUTES have passed, never once it has MAX clips,
 * and never more than WINDOW_HOURS after kickoff.
 */
class GameHighlights
{
    public const MAX = 6;

    public const RECHECK_MINUTES = 45;

    /** Kickoff plus a long game plus a day. */
    public const WINDOW_HOURS = 30;

    public function __construct(protected EspnProvider $espn)
    {
    }

    /** Whether this game is still worth asking about. */
    public function due(PickEvent $event, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        if ($event->status !== PickEvent::STATUS_FINISHED || $event->match_date === null) {
            return false;
        }

        if ($event->match_date->copy()->addHours(self::WINDOW_HOURS)->isBefore($now)) {
            return false;
        }

        if (count((array) $event->highlights) >= self::MAX) {
            return false;
        }

        return $event->highlights_checked_at === null
            || $event->highlights_checked_at->copy()->addMinutes(self::RECHECK_MINUTES)->isBefore($now);
    }

    /**
     * Look once, store what is there. True when the stored clips changed.
     *
     * 🚨 An unchanged list is not written back as a change — only the check
     * time moves — so anything watching `highlights` sees one update per new
     * clip, not one per pass.
     */
    public function refresh(PickEvent $event): bool
    {
        $season = $event->week?->season;
        $league = $season !== null && method_exists($season, 'leagueDefinition')
            ? $season->leagueDefinition()
            : (new Leagues())->get(Leagues::DEFAULT);

        // College football matches ESPN by its CFBD id, which IS the ESPN id.
        $id = trim((string) ($event->external_id ?: $event->cfbd_id));

        $clips = $id === '' ? null : $this->espn->highlights($league, $id, self::MAX);

        $event->highlights_checked_at = Carbon::now();

        $changed = $clips !== null && $clips !== [] && $clips !== (array) $event->highlights;

        if ($changed) {
            $event->highlights = $clips;
        }

        $event->save();

        return $changed;
    }
}
