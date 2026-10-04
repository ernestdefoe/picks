<?php

namespace Resofire\Picks\Confidence;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Week;

/**
 * The Confidence contest: N chosen games a week, each picked with a unique
 * value from 1 to N, running alongside the full board without touching it.
 *
 * The rules themselves are pure and tested on their own — Selector chooses the
 * games, Rules decides what a member may save, Scoring scores and ranks. This
 * class gathers the facts from the database, hands them over, and writes back
 * what they decide.
 */
class ConfidenceContest
{
    public const DEFAULT_SIZE = 10;
    public const MIN_SIZE = 3;
    public const MAX_SIZE = 20;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db
    ) {
    }

    /* ------------------------------------------------------------- settings */

    public function enabled(): bool
    {
        return (bool) $this->settings->get('ernestdefoe-picks.confidence10_enabled', false);
    }

    public function size(): int
    {
        $n = (int) $this->settings->get('ernestdefoe-picks.confidence10_games', self::DEFAULT_SIZE);

        return max(self::MIN_SIZE, min(self::MAX_SIZE, $n ?: self::DEFAULT_SIZE));
    }

    public function penalty(): string
    {
        $rule = (string) $this->settings->get('ernestdefoe-picks.confidence10_penalty', 'none');

        return in_array($rule, Scoring::PENALTIES, true) ? $rule : 'none';
    }

    /* ------------------------------------------------------------ selection */

    /** @return Collection<int, ConfidenceGame> in position order, events loaded */
    public function selection(int $weekId): Collection
    {
        return ConfidenceGame::with(['event.homeTeam', 'event.awayTeam', 'event.week'])
            ->where('week_id', $weekId)
            ->orderBy('position')
            ->get()
            ->filter(fn (ConfidenceGame $g) => $g->event !== null)
            ->values();
    }

    /**
     * Frozen once ANY chosen game can no longer be picked — the first of them
     * has locked. From then on the games are what members are already playing.
     */
    public function isFrozen(Collection $selection, ?\DateTimeInterface $now = null): bool
    {
        $now ??= Carbon::now('UTC');

        foreach ($selection as $game) {
            if (! Selector::isPickable(self::row($game->event), $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Choose a week's games if nobody has yet. Called when a week opens, and
     * on every read of an open week, so a week opened by any route (the
     * scheduler, the admin switch, the API) gets its games.
     */
    public function ensureSelected(Week $week): void
    {
        if (! $this->enabled() || ! $week->is_open) {
            return;
        }

        if (ConfidenceGame::where('week_id', $week->id)->exists()) {
            return;
        }

        $this->autoSelect($week);
    }

    /** @return string|null an error code, or null on success */
    public function autoSelect(Week $week): ?string
    {
        $events = PickEvent::where('week_id', $week->id)->get();
        $chosen = Selector::choose($events->map(fn (PickEvent $e) => self::row($e))->all(), $this->size(), Carbon::now('UTC'));

        if ($chosen === []) {
            return 'no_games';
        }

        return $this->setSelection($week, $chosen);
    }

    /**
     * Replace a week's games with $eventIds, in that order.
     *
     * 🚨 A member's picks on a game taken OUT are deleted: that game is no
     * longer in their contest, and a pick left behind would hold a value they
     * could not see or move. And if the FIRST game changes, everyone's
     * tiebreaker guess is cleared, because it was a guess about another game.
     *
     * @param int[] $eventIds
     * @return string|null an error code, or null on success
     */
    public function setSelection(Week $week, array $eventIds): ?string
    {
        $eventIds = array_values(array_map('intval', $eventIds));

        if (count($eventIds) !== count(array_unique($eventIds))) {
            return 'duplicate_game';
        }

        if (count($eventIds) > $this->size()) {
            return 'too_many';
        }

        $current = $this->selection($week->id);

        if ($this->isFrozen($current)) {
            return 'frozen';
        }

        $events  = PickEvent::where('week_id', $week->id)->whereIn('id', $eventIds ?: [0])->get()->keyBy('id');
        $now     = Carbon::now('UTC');
        $already = $current->pluck('event_id')->map(fn ($id) => (int) $id)->all();

        foreach ($eventIds as $id) {
            $event = $events->get($id);

            if (! $event) {
                return 'not_in_week';
            }

            if (! Selector::isPickable(self::row($event), $now)) {
                return in_array($id, $already, true) ? 'frozen' : 'started';
            }
        }

        $removed  = array_values(array_diff($already, $eventIds));
        $oldFirst = $already[0] ?? null;
        $newFirst = $eventIds[0] ?? null;

        $this->db->transaction(function () use ($week, $eventIds, $removed, $oldFirst, $newFirst) {
            ConfidenceGame::where('week_id', $week->id)->delete();

            $stamp = Carbon::now();
            $rows  = [];
            foreach ($eventIds as $i => $id) {
                $rows[] = [
                    'week_id'    => $week->id,
                    'event_id'   => $id,
                    'position'   => $i + 1,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
            if ($rows !== []) {
                ConfidenceGame::query()->insert($rows);
            }

            if ($removed !== []) {
                ConfidencePick::where('week_id', $week->id)->whereIn('event_id', $removed)->delete();
            }

            if ($oldFirst !== null && $oldFirst !== $newFirst) {
                ConfidenceEntry::where('week_id', $week->id)->update(['tiebreaker_total' => null]);
            }
        });

        return null;
    }

    /* --------------------------------------------------------------- member */

    /**
     * Everything the member's Confidence tab draws for one week.
     */
    public function board(Week $week, User $actor): array
    {
        $this->ensureSelected($week);

        $selection = $this->selection($week->id);
        $eventIds  = $selection->pluck('event_id')->all();

        $mine  = collect();
        $entry = null;
        $score = null;

        if (! $actor->isGuest()) {
            $mine = ConfidencePick::where('user_id', $actor->id)
                ->where('week_id', $week->id)
                ->get()
                ->keyBy('event_id');

            $entry = ConfidenceEntry::where('user_id', $actor->id)->where('week_id', $week->id)->first();
            $score = ConfidenceScore::where('user_id', $actor->id)->where('scope', 'w' . $week->id)->first();
        }

        $first = $selection->first();

        $games = $selection->map(function (ConfidenceGame $g) use ($mine) {
            $pick = $mine->get($g->event_id);

            return self::gamePayload($g->event) + [
                'position'      => (int) $g->position,
                'is_tiebreaker' => (int) $g->position === 1,
                'my_pick'       => $pick ? [
                    'selected_outcome' => $pick->selected_outcome,
                    'confidence'       => (int) $pick->confidence,
                    'is_correct'       => $pick->is_correct,
                ] : null,
            ];
        })->values()->all();

        return [
            'week_id'    => (int) $week->id,
            'week_open'  => (bool) $week->is_open,
            'enabled'    => $this->enabled(),
            'size'       => $this->size(),
            'penalty'    => $this->penalty(),
            'frozen'     => $this->isFrozen($selection),
            'games'      => $games,
            'tiebreaker' => $first ? [
                'event_id'     => (int) $first->event_id,
                'can_change'   => $first->event->canPick(),
                'guess'        => $entry?->tiebreaker_total,
                'actual_total' => $first->event->hasScores()
                    ? (int) $first->event->home_score + (int) $first->event->away_score
                    : null,
            ] : null,
            'my_score'   => $score ? [
                'total_points'  => (int) $score->total_points,
                'total_picks'   => (int) $score->total_picks,
                'correct_picks' => (int) $score->correct_picks,
                'tiebreak_diff' => $score->tiebreak_diff,
            ] : null,
            'picked'     => $mine->filter(fn ($p) => in_array($p->event_id, $eventIds))->count(),
        ];
    }

    /**
     * Save a member's picks for a week. Returns null, or an error code with
     * the game it concerns.
     *
     * @param array<int, array<string, mixed>> $submitted
     * @param mixed $tiebreaker  a number, null to clear, or false to leave alone
     */
    public function save(Week $week, User $actor, array $submitted, $tiebreaker = false): ?array
    {
        if (! $this->enabled()) {
            return ['error' => 'disabled', 'event_id' => null];
        }

        $selection = $this->selection($week->id);

        if ($selection->isEmpty()) {
            return ['error' => Rules::NOT_IN_CONTEST, 'event_id' => null];
        }

        $locked = $selection->filter(fn (ConfidenceGame $g) => ! $g->event->canPick())
            ->pluck('event_id')->map(fn ($id) => (int) $id)->all();

        $existing = ConfidencePick::where('user_id', $actor->id)
            ->where('week_id', $week->id)
            ->get()
            ->mapWithKeys(fn (ConfidencePick $p) => [(int) $p->event_id => [
                'outcome'    => $p->selected_outcome,
                'confidence' => (int) $p->confidence,
            ]])
            ->all();

        $result = Rules::validate(
            $this->size(),
            $selection->pluck('event_id')->all(),
            $locked,
            $existing,
            $submitted
        );

        if ($result['error'] !== null) {
            return ['error' => $result['error'], 'event_id' => $result['event_id']];
        }

        $guess = null;
        if ($tiebreaker !== false) {
            $checked = Rules::tiebreaker($tiebreaker);
            if ($checked['error'] !== null) {
                return ['error' => $checked['error'], 'event_id' => null];
            }

            $first = $selection->first();
            $entry = ConfidenceEntry::where('user_id', $actor->id)->where('week_id', $week->id)->first();
            $was   = $entry?->tiebreaker_total;

            if ($checked['value'] !== $was && ! $first->event->canPick()) {
                return ['error' => Rules::LOCKED, 'event_id' => (int) $first->event_id];
            }

            $guess = $checked;
        }

        $this->db->transaction(function () use ($week, $actor, $locked, $result, $guess) {
            /*
             * 🚨 Delete-then-insert, so a reshuffle never collides with the
             * (user, week, value) key halfway through: swapping 10 and 9 in
             * place would briefly hold two 10s.
             */
            ConfidencePick::where('user_id', $actor->id)
                ->where('week_id', $week->id)
                ->whereNotIn('event_id', $locked ?: [0])
                ->delete();

            $stamp = Carbon::now();
            $rows  = [];
            foreach ($result['write'] as $eventId => $pick) {
                $rows[] = [
                    'user_id'          => $actor->id,
                    'week_id'          => $week->id,
                    'event_id'         => $eventId,
                    'selected_outcome' => $pick['outcome'],
                    'confidence'       => $pick['confidence'],
                    'is_correct'       => null,
                    'created_at'       => $stamp,
                    'updated_at'       => $stamp,
                ];
            }
            if ($rows !== []) {
                ConfidencePick::query()->insert($rows);
            }

            if ($guess !== null) {
                $entry = ConfidenceEntry::firstOrNew(['user_id' => $actor->id, 'week_id' => $week->id]);
                $entry->tiebreaker_total = $guess['value'];
                $entry->save();
            }
        });

        return null;
    }

    /* -------------------------------------------------------------- scoring */

    /**
     * Score one finished game's Confidence picks, and restate the standings
     * of everyone it touches. Runs inside ScorePicksJob, on the queue.
     */
    public function scoreEvent(PickEvent $event): void
    {
        if (! $event->week_id) {
            return;
        }

        $inContest = ConfidenceGame::where('week_id', $event->week_id)->where('event_id', $event->id)->first();
        $hasPicks  = ConfidencePick::where('event_id', $event->id)->exists();

        if (! $inContest && ! $hasPicks) {
            return;
        }

        // 🚨 A draw is VOID, as on the full board: nobody could pick it.
        if (in_array($event->result, ['home', 'away'], true)) {
            ConfidencePick::where('event_id', $event->id)->where('selected_outcome', $event->result)->update(['is_correct' => true]);
            ConfidencePick::where('event_id', $event->id)->where('selected_outcome', '!=', $event->result)->update(['is_correct' => false]);
        } else {
            ConfidencePick::where('event_id', $event->id)->update(['is_correct' => null]);
        }

        $userIds = ConfidencePick::where('event_id', $event->id)->pluck('user_id');

        // The tiebreaker game's final moves everybody who guessed at it.
        if ($inContest && (int) $inContest->position === 1) {
            $userIds = $userIds->merge(ConfidenceEntry::where('week_id', $event->week_id)->pluck('user_id'));
        }

        $this->restate($event->week_id, $userIds->map(fn ($id) => (int) $id)->unique()->values()->all());
    }

    /**
     * Recompute the week and season rows of these members, then re-rank both.
     *
     * @param int[] $userIds
     */
    public function restate(int $weekId, array $userIds): void
    {
        $week = Week::find($weekId);

        if (! $week || $userIds === []) {
            return;
        }

        $penalty = $this->penalty();
        $first   = ConfidenceGame::with('event')->where('week_id', $weekId)->where('position', 1)->first();

        $seasonWeekIds = Week::where('season_id', $week->season_id)->pluck('id')->all();

        foreach ($userIds as $userId) {
            $weekPicks = ConfidencePick::where('user_id', $userId)->where('week_id', $weekId)->get(['is_correct', 'confidence']);
            $total     = Scoring::total(self::scorable($weekPicks), $penalty);

            $guess = ConfidenceEntry::where('user_id', $userId)->where('week_id', $weekId)->value('tiebreaker_total');
            $diff  = $first && $first->event && $first->event->isFinished()
                ? Scoring::tiebreakDiff($guess === null ? null : (int) $guess, $first->event->home_score, $first->event->away_score)
                : null;

            $this->writeScore($userId, (int) $week->season_id, $weekId, 'w' . $weekId, $total, $diff);

            $seasonPicks = ConfidencePick::where('user_id', $userId)->whereIn('week_id', $seasonWeekIds)->get(['is_correct', 'confidence']);
            $seasonTotal = Scoring::total(self::scorable($seasonPicks), $penalty);
            $seasonDiffs = ConfidenceScore::where('user_id', $userId)
                ->whereIn('week_id', $seasonWeekIds)
                ->whereNotNull('tiebreak_diff')
                ->pluck('tiebreak_diff');

            $this->writeScore(
                $userId,
                (int) $week->season_id,
                null,
                's' . $week->season_id,
                $seasonTotal,
                $seasonDiffs->isEmpty() ? null : (int) $seasonDiffs->sum()
            );
        }

        $this->rerank('w' . $weekId);
        $this->rerank('s' . $week->season_id);
    }

    private function writeScore(int $userId, int $seasonId, ?int $weekId, string $scope, array $total, ?int $diff): void
    {
        ConfidenceScore::updateOrCreate(
            ['user_id' => $userId, 'scope' => $scope],
            [
                'season_id'     => $seasonId,
                'week_id'       => $weekId,
                'total_points'  => $total['points'],
                'total_picks'   => $total['picks'],
                'correct_picks' => $total['correct'],
                'accuracy'      => $total['accuracy'],
                'tiebreak_diff' => $diff,
            ]
        );
    }

    /** The standings order for one scope, with last pass's rank kept for the arrows. */
    private function rerank(string $scope): void
    {
        $rows = $this->rankedRows($scope);

        foreach ($rows as $index => $row) {
            $rank  = $index + 1;
            $score = $row['model'];

            if ((int) $score->current_rank === $rank && $score->previous_rank !== null) {
                continue;
            }

            $score->previous_rank = $score->current_rank ?? $rank;
            $score->current_rank  = $rank;
            $score->save();
        }
    }

    /** @return array<int, array{user_id:int, points:int, correct:int, diff:?int, model:ConfidenceScore}> */
    private function rankedRows(string $scope): array
    {
        $rows = ConfidenceScore::with('user')
            ->where('scope', $scope)
            ->where('total_picks', '>', 0)
            ->get()
            ->map(fn (ConfidenceScore $s) => [
                'user_id' => (int) $s->user_id,
                'points'  => (int) $s->total_points,
                'correct' => (int) $s->correct_picks,
                'diff'    => $s->tiebreak_diff,
                'model'   => $s,
            ])
            ->all();

        return Scoring::rank($rows);
    }

    /* ------------------------------------------------------------ standings */

    public function standings(string $scope, ?User $actor = null, int $limit = 25): array
    {
        $rows = array_slice($this->rankedRows($scope), 0, $limit);

        return array_map(function (array $row, int $index) use ($actor) {
            /** @var ConfidenceScore $s */
            $s    = $row['model'];
            $rank = $index + 1;

            return [
                'rank'          => $rank,
                'previous_rank' => $s->previous_rank,
                'movement'      => $s->previous_rank !== null && $s->current_rank !== null
                    ? (int) $s->previous_rank - (int) $s->current_rank
                    : null,
                'user_id'       => (int) $s->user_id,
                'username'      => $s->user?->username,
                'display_name'  => $s->user?->display_name ?? $s->user?->username,
                'avatar_url'    => $s->user?->avatarUrl,
                'total_points'  => (int) $s->total_points,
                'total_picks'   => (int) $s->total_picks,
                'correct_picks' => (int) $s->correct_picks,
                'accuracy'      => (float) $s->accuracy,
                'tiebreak_diff' => $s->tiebreak_diff,
                'is_me'         => $actor !== null && ! $actor->isGuest() && (int) $s->user_id === (int) $actor->id,
            ];
        }, $rows, array_keys($rows));
    }

    /* -------------------------------------------------------------- helpers */

    /** @return array<int, array{is_correct:?bool, confidence:int}> */
    private static function scorable(Collection $picks): array
    {
        return $picks->map(fn ($p) => [
            'is_correct' => $p->is_correct === null ? null : (bool) $p->is_correct,
            'confidence' => (int) $p->confidence,
        ])->all();
    }

    /** The facts Selector reads from a game. */
    public static function row(PickEvent $e): array
    {
        $cutoff = $e->getRawOriginal('cutoff_date');
        $match  = $e->getRawOriginal('match_date');

        return [
            'id'          => (int) $e->id,
            'status'      => (string) $e->status,
            'cutoff'      => $cutoff ? Carbon::parse((string) $cutoff, 'UTC')->utc()->format('Y-m-d H:i:s') : null,
            'match_date'  => $match ? Carbon::parse((string) $match, 'UTC')->utc()->format('Y-m-d H:i:s') : null,
            'home_rank'   => $e->home_rank,
            'away_rank'   => $e->away_rank,
            'home_record' => $e->home_record,
            'away_record' => $e->away_record,
            'broadcast'   => $e->broadcast,
            'time_tbd'    => (bool) $e->time_tbd,
        ];
    }

    /** One game, in the shape the forum's game cards already draw. */
    public static function gamePayload(PickEvent $e): array
    {
        $team = fn ($t) => $t ? [
            'id'            => $t->id,
            'name'          => $t->name,
            'abbreviation'  => $t->abbreviation,
            'conference'    => $t->conference,
            'logo_url'      => $t->logo_url,
            'logo_dark_url' => $t->logo_dark_url,
        ] : null;

        return [
            'id'           => (int) $e->id,
            'status'       => $e->status,
            'can_pick'     => $e->canPick(),
            'pickable'     => Selector::isPickable(self::row($e), Carbon::now('UTC')),
            'match_date'   => $e->match_date?->toIso8601String(),
            'time_tbd'     => (bool) $e->time_tbd,
            'cutoff_date'  => $e->cutoff_date?->toIso8601String(),
            'neutral_site' => (bool) $e->neutral_site,
            'home_score'   => $e->home_score,
            'away_score'   => $e->away_score,
            'result'       => $e->result,
            'home_rank'    => Selector::rank($e->home_rank),
            'away_rank'    => Selector::rank($e->away_rank),
            'home_record'  => $e->home_record ?: null,
            'away_record'  => $e->away_record ?: null,
            'broadcast'    => $e->broadcast ?: null,
            'home_team'    => $team($e->homeTeam),
            'away_team'    => $team($e->awayTeam),
        ];
    }
}
