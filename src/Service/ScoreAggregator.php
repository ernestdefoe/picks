<?php

namespace Resofire\Picks\Service;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Resofire\Picks\Pick;
use Resofire\Picks\UserScore;

/**
 * Single source of truth for computing + upserting a user's pick score.
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

    /**
     * Recalculate a user's week, season and all-time scores in one call.
     */
    public function recalculateUserScore(
        int $userId,
        ?int $weekId,
        ?int $seasonId,
        bool $confidenceMode = false,
        string $confidencePenalty = 'none'
    ): void {
        if ($weekId) {
            $this->upsertScore($userId, $weekId, $seasonId, weekScope: true, confidenceMode: $confidenceMode, confidencePenalty: $confidencePenalty);
        }

        if ($seasonId) {
            $this->upsertScore($userId, null, $seasonId, weekScope: false, confidenceMode: $confidenceMode, confidencePenalty: $confidencePenalty);
        }

        $this->upsertScore($userId, null, null, weekScope: false, confidenceMode: $confidenceMode, confidencePenalty: $confidencePenalty);
    }

    /**
     * Upsert a single user score row for one scope, handling NULL columns
     * correctly. MySQL's unique index treats NULL != NULL, so updateOrCreate
     * with NULLs creates duplicates — we use an explicit find-or-create
     * guarded by a per-scope atomic lock (see withScopeLock) so concurrent
     * updates for the same user can't both insert.
     */
    public function upsertScore(
        int $userId,
        ?int $weekId,
        ?int $seasonId,
        bool $weekScope,
        bool $confidenceMode = false,
        string $confidencePenalty = 'none'
    ): void {
        $query = Pick::where('user_id', $userId)
            ->whereNotNull('is_correct');

        if ($weekScope && $weekId) {
            $query->whereHas('event', fn ($q) => $q->where('week_id', $weekId));
        } elseif ($seasonId) {
            $query->whereHas('event', fn ($q) => $q->whereHas(
                'week', fn ($w) => $w->where('season_id', $seasonId)
            ));
        }

        // Aggregate in SQL and return only scalars, instead of loading every
        // scored pick into a PHP Collection and walking it 2–3 times. A heavy
        // multi-season user can have hundreds of thousands of scored rows;
        // there's no reason to materialise them just to sum a few totals.
        if ($confidenceMode) {
            // Penalty from incorrect picks, per the configured rule.
            $penaltyExpr = match ($confidencePenalty) {
                'full'  => 'SUM(CASE WHEN is_correct = false THEN COALESCE(confidence, 0) ELSE 0 END)',
                'half'  => 'SUM(CASE WHEN is_correct = false THEN FLOOR(COALESCE(confidence, 0) / 2) ELSE 0 END)',
                default => '0',
            };

            $row = (clone $query)->selectRaw(
                'COUNT(*) AS agg_total, '
                . 'SUM(CASE WHEN is_correct = true THEN 1 ELSE 0 END) AS agg_correct, '
                . 'SUM(CASE WHEN is_correct = true THEN COALESCE(confidence, 1) ELSE 0 END) AS agg_earned, '
                . $penaltyExpr . ' AS agg_penalty'
            )->first();

            $totalPicks   = (int) ($row->agg_total ?? 0);
            $correctPicks = (int) ($row->agg_correct ?? 0);
            $totalPoints  = max(0, (int) ($row->agg_earned ?? 0) - (int) ($row->agg_penalty ?? 0));
        } else {
            $row = (clone $query)->selectRaw(
                'COUNT(*) AS agg_total, '
                . 'SUM(CASE WHEN is_correct = true THEN 1 ELSE 0 END) AS agg_correct'
            )->first();

            $totalPicks   = (int) ($row->agg_total ?? 0);
            $correctPicks = (int) ($row->agg_correct ?? 0);
            $totalPoints  = $correctPicks;
        }

        $accuracy = $totalPicks > 0
            ? round($correctPicks / $totalPicks * 100, 2)
            : 0.0;

        // Serialize the read-then-write per (user, scope). Without this, two
        // concurrent score updates for the same user can both find no
        // existing row and both INSERT — the all-time scope (week_id=NULL,
        // season_id=NULL) isn't covered by the unique index because NULL !=
        // NULL, so the duplicates become permanent and corrupt leaderboard
        // totals. The lock is the portable equivalent of a SELECT … FOR
        // UPDATE that also covers the not-yet-existing row.
        $this->withScopeLock($userId, $weekScope, $weekId, $seasonId, function () use (
            $userId, $weekScope, $weekId, $seasonId, $totalPicks, $correctPicks, $totalPoints, $accuracy
        ) {
            $scoreQuery = UserScore::where('user_id', $userId);

            if ($weekScope && $weekId) {
                $scoreQuery->where('week_id', $weekId)->where('season_id', $seasonId);
            } elseif ($seasonId) {
                $scoreQuery->whereNull('week_id')->where('season_id', $seasonId);
            } else {
                $scoreQuery->whereNull('week_id')->whereNull('season_id');
            }

            $score = $scoreQuery->first() ?? new UserScore();

            $score->user_id       = $userId;
            $score->week_id       = ($weekScope && $weekId) ? $weekId : null;
            $score->season_id     = $seasonId;
            $score->total_picks   = $totalPicks;
            $score->correct_picks = $correctPicks;
            $score->total_points  = $totalPoints;
            $score->accuracy      = $accuracy;
            $score->save();
        });
    }

    /**
     * Run $write while holding an atomic lock for this user + scope, so the
     * find-or-create can't interleave with a concurrent update for the same
     * row. Falls back to running unlocked on cache stores without atomic
     * locks (or if the lock can't be acquired within the wait window) —
     * best-effort, no worse than the prior behaviour.
     */
    private function withScopeLock(int $userId, bool $weekScope, ?int $weekId, ?int $seasonId, callable $write): void
    {
        $scope   = ($weekScope && $weekId) ? "w{$weekId}" : ($seasonId ? "s{$seasonId}" : 'all');
        $lockKey = "ernestdefoe-picks.score.{$userId}.{$scope}";

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
