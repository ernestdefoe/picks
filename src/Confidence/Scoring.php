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
     * @param array<int, array{user_id:int, points:int, correct:int, diff:?int}> $rows
     * @return array<int, array{user_id:int, points:int, correct:int, diff:?int}>
     */
    public static function rank(array $rows): array
    {
        usort($rows, function (array $a, array $b) {
            return [$b['points'], self::diffKey($a['diff']), $b['correct'], $a['user_id']]
                <=> [$a['points'], self::diffKey($b['diff']), $a['correct'], $b['user_id']];
        });

        return $rows;
    }

    private static function diffKey(?int $diff): int
    {
        return $diff ?? PHP_INT_MAX;
    }
}
