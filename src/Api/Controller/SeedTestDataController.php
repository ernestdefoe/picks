<?php

namespace Resofire\Picks\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Pick;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Season;
use Resofire\Picks\Service\ScoreAggregator;
use Resofire\Picks\Team;
use Resofire\Picks\UserScore;
use Resofire\Picks\Week;

/**
 * POST /picks/seed-test-data
 *
 * Body: { "action": "seed2026" | "seedFake2025" | "cleanFake" | "wipeAll" }
 *
 * Admin-only. Powers the Testing tab in the admin panel.
 */
class SeedTestDataController implements RequestHandlerInterface
{
    protected const FAKE_YEAR  = 2025;
    protected const FAKE_SLUG  = '2025-season-fake';
    protected const EVENTS_PER_WEEK = 8;
    protected const FAKE_WEEK_COUNT = 16;

    // Hit rates assigned randomly across users — produces a realistic leaderboard spread
    protected const HIT_RATES = [0.82, 0.75, 0.70, 0.65, 0.60, 0.55, 0.50];

    protected bool $confidenceMode;
    protected string $confidencePenalty;

    public function __construct(
        protected ScoreAggregator $aggregator,
        SettingsRepositoryInterface $settings
    ) {
        // Seeded scores must honour the same confidence settings as live
        // scoring, so the Testing-tab leaderboard matches production behaviour.
        $this->confidenceMode    = (bool) $settings->get('ernestdefoe-picks.confidence_mode', false);
        $this->confidencePenalty = $settings->get('ernestdefoe-picks.confidence_penalty', 'none');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body   = $request->getParsedBody() ?? [];
        $action = Arr::get($body, 'action', '');

        switch ($action) {
            case 'seed2026':
                return $this->seed2026();
            case 'seedFake2025':
                return $this->seedFake2025();
            case 'cleanFake':
                return $this->cleanFake();
            case 'wipeAll':
                return $this->wipeAll();
            default:
                return new JsonResponse(['status' => 'error', 'message' => 'Unknown action.'], 422);
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    private function seed2026(): JsonResponse
    {
        $users   = User::all();
        $userIds = $users->pluck('id')->values()->toArray();

        if (empty($userIds)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No users found.']);
        }

        $hitRates = $this->assignHitRates($userIds);

        $events = PickEvent::query()->whereNotNull('week_id')->get();

        if ($events->isEmpty()) {
            return new JsonResponse(['status' => 'error', 'message' => 'No events found in picks_events. Import your 2026 schedule first.']);
        }

        $eventsByWeek  = $events->groupBy('week_id');
        $weekIds       = $eventsByWeek->keys()->toArray();
        $picksInserted = 0;

        foreach ($weekIds as $weekId) {
            $weekEvents = $eventsByWeek[$weekId];

            foreach ($userIds as $userId) {
                $hitRate = $hitRates[$userId];

                foreach ($weekEvents as $event) {
                    // Skip if pick already exists
                    $exists = Pick::query()
                        ->where('user_id', $userId)
                        ->where('event_id', $event->id)
                        ->exists();

                    if ($exists) continue;

                    $isCorrect = null;
                    $outcome   = $this->randomOutcome();

                    if ($event->status === PickEvent::STATUS_FINISHED && $event->result) {
                        $correct   = $this->rollCorrect($hitRate);
                        $outcome   = $correct ? $event->result : ($event->result === 'home' ? 'away' : 'home');
                        $isCorrect = ($outcome === $event->result);
                    }

                    Pick::query()->insert([
                        'user_id'          => $userId,
                        'event_id'         => $event->id,
                        'selected_outcome' => $outcome,
                        'is_correct'       => $isCorrect,
                        'confidence'       => null,
                        'created_at'       => Carbon::now(),
                        'updated_at'       => Carbon::now(),
                    ]);

                    $picksInserted++;
                }
            }

            // Roll up week scores
            $week     = Week::find($weekId);
            $seasonId = $week?->season_id;
            foreach ($userIds as $userId) {
                $this->upsertScore($userId, $weekId, $seasonId);
            }
        }

        // Roll up season + all-time
        $season2026Id = Week::query()->whereIn('id', $weekIds)->value('season_id');
        foreach ($userIds as $userId) {
            if ($season2026Id) $this->upsertScore($userId, null, $season2026Id);
            $this->upsertScore($userId, null, null);
        }

        return new JsonResponse([
            'status'  => 'success',
            'message' => "Seeded {$picksInserted} picks for " . count($userIds) . " users across " . count($weekIds) . " weeks.",
        ]);
    }

    private function seedFake2025(): JsonResponse
    {
        // Bail if already seeded
        $existing = Season::where('year', self::FAKE_YEAR)->where('slug', self::FAKE_SLUG)->first();
        if ($existing) {
            return new JsonResponse(['status' => 'error', 'message' => 'Fake 2025 season already exists. Use Clean Fake Data first.']);
        }

        $teamIds = Team::query()->pluck('id')->toArray();
        if (count($teamIds) < 2) {
            return new JsonResponse(['status' => 'error', 'message' => 'Need at least 2 teams in picks_teams.']);
        }

        $users   = User::all();
        $userIds = $users->pluck('id')->values()->toArray();
        if (empty($userIds)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No users found.']);
        }

        $hitRates = $this->assignHitRates($userIds);

        // Create fake season
        $season             = new Season();
        $season->name       = '2025 Season';
        $season->slug       = self::FAKE_SLUG;
        $season->year       = self::FAKE_YEAR;
        $season->start_date = '2025-08-28';
        $season->end_date   = '2025-12-06';
        $season->save();

        $picksInserted = 0;
        $weeksCreated  = 0;

        for ($wn = 1; $wn <= self::FAKE_WEEK_COUNT; $wn++) {
            $weekStart = Carbon::parse('2025-08-28')->addWeeks($wn - 1);
            $weekEnd   = $weekStart->copy()->addDays(6);

            $week              = new Week();
            $week->season_id   = $season->id;
            $week->name        = 'Week ' . $wn;
            $week->week_number = $wn;
            $week->season_type = 'regular';
            $week->start_date  = $weekStart->toDateString();
            $week->end_date    = $weekEnd->toDateString();
            $week->is_open     = false;
            $week->save();

            $usedTeams = [];
            $eventIds  = [];
            $results   = [];
            $gameDate  = $weekStart->copy()->addDays(5)->setTime(15, 0);

            for ($e = 0; $e < self::EVENTS_PER_WEEK; $e++) {
                [$homeId, $awayId] = $this->pickTeamPair($teamIds, $usedTeams);
                if (!$homeId || !$awayId) break;

                $usedTeams[] = $homeId;
                $usedTeams[] = $awayId;
                $result      = $this->randomOutcome();

                $eventId = PickEvent::query()->insertGetId([
                    'week_id'      => $week->id,
                    'home_team_id' => $homeId,
                    'away_team_id' => $awayId,
                    'cfbd_id'      => null,
                    'neutral_site' => false,
                    'match_date'   => $gameDate->copy(),
                    'cutoff_date'  => $gameDate->copy()->subMinutes(30),
                    'status'       => PickEvent::STATUS_FINISHED,
                    'home_score'   => $result === 'home' ? rand(21, 42) : rand(7, 20),
                    'away_score'   => $result === 'away' ? rand(21, 42) : rand(7, 20),
                    'result'       => $result,
                    'created_at'   => Carbon::now(),
                    'updated_at'   => Carbon::now(),
                ]);

                $eventIds[]         = $eventId;
                $results[$eventId]  = $result;
                $gameDate->addHours(2);
            }

            // Insert picks for all users
            foreach ($userIds as $userId) {
                $hitRate = $hitRates[$userId];

                foreach ($eventIds as $eventId) {
                    $correct = $this->rollCorrect($hitRate);
                    $outcome = $correct
                        ? $results[$eventId]
                        : ($results[$eventId] === 'home' ? 'away' : 'home');

                    Pick::query()->insert([
                        'user_id'          => $userId,
                        'event_id'         => $eventId,
                        'selected_outcome' => $outcome,
                        'is_correct'       => $correct,
                        'confidence'       => null,
                        'created_at'       => Carbon::now(),
                        'updated_at'       => Carbon::now(),
                    ]);

                    $picksInserted++;
                }

                $this->upsertScore($userId, $week->id, $season->id);
            }

            $weeksCreated++;
        }

        // Roll up season + all-time
        foreach ($userIds as $userId) {
            $this->upsertScore($userId, null, $season->id);
            $this->upsertScore($userId, null, null);
        }

        return new JsonResponse([
            'status'  => 'success',
            'message' => "Created fake 2025 season with {$weeksCreated} weeks and {$picksInserted} picks.",
        ]);
    }

    private function cleanFake(): JsonResponse
    {
        $season = Season::where('year', self::FAKE_YEAR)->where('slug', self::FAKE_SLUG)->first();

        if (!$season) {
            return new JsonResponse(['status' => 'success', 'message' => 'No fake 2025 season found — nothing to clean.']);
        }

        $weekIds = Week::where('season_id', $season->id)->pluck('id')->toArray();

        if (!empty($weekIds)) {
            $eventIds = PickEvent::query()->whereIn('week_id', $weekIds)->pluck('id')->toArray();

            if (!empty($eventIds)) {
                Pick::query()->whereIn('event_id', $eventIds)->delete();
                PickEvent::query()->whereIn('id', $eventIds)->delete();
            }

            UserScore::query()->whereIn('week_id', $weekIds)->delete();
            UserScore::query()
                ->where('season_id', $season->id)
                ->whereNull('week_id')
                ->delete();

            Week::whereIn('id', $weekIds)->delete();
        }

        $season->delete();

        // Recalculate all-time scores without fake data
        $userIds = User::pluck('id')->toArray();
        foreach ($userIds as $userId) {
            $this->upsertScore($userId, null, null);
        }

        return new JsonResponse([
            'status'  => 'success',
            'message' => 'Fake 2025 season removed. All-time scores recalculated.',
        ]);
    }

    private function wipeAll(): JsonResponse
    {
        // Wipe picks and scores for real 2026 events only.
        // Never deletes real events, weeks, or seasons.
        $season2026 = Season::query()->where('year', 2026)->first();

        $picksDeleted  = 0;
        $scoresDeleted = 0;

        if ($season2026) {
            $weekIds = Week::query()->where('season_id', $season2026->id)->pluck('id')->toArray();

            if (!empty($weekIds)) {
                $eventIds = PickEvent::query()->whereIn('week_id', $weekIds)->pluck('id')->toArray();

                if (!empty($eventIds)) {
                    $picksDeleted = Pick::query()->whereIn('event_id', $eventIds)->delete();
                }

                $scoresDeleted += UserScore::query()->whereIn('week_id', $weekIds)->delete();
                $scoresDeleted += UserScore::query()->where('season_id', $season2026->id)->whereNull('week_id')->delete();
            }
        }

        // Also clean fake 2025 if it exists
        $fakeSeason = Season::where('year', self::FAKE_YEAR)->where('slug', self::FAKE_SLUG)->first();
        if ($fakeSeason) {
            $weekIds  = Week::where('season_id', $fakeSeason->id)->pluck('id')->toArray();
            $eventIds = PickEvent::query()->whereIn('week_id', $weekIds)->pluck('id')->toArray();

            if (!empty($eventIds)) {
                $picksDeleted += Pick::query()->whereIn('event_id', $eventIds)->delete();
                PickEvent::query()->whereIn('id', $eventIds)->delete();
            }

            UserScore::query()->whereIn('week_id', $weekIds)->delete();
            UserScore::query()->where('season_id', $fakeSeason->id)->whereNull('week_id')->delete();
            Week::whereIn('id', $weekIds)->delete();
            $fakeSeason->delete();
        }

        // Wipe all-time scores and recalculate (will be zeroed since no picks remain)
        $scoresDeleted += UserScore::query()->whereNull('week_id')->whereNull('season_id')->delete();

        return new JsonResponse([
            'status'  => 'success',
            'message' => "Wiped {$picksDeleted} picks and {$scoresDeleted} score rows. Real schedule data (events/weeks/seasons) is intact.",
        ]);
    }

    // ── Score calculation — delegates to the shared ScoreAggregator ───────────

    /**
     * Roll up a user's score for one scope via the shared ScoreAggregator, so
     * the seeder produces exactly the same totals as live scoring (including
     * confidence mode / penalty — which this method previously ignored).
     */
    private function upsertScore(int $userId, ?int $weekId, ?int $seasonId): void
    {
        $this->aggregator->upsertScore(
            $userId,
            $weekId,
            $seasonId,
            weekScope: $weekId !== null,
            confidenceMode: $this->confidenceMode,
            confidencePenalty: $this->confidencePenalty,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function assignHitRates(array $userIds): array
    {
        $rates = self::HIT_RATES;
        shuffle($rates);
        $hitRates = [];
        foreach ($userIds as $i => $userId) {
            $hitRates[$userId] = $rates[$i % count($rates)];
        }
        return $hitRates;
    }

    private function randomOutcome(): string
    {
        return rand(0, 1) === 1 ? 'home' : 'away';
    }

    private function rollCorrect(float $hitRate): bool
    {
        return (mt_rand(0, 10000) / 10000) <= $hitRate;
    }

    private function pickTeamPair(array $allTeamIds, array $usedTeams): array
    {
        $available = array_values(array_diff($allTeamIds, $usedTeams));
        if (count($available) < 2) return [null, null];
        shuffle($available);
        return [$available[0], $available[1]];
    }
}
