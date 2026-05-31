<?php

namespace Resofire\Picks\Jobs;

use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Resofire\Picks\Pick;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Service\ScoreAggregator;
use Resofire\Picks\UserScore;

class ScorePicksJob extends AbstractJob
{
    public function __construct(
        protected int $eventId
    ) {
    }

    public function handle(SettingsRepositoryInterface $settings, ScoreAggregator $aggregator): void
    {
        $event = PickEvent::find($this->eventId);

        if (! $event || ! $event->isFinished() || ! $event->result) {
            return;
        }

        $confidenceMode    = (bool) $settings->get('ernestdefoe-picks.confidence_mode', false);
        $confidencePenalty = $settings->get('ernestdefoe-picks.confidence_penalty', 'none');

        // Unique users affected — read before the batch update so we can bail
        // early when nobody picked this game.
        $userIds = Pick::where('event_id', $this->eventId)
            ->pluck('user_id')
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        // Score every pick in two batched UPDATEs instead of one save() per
        // row — a popular week can have hundreds of picks per game.
        Pick::where('event_id', $this->eventId)
            ->where('selected_outcome', $event->result)
            ->update(['is_correct' => true]);

        Pick::where('event_id', $this->eventId)
            ->where('selected_outcome', '!=', $event->result)
            ->update(['is_correct' => false]);

        // Recalculate scores for each user (shared ScoreAggregator)
        foreach ($userIds as $userId) {
            $aggregator->recalculateUserScore($userId, $event->week_id, $this->getSeasonId($event->week_id), $confidenceMode, $confidencePenalty);
        }

        // Update rank movement for all users in each affected scope
        $this->updateRankMovements($event->week_id, $this->getSeasonId($event->week_id));
    }

    private function getSeasonId(?int $weekId): ?int
    {
        if (! $weekId) {
            return null;
        }

        return \Resofire\Picks\Week::find($weekId)?->season_id;
    }

    /**
     * After all scores are updated, recompute each scope's ranking and record
     * movement. The prior pass's rank (current_rank) rolls into previous_rank
     * and this pass's rank becomes current_rank, so the leaderboard's movement
     * arrows reflect the change since the last scoring — previous_rank used to
     * freeze at the first-ever rank.
     *
     * Only rows whose stored ranks actually change are written, in a single
     * upsert per scope (was one save() per row — up to ~600 UPDATEs for a
     * 200-player game across the three scopes).
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
                            ->get(['id', 'user_id', 'previous_rank', 'current_rank']);

            $rows = [];

            foreach ($scores as $index => $score) {
                $currentRank  = $index + 1;
                $previousRank = $score->current_rank !== null ? (int) $score->current_rank : $currentRank;

                $unchanged = $score->current_rank !== null
                    && (int) $score->current_rank === $currentRank
                    && $score->previous_rank !== null
                    && (int) $score->previous_rank === $previousRank;

                if ($unchanged) {
                    continue;
                }

                $rows[] = [
                    'id'            => $score->id,
                    'user_id'       => $score->user_id,
                    'previous_rank' => $previousRank,
                    'current_rank'  => $currentRank,
                ];
            }

            if (! empty($rows)) {
                // user_id is included so the (never-taken) INSERT branch stays
                // valid; only the two rank columns are written on conflict.
                UserScore::query()->upsert($rows, ['id'], ['previous_rank', 'current_rank']);
            }
        }
    }
}
