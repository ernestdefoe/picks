<?php

namespace Resofire\Picks\Confidence;

/**
 * How a Confidence contest scores and ranks. A pure rule, tested in
 * tests/run.php.
 *
 * A correct pick earns its value. A wrong pick costs nothing, half its value
 * (rounded down) or all of it, by the same three penalty choices the full
 * board's confidence mode offers. A pick on a game not yet decided, or on a
 * drawn game, counts for nothing either way.
 *
 * 🚨 Not floored at zero. With a penalty on, a bad week IS a negative week, and
 * a season is the sum of its weeks — a floor on each week would make the
 * season table disagree with the weeks it is made of.
 *
 * Ties: the most points, then the closest tiebreaker guess (no guess, or no
 * result yet, ranks behind any guess), then the most correct picks.
 */
final class Scoring
{
    public const PENALTIES = ['none', 'half', 'full'];

    public static function penalty(int $confidence, string $rule): int
    {
        return match ($rule) {
            'full'  => $confidence,
            'half'  => intdiv($confidence, 2),
            default => 0,
        };
    }

    /**
     * @param array<int, array{is_correct:?bool, confidence:int}> $picks
     * @return array{points:int, picks:int, correct:int, accuracy:float}
     */
    public static function total(array $picks, string $penalty): array
    {
        $points  = 0;
        $scored  = 0;
        $correct = 0;

        foreach ($picks as $pick) {
            if ($pick['is_correct'] === null) {
                continue;
            }

            $scored++;
            $value = (int) $pick['confidence'];

            if ($pick['is_correct']) {
                $correct++;
                $points += $value;
            } else {
                $points -= self::penalty($value, $penalty);
            }
        }

        return [
            'points'   => $points,
            'picks'    => $scored,
            'correct'  => $correct,
            'accuracy' => $scored > 0 ? round($correct / $scored * 100, 2) : 0.0,
        ];
    }

    /** How far a guess was from the real total, or null when either is unknown. */
    public static function tiebreakDiff(?int $guess, ?int $homeScore, ?int $awayScore): ?int
    {
        if ($guess === null || $homeScore === null || $awayScore === null) {
            return null;
        }

        return abs($guess - ($homeScore + $awayScore));
    }

    /**
     * Rows in standings order.
     *
     * @template T of array{user_id:int, points:int, correct:int, diff:?int}
     *
     * @param array<int, T> $rows
     * @return array<int, T>
     */
    public static function rank(array $rows): array
    {
        usort($rows, function (array $a, array $b) {
            return [$b['points'], self::diffKey($a['diff']), $b['correct'], $a['user_id']]
                <=> [$a['points'], self::diffKey($b['diff']), $a['correct'], $b['user_id']];
        });

        return $rows;
    }

    /**
     * Every season a member played, as one all-time line.
     *
     * 🚨 A sum of the season rows, not a fresh pass over every pick. A season
     * row is already that season's verdict — scored under the penalty it was
     * played with — so all-time is consistent with the season tables it is
     * made of, the way the full board's all-time is the sum of its seasons.
     * Accuracy is worked out again from the summed counts; an average of
     * percentages would weigh a three-pick season like a full one.
     *
     * The tiebreaker distance is the sum of the seasons that have one, and
     * null when none do, so a member who never guessed ranks behind any guess.
     *
     * @param array<int, array{user_id:int, points:int, picks:int, correct:int, diff:?int}> $seasonRows
     * @return array<int, array{user_id:int, points:int, picks:int, correct:int, accuracy:float, diff:?int}> in standings order
     */
    public static function combine(array $seasonRows): array
    {
        $byUser = [];

        foreach ($seasonRows as $row) {
            $id = (int) $row['user_id'];
            $line = $byUser[$id] ?? ['user_id' => $id, 'points' => 0, 'picks' => 0, 'correct' => 0, 'diff' => null];

            $line['points']  += (int) $row['points'];
            $line['picks']   += (int) $row['picks'];
            $line['correct'] += (int) $row['correct'];

            if ($row['diff'] !== null) {
                $line['diff'] = ($line['diff'] ?? 0) + (int) $row['diff'];
            }

            $byUser[$id] = $line;
        }

        $lines = [];
        foreach ($byUser as $line) {
            if ($line['picks'] === 0) {
                continue;
            }

            $line['accuracy'] = round($line['correct'] / $line['picks'] * 100, 2);
            $lines[] = $line;
        }

        return self::rank($lines);
    }

    private static function diffKey(?int $diff): int
    {
        return $diff ?? PHP_INT_MAX;
    }
}
