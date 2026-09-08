<?php

namespace Resofire\Picks\Service\Providers;

use Resofire\Picks\Service\Leagues\League;

/**
 * Where a league's fixtures and figures come from.
 *
 * 🚨 Every provider answers in ONE shape — CollegeFootballData's, because that
 * is the shape this extension already stores and already has a tested
 * normaliser for. A second storage shape would mean a second normaliser, a
 * second set of fixtures and two recaps that eventually disagree about what a
 * turnover is called.
 *
 * So an adapter's job is translation, not interpretation. It never decides what
 * a statistic means; it decides what this codebase already calls it.
 */
interface Provider
{
    /** Stable key, matching `League::$provider`. */
    public function key(): string;

    /**
     * Whether this provider can answer for a league at all.
     *
     * 🚨 Asked rather than assumed. A league registered by an extension whose
     * provider was then disabled is somebody's install, and a sync that says
     * "nothing to do" is a far better outcome than one that throws every
     * minute for the rest of the season.
     */
    public function supports(League $league): bool;

    /**
     * The fixtures.
     *
     * @param  int|null $week Only for leagues that have weeks; ignored otherwise.
     * @return list<array{
     *     external_id: string,
     *     week: int|null,
     *     season_type: string,
     *     start: string,
     *     home: string,
     *     away: string,
     *     home_score: int|null,
     *     away_score: int|null,
     *     completed: bool,
     *     status: string,
     *     neutral_site: bool
     * }>
     */
    public function games(League $league, int $year, ?int $week = null, string $seasonType = 'regular'): array;

    /**
     * One game's box score, in the house shape.
     *
     * 🚨 Per GAME, not per week, and that is deliberate even though the CFBD
     * endpoints are per week. The caller knows which games are missing a box
     * score; asking it game by game is what lets it stop after twenty rather
     * than re-fetching a full week every hour for the rest of the season.
     * Providers that really are batched cache the week behind this call.
     *
     * @return array{teams: list<array<string, mixed>>, players: list<array<string, mixed>>}|null
     */
    public function boxScore(League $league, string $externalId, int $year, ?int $week = null, string $seasonType = 'regular'): ?array;
}
