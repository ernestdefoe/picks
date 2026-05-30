<?php

namespace Resofire\Picks\Jobs;

use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Resofire\Picks\Pick;
use Resofire\Picks\PickEvent;
use Resofire\Picks\UserScore;

class ScorePicksJob extends AbstractJob
{
    public function __construct(
        protected int $eventId
    ) {
    }

    public function handle(SettingsRepositoryInterface $settings): void
    {
        $event = PickEvent::find($this->eventId);

        if (! $event || ! $event->isFinished() || ! $event->result) {
            return;
        }

        $confidenceMode    = (bool) $settings->get('ernestdefoe-picks.confidence_mode', false);
        $confidencePenalty = $settings->get('ernestdefoe-picks.confidence_penalty', 'none');

        $picks = Pick::where('event_id', $this->eventId)
            ->with('user')
            ->get();

        if ($picks->isEmpty()) {
            return;
        }

        // Score each pick
        foreach ($picks as $pick) {
            $pick->is_correct = ($pick->selected_outcome === $event->result);
            $pick->save();
        }

        // Get unique user IDs affected
        $userIds = $picks->pluck('user_id')->unique()->values();

        // Recalculate scores for each user
        foreach ($userIds as $userId) {
            $this->recalculateUserScore($userId, $event->week_id, $this->getSeasonId($event->week_id), $confidenceMode, $confidencePenalty);
        }

        // Update rank movement for all users in each affected scope
        $this->updateRankMovements($event->week_id, $this->getSeasonId($event->week_id));
    }

    /**
     * Recalculate and upsert a user's score for a specific week and season.
     */
    private function recalculateUserScore(int $userId, ?int $weekId, ?int $seasonId, bool $confidenceMode = false, string $confidencePenalty = 'none'): void
    {
        if ($weekId) {
            $this->upsertScore($userId, $weekId, $seasonId, weekScope: true, confidenceMode: $confidenceMode, confidencePenalty: $confidencePenalty);
        }

        if ($seasonId) {
            $this->upsertScore($userId, null, $seasonId, weekScope: false, confidenceMode: $confidenceMode, confidencePenalty: $confidencePenalty);
        }

        $this->upsertScore($userId, null, null, weekScope: false, confidenceMode: $confidenceMode, confidencePenalty: $confidencePenalty);
    }

    /**
     * Upsert a single user score row, handling NULL columns correctly.
     * MySQL's unique index treats NULL != NULL, so updateOrCreate with NULLs
     * creates duplicates. We use explicit firstOrNew + save instead.
     */
    private function upsertScore(int $userId, ?int $weekId, ?int $seasonId, bool $weekScope, bool $confidenceMode = false, string $confidencePenalty = 'none'): void
    {
        $query = Pick::where('user_id', $userId)
            ->whereNotNull('is_correct');

        if ($weekScope && $weekId) {
            $query->whereHas('event', fn ($q) => $q->where('week_id', $weekId));
        } elseif ($seasonId) {
            $query->whereHas('event', fn ($q) => $q->whereHas(
                'week', fn ($w) => $w->where('season_id', $seasonId)
            ));
        }

        $allPicks     = (clone $query)->get();
        $totalPicks   = $allPicks->count();
        $correctPicks = $allPicks->where('is_correct', true)->count();

        if ($confidenceMode) {
            // Points from correct picks
            $earned = $allPicks
                ->where('is_correct', true)
                ->sum(fn ($p) => $p->confidence ?? 1);

            // Penalty from incorrect picks
            $penalty = 0;
            if ($confidencePenalty === 'full') {
                // Option A: lose full confidence value
                $penalty = $allPicks
                    ->where('is_correct', false)
                    ->sum(fn ($p) => $p->confidence ?? 0);
            } elseif ($confidencePenalty === 'half') {
                // Option B: lose half confidence value (rounded down)
                $penalty = $allPicks
                    ->where('is_correct', false)
                    ->sum(fn ($p) => (int) floor(($p->confidence ?? 0) / 2));
            }

            $totalPoints = max(0, $earned - $penalty);
        } else {
            $totalPoints = $correctPicks;
        }

        $accuracy = $totalPicks > 0
            ? round($correctPicks / $totalPicks * 100, 2)
            : 0.0;

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
    }

    private function getSeasonId(?int $weekId): ?int
    {
        if (! $weekId) {
            return null;
        }

        return \Resofire\Picks\Week::find($weekId)?->season_id;
    }

    /**
     * After all scores are updated, assign current ranks and store
     * the previous rank so the leaderboard can show movement arrows.
     */
    private function updateRankMovements(?int $weekId, ?int $seasonId): void
    {
        $scopes = [];

        if ($weekId && $seasonId) {
            $scopes[] = ['week_id' => $weekId, 'season_id' => $seasonId];
        }

        if ($seasonId) {
            $scopes[] = ['week_id' => null, 'season_id' => $seasonId];
        }

        $scopes[] = ['week_id' => null, 'season_id' => null];

        foreach ($scopes as $scope) {
            $query = UserScore::where('total_picks', '>', 0);

            if ($scope['week_id'] !== null) {
                $query->where('week_id', $scope['week_id'])
                      ->where('season_id', $scope['season_id']);
            } elseif ($scope['season_id'] !== null) {
                $query->whereNull('week_id')->where('season_id', $scope['season_id']);
            } else {
                $query->whereNull('week_id')->whereNull('season_id');
            }

            $scores = $query->orderByDesc('total_points')
                            ->orderByDesc('correct_picks')
                            ->get();

            foreach ($scores as $rank => $score) {
                $currentRank = $rank + 1;

                // Only update previous_rank when the rank actually changes
                if ($score->previous_rank !== $currentRank) {
                    // Store old rank as previous before overwriting
                    $score->previous_rank = $score->previous_rank ?? $currentRank;
                    $score->save();
                }
            }
        }
    }
}
