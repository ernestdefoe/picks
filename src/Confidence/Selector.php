<?php

namespace Resofire\Picks\Confidence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Which games make a week's Confidence contest. A pure rule, so it can be
 * tested without Flarum or a database (tests/run.php).
 *
 * The biggest matchups of the week, in this order:
 *
 *   1. both sides ranked, by the two ranks added together (#2 v #7 = 9 comes
 *      before #11 v #24 = 35), then by the better of the two;
 *   2. one side ranked, by that rank;
 *   3. neither ranked, by how good the two sides' records are together.
 *
 * Inside each tier, ties go to the bigger broadcast, then a primetime kickoff,
 * then the earlier game. A game is only a candidate while it can still be
 * picked: scheduled, with its lock still ahead.
 *
 * 🚨 A rank of 0 is UNRANKED. The lead-in columns store 0, not null, for a side
 * outside the poll, and a "lowest rank first" sort that forgot that would put
 * every unranked game at the top as #0.
 *
 * Each game is passed as:
 *   ['id' => int, 'status' => string, 'cutoff' => ?string UTC 'Y-m-d H:i:s',
 *    'match_date' => ?string, 'home_rank' => ?int, 'away_rank' => ?int,
 *    'home_record' => ?string, 'away_record' => ?string, 'broadcast' => ?string,
 *    'time_tbd' => bool]
 */
final class Selector
{
    /** Over-the-air and flagship national windows. */
    private const MAJOR_NETWORKS = ['ABC', 'CBS', 'NBC', 'FOX', 'ESPN'];

    /** National cable. A conference network still reaches the whole country. */
    private const NATIONAL_CABLE = [
        'ESPN2', 'ESPNU', 'FS1', 'TNT', 'TRUTV', 'CBSSN', 'BTN', 'SEC NETWORK', 'SECN',
        'ACC NETWORK', 'ACCN', 'CW', 'USA', 'USA NET', 'NBCSN', 'PEACOCK',
    ];

    /**
     * The chosen game ids, biggest first, at most $count of them.
     *
     * @param array<int, array<string, mixed>> $games
     * @return int[]
     */
    public static function choose(array $games, int $count, DateTimeInterface $now): array
    {
        return array_slice(self::order($games, $now), 0, max(0, $count));
    }

    /**
     * Every pickable game id, biggest first.
     *
     * @param array<int, array<string, mixed>> $games
     * @return int[]
     */
    public static function order(array $games, DateTimeInterface $now): array
    {
        $open = array_values(array_filter($games, fn (array $g) => self::isPickable($g, $now)));

        usort($open, fn (array $a, array $b) => self::key($a) <=> self::key($b));

        return array_map(fn (array $g) => (int) $g['id'], $open);
    }

    public static function isPickable(array $game, DateTimeInterface $now): bool
    {
        if (($game['status'] ?? '') !== 'scheduled') {
            return false;
        }

        $cutoff = $game['cutoff'] ?? null;

        if ($cutoff === null || $cutoff === '') {
            return true;
        }

        return self::ts($cutoff) > $now->getTimestamp();
    }

    /**
     * The sort key: smaller is bigger. An array compares element by element.
     *
     * @return array<int, int|float>
     */
    public static function key(array $game): array
    {
        $home = self::rank($game['home_rank'] ?? null);
        $away = self::rank($game['away_rank'] ?? null);

        $tv        = -self::broadcastWeight((string) ($game['broadcast'] ?? ''));
        $primetime = self::isPrimetime($game) ? -1 : 0;
        $kickoff   = isset($game['match_date']) && $game['match_date'] ? self::ts((string) $game['match_date']) : PHP_INT_MAX;
        $id        = (int) ($game['id'] ?? 0);

        if ($home !== null && $away !== null) {
            return [0, $home + $away, min($home, $away), $tv, $primetime, $kickoff, $id];
        }

        if ($home !== null || $away !== null) {
            $ranked   = $home ?? $away;
            $opponent = $home === null ? ($game['home_record'] ?? null) : ($game['away_record'] ?? null);

            return [1, $ranked, -self::winPct($opponent), $tv, $primetime, $kickoff, $id];
        }

        $strength = self::winPct($game['home_record'] ?? null) + self::winPct($game['away_record'] ?? null);

        // Rounded so a tenth of a percent cannot outrank a national broadcast.
        // 🚨 Padded to the same length as the other tiers: PHP compares arrays
        // by COUNT before contents, so a shorter key sorted every unranked game
        // ahead of #2 v #7.
        return [2, -round($strength, 2), 0, $tv, $primetime, $kickoff, $id];
    }

    /** A poll rank, or null for unranked (0, null, or nonsense). */
    public static function rank($value): ?int
    {
        $rank = (int) $value;

        return $rank >= 1 && $rank <= 50 ? $rank : null;
    }

    /**
     * Wins over games played from a record like "4-1" or "4-1-0"; 0.5 when the
     * record is missing or no games have been played, so an unknown side is
     * neither a giant nor a minnow.
     */
    public static function winPct(?string $record): float
    {
        if ($record === null || ! preg_match('/(\d+)\s*-\s*(\d+)(?:\s*-\s*(\d+))?/', $record, $m)) {
            return 0.5;
        }

        $wins   = (int) $m[1];
        $played = $wins + (int) $m[2] + (int) ($m[3] ?? 0);

        return $played > 0 ? $wins / $played : 0.5;
    }

    public static function broadcastWeight(string $broadcast): int
    {
        $weight = 0;

        foreach (preg_split('/\s*[\/,|&]\s*/', strtoupper(trim($broadcast))) ?: [] as $network) {
            if (in_array($network, self::MAJOR_NETWORKS, true)) {
                $weight = max($weight, 2);
            } elseif (in_array($network, self::NATIONAL_CABLE, true)) {
                $weight = max($weight, 1);
            }
        }

        return $weight;
    }

    /**
     * A kickoff at 7pm Eastern or later. An unannounced time is not primetime:
     * its placeholder is midnight, which would otherwise count.
     */
    public static function isPrimetime(array $game): bool
    {
        if (! empty($game['time_tbd']) || empty($game['match_date'])) {
            return false;
        }

        $local = (new DateTimeImmutable('@' . self::ts((string) $game['match_date'])))
            ->setTimezone(new DateTimeZone('America/New_York'));

        return (int) $local->format('G') >= 19;
    }

    private static function ts(string $utc): int
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
    }
}
