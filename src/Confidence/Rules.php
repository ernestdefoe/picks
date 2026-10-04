<?php

namespace Resofire\Picks\Confidence;

/**
 * What a member may save to a week's Confidence contest. A pure rule, tested
 * in tests/run.php; the controller only gathers the facts and writes the rows.
 *
 * The contest is N games, each picked with a unique value from 1 to N.
 *
 *   - A game LOCKS at its own lock time, like the full board. A pick on a
 *     locked game keeps its winner and its value for good; resending it
 *     unchanged is fine, changing it is refused.
 *   - Unlocked picks can be reshuffled freely, but only among the values the
 *     locked picks do not hold. Values stay unique across the whole week.
 *   - A submission REPLACES the member's unlocked picks: an unlocked game left
 *     out (or sent without a winner) has no pick afterwards. Nothing has to be
 *     complete; a game without a pick simply scores nothing.
 *
 * Errors come back as codes, which the forum translates, never as English.
 */
final class Rules
{
    public const NOT_IN_CONTEST  = 'not_in_contest';
    public const LOCKED          = 'locked';
    public const BAD_OUTCOME     = 'bad_outcome';
    public const OUT_OF_RANGE    = 'out_of_range';
    public const DUPLICATE_VALUE = 'duplicate_value';
    public const DUPLICATE_GAME  = 'duplicate_game';
    public const BAD_TIEBREAKER  = 'bad_tiebreaker';

    /** The highest total a tiebreaker guess may name. */
    public const TIEBREAKER_MAX = 250;

    /**
     * @param int   $n          the contest size: values run 1..$n
     * @param int[] $selection  event ids in the contest
     * @param int[] $locked     event ids that can no longer be picked
     * @param array<int, array{outcome:string, confidence:int}> $existing the member's saved picks, by event id
     * @param array<int, array{event_id:mixed, selected_outcome:mixed, confidence:mixed}> $submitted
     *
     * @return array{error:?string, event_id:?int, write:array<int, array{outcome:string, confidence:int}>}
     *   `write` is the member's full set of UNLOCKED picks after the save.
     */
    public static function validate(int $n, array $selection, array $locked, array $existing, array $submitted): array
    {
        $selection = array_map('intval', $selection);
        $locked    = array_map('intval', $locked);

        $fail = fn (string $code, ?int $eventId = null) => ['error' => $code, 'event_id' => $eventId, 'write' => []];

        // The values the locked picks hold, which nothing else may take.
        $held = [];
        foreach ($existing as $eventId => $pick) {
            if (in_array((int) $eventId, $locked, true)) {
                $held[(int) $pick['confidence']] = (int) $eventId;
            }
        }

        $write = [];
        $seen  = [];

        foreach ($submitted as $row) {
            $eventId = (int) ($row['event_id'] ?? 0);

            if (! in_array($eventId, $selection, true)) {
                return $fail(self::NOT_IN_CONTEST, $eventId);
            }

            if (isset($seen[$eventId])) {
                return $fail(self::DUPLICATE_GAME, $eventId);
            }
            $seen[$eventId] = true;

            $outcome = $row['selected_outcome'] ?? null;
            $value   = $row['confidence'] ?? null;

            if (in_array($eventId, $locked, true)) {
                $mine = $existing[$eventId] ?? null;

                $unchanged = $mine === null
                    ? ($outcome === null || $outcome === '')
                    : ($outcome === $mine['outcome'] && (int) $value === (int) $mine['confidence']);

                if (! $unchanged) {
                    return $fail(self::LOCKED, $eventId);
                }

                continue;
            }

            // No winner chosen: no pick on this game.
            if ($outcome === null || $outcome === '') {
                continue;
            }

            if (! in_array($outcome, ['home', 'away'], true)) {
                return $fail(self::BAD_OUTCOME, $eventId);
            }

            if (! is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > $n) {
                return $fail(self::OUT_OF_RANGE, $eventId);
            }

            $value = (int) $value;

            if (isset($held[$value])) {
                return $fail(self::DUPLICATE_VALUE, $eventId);
            }

            $held[$value] = $eventId;
            $write[$eventId] = ['outcome' => $outcome, 'confidence' => $value];
        }

        return ['error' => null, 'event_id' => null, 'write' => $write];
    }

    /** A tiebreaker guess: a whole number of points, or null to clear it. */
    public static function tiebreaker($value): array
    {
        if ($value === null || $value === '') {
            return ['error' => null, 'value' => null];
        }

        if (! is_numeric($value) || (int) $value != $value || (int) $value < 0 || (int) $value > self::TIEBREAKER_MAX) {
            return ['error' => self::BAD_TIEBREAKER, 'value' => null];
        }

        return ['error' => null, 'value' => (int) $value];
    }
}
