<?php

namespace Resofire\Picks\Service;

use Resofire\Picks\Season;
use Resofire\Picks\Week;

/**
 * Single source of truth for "what week are we currently in".
 *
 * A week is current if it has at least one game not yet finished (status
 * scheduled / in_progress). We take the most recent season that still has an
 * unfinished game, then the earliest unfinished week within it — so future
 * unplayed weeks in a later season never jump ahead of the true current week.
 *
 * Previously this two-step query was copy-pasted as raw Capsule queries into
 * five controllers; centralising it here (in Eloquent) means the active-season
 * definition lives in one place.
 */
class CurrentSeasonService
{
    /** Game statuses that mean "not yet finished". */
    public const UNFINISHED = ['scheduled', 'in_progress'];

    public function getCurrentWeek(): ?Week
    {
        $currentSeason = Season::query()
            ->whereHas('weeks.events', fn ($q) => $q->whereIn('status', self::UNFINISHED))
            ->orderByDesc('year')
            ->first();

        if ($currentSeason === null) {
            return null;
        }

        return Week::query()
            ->where('season_id', $currentSeason->id)
            ->whereHas('events', fn ($q) => $q->whereIn('status', self::UNFINISHED))
            // Regular-season weeks rank ahead of postseason within a season.
            ->orderByRaw("CASE season_type WHEN 'regular' THEN 0 ELSE 1 END")
            ->orderBy('week_number')
            ->first();
    }
}
