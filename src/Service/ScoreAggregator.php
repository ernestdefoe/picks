<?php

namespace Resofire\Picks\Service;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Resofire\Picks\Pick;
use Resofire\Picks\UserScore;

/**
 * Single source of truth for computing + upserting members' pick scores.
 *
 * Used by both ScorePicksJob (live scoring on result entry) and the admin
 * test-data seeder, so both honour confidence_mode / confidence_penalty
 * identically — previously the seeder had its own copy that ignored confidence
 * and produced wrong point totals when confidence mode was on.
 */
class ScoreAggregator
{
    /** Lock TTL + max wait (seconds) for the per-scope write serialization. */
    private const LOCK_SECONDS = 10;
    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(private CacheRepository $cache)
    {
    }

    /** Members scored per query; keeps the IN list a size every database takes. */
    private const CHUNK = 500;

    /**
     * Recalculate these members' week, season and all-time scores.
     *
     * @param int[] $userIds
     */
    public function recalculate(
        array $userIds,
        ?int $weekId,
        ?int $seasonId,
        bool $confidenceMode = false,
        string $confidencePenalty = 'none'
    ): void {
        if ($weekId) {
            $this->scoreScope($userIds, $weekId, $seasonId, $confidenceMode, $confidencePenalty);
        }

        if ($seasonId) {
            $this->scoreScope($userIds, null, $seasonId, $confidenceMode, $confidencePenalty);
        }

        $this->scoreScope($userIds, null, null, $confidenceMode, $confidencePenalty);
    }

    /**
     * One member's week, season and all-time scores. Kept for scripts that
     * score members one at a time (the showcase seed does).
     */
    public function recalculateUserScore(int $userId, ?int $weekId, ?int $seasonId, bool $confidenceMode = false, string $confidencePenalty = 'none'): void
    {
        $this->recalculate([$userId], $weekId, $seasonId, $confidenceMode, $confidencePenalty);
    }

    /**
     * Write one scope's score row for each of these members: a week (week id
     * given), a season (season id only) or all time (neither).
     *
     * 🚨 One aggregate query and one write per scope for the whole batch, not
     * per member: a popular game has hundreds of pickers, and scoring it used
     * to cost several queries each.
     *
     * @param int[] $userIds
     */
    public function scoreScope(
        array $userIds,
        ?int $weekId,
        ?int $seasonId,
        bool $confidenceMode = false,
        string $confidencePenalty = 'none'
    ): void {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            $totals = $this->totals($chunk, $weekId, $seasonId, $confidenceMode, $confidencePenalty);

            /*
             * Serialize the read-then-write per scope. Without this, two
             * concurrent scorings can both find no row for a member and both
             * INSERT — the all-time scope (week_id=NULL, season_id=NULL) isn't
             * covered by the unique index because NULL != NULL, so the
             * duplicates become permanent and corrupt leaderboard totals.
             */
            $this->withScopeLock($weekId, $seasonId, fn () => $this->write($chunk, $weekId, $seasonId, $totals));
        }
    }

    /**
     * Each member's totals in a scope, from the scored picks.
     *
     * @param int[] $userIds
     * @return array<int, array{total_picks: int, correct_picks: int, total_points: int, accuracy: float}>
     */
    private function totals(array $userIds, ?int $weekId, ?int $seasonId, bool $confidenceMode, string $confidencePenalty): array
    {
        $query = Pick::query()->whereIn('user_id', $userIds)->whereNotNull('is_correct');

        if ($weekId) {
            $query->whereHas('event', fn ($q) => $q->where('week_id', $weekId));
        } elseif ($seasonId) {
            $query->whereHas('event', fn ($q) => $q->whereHas('week', fn ($w) => $w->where('season_id', $seasonId)));
        }

        /*
         * Aggregated in SQL: a heavy multi-season member can have a great many
         * scored rows, and there is no reason to load them to sum a few totals.
         */
        $penaltyExpr = ! $confidenceMode ? '0' : match ($confidencePenalty) {
            'full' => 'SUM(CASE WHEN is_correct = false THEN COALESCE(confidence, 0) ELSE 0 END)',
            'half' => 'SUM(CASE WHEN is_correct = false THEN FLOOR(COALESCE(confidence, 0) / 2) ELSE 0 END)',
            default => '0',
        };
        $earnedExpr = $confidenceMode
            ? 'SUM(CASE WHEN is_correct = true THEN COALESCE(confidence, 1) ELSE 0 END)'
            : 'SUM(CASE WHEN is_correct = true THEN 1 ELSE 0 END)';

        $rows = $query
            ->select('user_id')
            ->selectRaw('COUNT(*) AS agg_total')
            ->selectRaw('SUM(CASE WHEN is_correct = true THEN 1 ELSE 0 END) AS agg_correct')
            ->selectRaw($earnedExpr.' AS agg_earned')
            ->selectRaw($penaltyExpr.' AS agg_penalty')
            ->groupBy('user_id')
            ->toBase()
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $picks = (int) $row->agg_total;
            $correct = (int) $row->agg_correct;

            $totals[(int) $row->user_id] = [
                'total_picks' => $picks,
                'correct_picks' => $correct,
                'total_points' => max(0, (int) $row->agg_earned - (int) $row->agg_penalty),
                'accuracy' => $picks > 0 ? round($correct / $picks * 100, 2) : 0.0,
            ];
        }

        return $totals;
    }

    /**
     * Update the members' existing rows in one upsert and create the missing
     * ones in one insert. A member with no scored picks here gets zeros.
     *
     * @param int[] $userIds
     * @param array<int, array<string, int|float>> $totals
     */
    private function write(array $userIds, ?int $weekId, ?int $seasonId, array $totals): void
    {
        $existing = UserScore::query()->whereIn('user_id', $userIds);

        if ($weekId) {
            $existing->where('week_id', $weekId)->where('season_id', $seasonId);
        } elseif ($seasonId) {
            $existing->whereNull('week_id')->where('season_id', $seasonId);
        } else {
            $existing->whereNull('week_id')->whereNull('season_id');
        }

        $ids = $existing->pluck('id', 'user_id');
        $zero = ['total_picks' => 0, 'correct_picks' => 0, 'total_points' => 0, 'accuracy' => 0.0];
        $now = Carbon::now();
        $update = [];
        $insert = [];

        foreach ($userIds as $userId) {
            $row = ['user_id' => $userId, 'week_id' => $weekId, 'season_id' => $seasonId] + ($totals[$userId] ?? $zero);

            if ($ids->has($userId)) {
                $update[] = ['id' => (int) $ids->get($userId)] + $row;
            } else {
                $insert[] = $row + ['created_at' => $now, 'updated_at' => $now];
            }
        }

        if ($update !== []) {
            UserScore::query()->upsert($update, ['id'], ['total_picks', 'correct_picks', 'total_points', 'accuracy']);
        }

        if ($insert !== []) {
            UserScore::query()->insert($insert);
        }
    }

    /**
     * Run $write while holding an atomic lock for this scope, so the
     * find-or-create can't interleave with a concurrent update of the same
     * rows. Falls back to running unlocked on cache stores without atomic
     * locks (or if the lock can't be acquired within the wait window) —
     * best-effort, no worse than the prior behaviour.
     */
    private function withScopeLock(?int $weekId, ?int $seasonId, callable $write): void
    {
        $scope = $weekId ? "w{$weekId}" : ($seasonId ? "s{$seasonId}" : 'all');
        $lockKey = "ernestdefoe-picks.score.{$scope}";

        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            $write();

            return;
        }

        $lock = $this->cache->lock($lockKey, self::LOCK_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException $e) {
            $write();

            return;
        }

        try {
            $write();
        } finally {
            $lock->release();
        }
    }
}
