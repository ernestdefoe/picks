<?php

namespace Resofire\Picks\Confidence;

/**
 * A member's Confidence record, shaped for their profile's Picks History tab:
 * an all-time line, then each season they played with its weeks underneath.
 * A pure rule, tested in tests/run.php — the controller gathers the rows.
 *
 * Only seasons and weeks the member actually scored in are listed. The full
 * board lists every week of a season because every week has games on it; a
 * Confidence week the member sat out is not a result of theirs, and a table
 * of zero rows would bury the weeks they did play.
 */
final class Profile
{
    /**
     * @param array<int, array{user_id:int, points:int, picks:int, correct:int, accuracy:float, diff:?int}> $allTime
     *        every member's all-time line, ranked (Scoring::combine)
     * @param array<int, array{id:int, name:string, year:int}> $seasons
     * @param array<int, array{name:string, type:string, number:int}> $weeks  keyed by week id
     * @param array<int, array{scope:string, season_id:int, week_id:?int, points:int, picks:int, correct:int, accuracy:float, diff:?int, rank:?int}> $rows
     *        the member's own score rows
     * @param array<string, int> $players  scope => members with a scored pick in it
     */
    public static function build(
        int $userId,
        array $allTime,
        array $seasons,
        array $weeks,
        array $rows,
        array $players,
        ?int $currentSeasonId,
        ?int $currentWeekId
    ): array {
        $alltime = null;

        foreach ($allTime as $index => $line) {
            if ($line['user_id'] === $userId) {
                $alltime = [
                    'total_points' => $line['points'],
                    'total_picks' => $line['picks'],
                    'correct_picks' => $line['correct'],
                    'accuracy' => (float) $line['accuracy'],
                    'rank' => $index + 1,
                    'total_players' => count($allTime),
                ];
                break;
            }
        }

        $seasonRows = [];
        $weekRows = [];

        foreach ($rows as $row) {
            if ($row['picks'] <= 0) {
                continue;
            }

            if ($row['week_id'] === null) {
                $seasonRows[$row['season_id']] = $row;
            } elseif (isset($weeks[$row['week_id']])) {
                $weekRows[$row['season_id']][] = $row;
            }
        }

        $out = [];

        foreach ($seasons as $season) {
            $id = (int) $season['id'];
            $stats = $seasonRows[$id] ?? null;

            if ($stats === null) {
                continue;
            }

            $played = $weekRows[$id] ?? [];

            // Newest first, the regular season before the postseason — the
            // order the full board's history uses.
            usort($played, function (array $a, array $b) use ($weeks) {
                $wa = $weeks[$a['week_id']];
                $wb = $weeks[$b['week_id']];

                return [$wa['type'] === 'regular' ? 0 : 1, $wb['number']] <=> [$wb['type'] === 'regular' ? 0 : 1, $wa['number']];
            });

            $out[] = [
                'season_id' => $id,
                'name' => $season['name'],
                'year' => (int) $season['year'],
                'is_current' => $id === $currentSeasonId,
                'stats' => self::line($stats, $players),
                'weeks' => array_map(fn (array $row) => [
                    'week_id' => (int) $row['week_id'],
                    'week_name' => $weeks[$row['week_id']]['name'],
                    'is_current' => (int) $row['week_id'] === $currentWeekId,
                ] + self::line($row, $players), $played),
            ];
        }

        return ['alltime' => $alltime, 'seasons' => $out];
    }

    /** One scored row, as the profile draws it. */
    private static function line(array $row, array $players): array
    {
        return [
            'total_points' => (int) $row['points'],
            'total_picks' => (int) $row['picks'],
            'correct_picks' => (int) $row['correct'],
            'accuracy' => (float) $row['accuracy'],
            'tiebreak_diff' => $row['diff'],
            'rank' => $row['rank'],
            'total_players' => (int) ($players[$row['scope']] ?? 0),
        ];
    }
}
