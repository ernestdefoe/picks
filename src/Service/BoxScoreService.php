<?php

namespace Resofire\Picks\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Carbon;
use Resofire\Picks\BoxScore;
use Resofire\Picks\Service\Leagues\League;
use Resofire\Picks\Service\Leagues\Leagues;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Week;

/**
 * Fetching and normalising what happened in a game.
 *
 * 🚨 Picks fetches; everything else reads. The provider relationship, the API
 * key and the call budget live on this side, and one place is what makes a
 * budget enforceable at all. Game Day reads the rows this leaves behind and
 * reaches nothing itself.
 *
 * 🚨 This is the SIBLING of `Services/BoxScores.php` in the Convoro build of
 * Picks, and the normalising below is deliberately the same logic. If the
 * provider's shape changes, both change — they are two ports of one idea, not
 * two ideas. The tests either side use the same captured payload for exactly
 * that reason.
 */
class BoxScoreService
{
    /**
     * How long after a game finishes to keep trying for its box score.
     *
     * 🚨 There is a gap between a final score and a published box score —
     * minutes usually, sometimes longer — so the first pass after a game often
     * finds nothing, and giving up immediately would mean never having one.
     * Two days survives a scheduler that was off for a night, and stops a game
     * the provider never covered being asked about for ever.
     */
    public const KEEP_TRYING_HOURS = 48;

    /** Weeks fetched in one pass, so a backfill cannot run long. */
    private const WEEKS_PER_PASS = 2;

    public function __construct(
        protected CfbdService $cfbd,
        protected SettingsRepositoryInterface $settings,
        protected \Resofire\Picks\Service\Providers\EspnProvider $espn,
        protected Leagues $leagues = new Leagues()
    ) {
    }

    /**
     * Fetches box scores for finished games that have none yet.
     *
     * @return array{fetched: int, weeks: int, error: string}
     */
    public function sync(): array
    {
        $weeks = $this->weeksNeeding();

        if ($weeks->isEmpty()) {
            return ['fetched' => 0, 'weeks' => 0, 'error' => ''];
        }

        $fetched = 0;

        foreach ($weeks->take(self::WEEKS_PER_PASS) as $week) {
            try {
                $fetched += $this->fetchWeek($week);
            } catch (\Throwable $e) {
                /*
                 * 🚨 Stops at the first refusal rather than working through the
                 * rest. A spent quota or a rejected key applies to every call
                 * that would follow, and asking again is how an outage becomes
                 * an outage plus a wasted allowance.
                 */
                return ['fetched' => $fetched, 'weeks' => $weeks->count(), 'error' => $e->getMessage()];
            }
        }

        return ['fetched' => $fetched, 'weeks' => $weeks->count(), 'error' => ''];
    }

    /**
     * The normalised box score for a game, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function forEvent(int $eventId): ?array
    {
        $row = BoxScore::where('event_id', $eventId)->first();

        if ($row === null) {
            return null;
        }

        $document = $row->document;

        if ($document === []) {
            return null;
        }

        // The row's own timestamp rather than one inside the document, so a
        // screen saying how old this is cannot disagree with the table.
        $document['fetched_at'] = (string) $row->fetched_at;

        return $document;
    }

    /* ------------------------------------------------------------- fetching */

    protected function fetchWeek(Week $week): int
    {
        $season = $week->season;
        $year = (int) ($season->year ?? 0);

        if ($year < 1) {
            return 0;
        }

        $league = $this->leagues->get($season?->league);

        if ($league->provider !== 'cfbd') {
            return $this->fetchWeekFromEspn($week, $league, $year);
        }

        /*
         * 🚨 The key check lives HERE rather than at the top of `sync()`, where
         * it used to be. Every league but college football is on ESPN, which
         * needs no key at all — and a board that follows only the NFL would
         * otherwise be told it was "unconfigured" and never fetch a single box
         * score, with nothing on screen explaining why.
         */
        if (empty($this->settings->get('ernestdefoe-picks.cfbd_api_key'))) {
            return 0;
        }

        $type = (string) ($week->season_type ?: 'regular');
        $number = (int) $week->week_number;

        $teams = $this->cfbd->fetchTeamBoxScores($year, $type, $number);

        if ($teams === []) {
            // Answered, and had nothing. Not a failure — the provider has
            // simply not published this week's box scores yet.
            return 0;
        }

        /*
         * 🚨 The team half is NOT stored on its own. A box score that arrives
         * without its leaders would satisfy a "have we got one" check, and
         * nothing would ever come back for the rest of it — a half-written row
         * is how a gap becomes permanent.
         */
        $players = $this->cfbd->fetchPlayerBoxScores($year, $type, $number);

        $stored = 0;

        foreach ($this->finishedIn($week) as $event) {
            $gameId = (int) $event->cfbd_id;

            if (!isset($teams[$gameId])) {
                continue;
            }

            $document = $this->normalise($gameId, $teams[$gameId], $players[$gameId] ?? [], $league);

            if ($document === null) {
                continue;
            }

            BoxScore::updateOrCreate(
                ['event_id' => $event->id],
                ['payload' => json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                 'fetched_at' => Carbon::now()],
            );

            $stored++;
        }

        return $stored;
    }

    /**
     * The same week, from ESPN.
     *
     * 🚨 ESPN answers a box score per GAME, where CollegeFootballData answers a
     * whole week at once — so this loops where the other does not, and the
     * provider caps how many summaries one run may fetch. A Saturday of college
     * basketball is a hundred and fifty games, and a scheduled job that fired a
     * hundred and fifty outbound requests inside a minute has taken a site on
     * this stack down before. What is not fetched now is fetched next hour; a
     * box score arriving late is invisible, and a dead queue worker is not.
     */
    protected function fetchWeekFromEspn(Week $week, League $league, int $year): int
    {
        if (!$this->espn->supports($league)) {
            return 0;
        }

        $type = (string) ($week->season_type ?: 'regular');
        $number = (int) $week->week_number;
        $stored = 0;

        foreach ($this->finishedIn($week) as $event) {
            $externalId = (string) ($event->external_id ?? '');

            if ($externalId === '') {
                continue;
            }

            $sides = $this->espn->boxScore($league, $externalId, $year, $number, $type);

            if ($sides === null) {
                continue;
            }

            $document = $this->normalise((int) $event->id, $sides['teams'], $sides['players'], $league);

            if ($document === null) {
                continue;
            }

            BoxScore::updateOrCreate(
                ['event_id' => $event->id],
                ['payload' => json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                 'fetched_at' => Carbon::now()],
            );

            $stored++;
        }

        return $stored;
    }

    /** Weeks holding a finished game that still wants a box score. */
    protected function weeksNeeding()
    {
        $cutoff = Carbon::now()->subHours(self::KEEP_TRYING_HOURS);

        $weekIds = PickEvent::query()
            ->where('status', PickEvent::STATUS_FINISHED)
            /*
             * 🚨 Either id will do. `cfbd_id` was the only one there was; a
             * league synced from ESPN has an `external_id` and no `cfbd_id`,
             * and requiring the old column would quietly exclude every game
             * that is not college football.
             */
            ->where(fn ($q) => $q->whereNotNull('cfbd_id')->orWhereNotNull('external_id'))
            ->where('match_date', '>', $cutoff)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('picks_box_scores')
                    ->whereColumn('picks_box_scores.event_id', 'picks_events.id');
            })
            ->distinct()
            ->pluck('week_id')
            ->filter()
            ->all();

        return Week::query()->whereIn('id', $weekIds)->orderByDesc('week_number')->get();
    }

    protected function finishedIn(Week $week)
    {
        return PickEvent::query()
            ->where('week_id', $week->id)
            ->where('status', PickEvent::STATUS_FINISHED)
            ->where(fn ($q) => $q->whereNotNull('cfbd_id')->orWhereNotNull('external_id'))
            ->where('match_date', '>', Carbon::now()->subHours(self::KEEP_TRYING_HOURS))
            ->get();
    }

    /* ---------------------------------------------------------- normalising */

    /**
     * The provider's two answers, turned into one document of our own shape.
     *
     * @param  array<int, array<string, mixed>> $teamSides
     * @param  array<int, array<string, mixed>> $playerSides
     * @return array<string, mixed>|null
     */
    public function normalise(int $gameId, array $teamSides, array $playerSides, ?League $league = null): ?array
    {
        $league ??= (new Leagues())->get(Leagues::DEFAULT);

        $document = ['game' => $gameId];

        foreach ($teamSides as $side) {
            $where = ($side['homeAway'] ?? '') === 'away' ? 'away' : 'home';

            $document[$where] = [
                'team'    => (string) ($side['team'] ?? ''),
                'points'  => isset($side['points']) ? (int) $side['points'] : null,
                'stats'   => $this->teamStats($side['stats'] ?? []),
                'leaders' => [],
            ];
        }

        if (!isset($document['home'], $document['away'])) {
            return null;
        }

        foreach ($playerSides as $side) {
            $where = ($side['homeAway'] ?? '') === 'away' ? 'away' : 'home';

            if (!isset($document[$where])) {
                continue;
            }

            $document[$where]['leaders'] = $this->leaders($side['categories'] ?? [], $league->leaders);
        }

        return $document;
    }

    /**
     * `[{category, stat}]` as a map.
     *
     * 🚨 Values stay STRINGS. The feed mixes counts ("20"), ratios ("3-9"),
     * averages ("8.2") and clock times ("31:36") in one list, and a numeric
     * cast turns three of those four into a wrong number rather than an error —
     * `31:36` becomes 31, which reads as a plausible possession figure and is
     * not one.
     *
     * @return array<string, string>
     */
    protected function teamStats($stats): array
    {
        $out = [];

        foreach (is_array($stats) ? $stats : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $category = (string) ($entry['category'] ?? '');

            if ($category !== '') {
                $out[$category] = (string) ($entry['stat'] ?? '');
            }
        }

        return $out;
    }

    /**
     * The one player worth naming in each category.
     *
     * 🚨 The provider's shape is inside out for this: a category holds a list
     * of TYPES, and each type holds every athlete's figure for it, so a
     * quarterback's line is scattered across five lists rather than sitting
     * together. Pivoted here, once, because every reader would otherwise pivot
     * it again.
     *
     * 🚨 WHICH categories, and which figure decides each, comes from the
     * LEAGUE — it used to be a football-shaped constant here, and every other
     * sport's players silently vanished because no group was called "passing".
     * A basketball box score has one unnamed group and baseball has two named
     * from a different field; neither is a variation on football.
     *
     * @param array<string, string> $decidedBy group => the deciding figure
     * @return array<string, array{name: string, stats: array<string, string>}>
     */
    protected function leaders($categories, array $decidedBy): array
    {
        $out = [];

        foreach (is_array($categories) ? $categories : [] as $category) {
            if (!is_array($category)) {
                continue;
            }

            $name = (string) ($category['name'] ?? '');

            if (!isset($decidedBy[$name])) {
                continue;
            }

            $players = [];
            $order   = [];

            foreach ((array) ($category['types'] ?? []) as $type) {
                if (!is_array($type)) {
                    continue;
                }

                $figure = (string) ($type['name'] ?? '');

                foreach ((array) ($type['athletes'] ?? []) as $athlete) {
                    if (!is_array($athlete)) {
                        continue;
                    }

                    $who = (string) ($athlete['name'] ?? '');

                    if ($who === '') {
                        continue;
                    }

                    $players[$who][$figure] = (string) ($athlete['stat'] ?? '');

                    if (!isset($order[$who])) {
                        $order[$who] = count($order);
                    }
                }
            }

            if ($players === []) {
                continue;
            }

            $best      = null;
            $bestScore = null;

            foreach ($players as $who => $figures) {
                $score = (float) ($figures[$decidedBy[$name]] ?? 0);

                // Ties go to whoever the feed listed first, which is the order
                // it considers most notable.
                if ($bestScore === null || $score > $bestScore) {
                    $best      = $who;
                    $bestScore = $score;
                }
            }

            $out[$name] = ['name' => (string) $best, 'stats' => $players[$best]];
        }

        return $out;
    }
}
