<?php

namespace Resofire\Picks\Service;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Season;
use Resofire\Picks\Service\Leagues\League;
use Resofire\Picks\Service\Providers\EspnProvider;
use Resofire\Picks\Team;
use Resofire\Picks\Week;

/**
 * Fixtures and scores for every league that is not college football.
 *
 * 🚨 Deliberately a SECOND service rather than a rewrite of
 * `ScheduleSyncService`. That one is CollegeFootballData-shaped down to its
 * bones — teams keyed by `cfbd_id`, weeks read from a calendar endpoint,
 * conference filters — and every existing install depends on it working
 * exactly as it does. Generalising it would put a season of somebody's picks
 * behind a refactor nobody asked for, to make one code path serve two feeds
 * that genuinely disagree about what a season looks like.
 *
 * The two meet where it matters: the same tables, the same normalised box
 * score, the same recap.
 */
class EspnSyncService
{
    public function __construct(
        protected EspnProvider $espn
    ) {
    }

    /**
     * Bring one season's fixtures up to date.
     *
     * @return array{teams: int, weeks: int, created: int, updated: int, skipped: int}
     */
    public function sync(Season $season): array
    {
        $league = $season->leagueDefinition();

        $summary = ['teams' => 0, 'weeks' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        if (!$this->espn->supports($league)) {
            return $summary;
        }

        $games = $this->espn->games($league, $season->year);

        if ($games === []) {
            return $summary;
        }

        /*
         * 🚨 Read once, into memory, before the loop. A season is several
         * hundred games and the naive version fires two lookups per game — a
         * team by name and an event by id — which is the shape that made the
         * college sync slow enough to notice.
         */
        $teams = Team::query()->get()->keyBy(fn (Team $team) => $this->slug($team->name ?? ''));
        $events = PickEvent::query()
            ->whereNotNull('external_id')
            ->get()
            ->keyBy('external_id');

        $weeks = [];

        foreach ($games as $game) {
            if ($game['external_id'] === '' || $game['home'] === '' || $game['away'] === '') {
                $summary['skipped']++;
                continue;
            }

            $home = $this->team($teams, $game['home'], $game['home_team'] ?? [], $summary);
            $away = $this->team($teams, $game['away'], $game['away_team'] ?? [], $summary);

            $start = $this->when($game['start']);

            if ($start === null) {
                $summary['skipped']++;
                continue;
            }

            $week = $this->week($season, $league, $game, $start, $weeks, $summary);

            $event = $events->get($game['external_id']);

            $attributes = [
                'week_id' => $week->id,
                'home_team_id' => $home->id,
                'away_team_id' => $away->id,
                'external_id' => $game['external_id'],
                'neutral_site' => $game['neutral_site'],
                'match_date' => $start,
                /*
                 * 🚨 The deadline is kickoff, not the start of the week. In a
                 * league played across seven days, one deadline for the whole
                 * round would either close Monday's game on Saturday morning or
                 * leave Saturday's open until Monday night — and the second of
                 * those lets somebody pick a game they have already watched.
                 */
                'cutoff_date' => $start,
                'status' => $this->status($game),
                'home_score' => $game['home_score'],
                'away_score' => $game['away_score'],
                'result' => $this->result($game),
            ] + $this->liveState($game) + $this->leadIn($game);

            if ($event === null) {
                PickEvent::query()->create($attributes);
                $summary['created']++;
                continue;
            }

            /*
             * 🚨 A finished game is never rewritten. The scoreboard keeps
             * answering for weeks afterwards, and a provider correction that
             * flipped a result after picks were scored would leave the standings
             * disagreeing with the game everybody watched. A correction is a
             * moderator's job, not a scheduled job's.
             */
            if ($event->status === 'finished') {
                $summary['skipped']++;
                continue;
            }

            $event->fill($attributes)->save();
            $summary['updated']++;
        }

        return $summary;
    }

    /* --------------------------------------------------------------- teams */

    /**
     * @param \Illuminate\Support\Collection<string, Team> $teams
     * @param array<string, int>                          $summary
     */
    protected function team($teams, string $name, array $club, array &$summary): Team
    {
        $slug = $this->slug($name);

        $crest = [
            'espn_id' => ($club['external_id'] ?? '') !== '' ? (int) $club['external_id'] : null,
            'abbreviation' => (string) ($club['abbreviation'] ?? ''),
            'logo_path' => (string) ($club['logo'] ?? ''),
        ];

        if ($teams->has($slug)) {
            $team = $teams->get($slug);

            /*
             * 🚨 A crest an operator chose is never overwritten. `logo_custom`
             * is the only way to keep a hand-picked one through a sync, and a
             * sync that ignored it would undo the same piece of work every hour.
             */
            if ($crest['logo_path'] !== '' && !$team->logo_custom && (string) $team->logo_path === '') {
                $team->logo_path = $crest['logo_path'];
                $team->espn_id = $crest['espn_id'];
                $team->save();
            }

            return $team;
        }

        /*
         * 🚨 Created rather than skipped. ESPN answers no separate team list
         * for most of these leagues, and a fixture whose clubs are not already
         * in the database is the ordinary case on the first sync — not an
         * error. The alternative is a season that imports nothing and says
         * nothing about why.
         *
         * 🚨 The crest comes off the FIXTURE, which is the whole reason it is
         * carried there. A pick'em whose teams have no logo is a board of grey
         * squares — the first version of this shipped exactly that, and it
         * looked broken rather than unfinished.
         */
        $team = Team::query()->create([
            'name' => $name,
            'slug' => $slug,
            'abbreviation' => $crest['abbreviation'] !== ''
                ? $crest['abbreviation']
                : Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', $name) ?? '', 0, 4)),
            'espn_id' => $crest['espn_id'],
            'logo_path' => $crest['logo_path'] ?: null,
        ]);

        $teams->put($slug, $team);
        $summary['teams']++;

        return $team;
    }

    protected function slug(string $name): string
    {
        return Str::slug($name);
    }

    /* --------------------------------------------------------------- weeks */

    /**
     * The week a game belongs to.
     *
     * 🚨 Most sports have no weeks, and this is where that is dealt with.
     * Gridiron numbers its rounds and everything else is played to a date, but
     * the whole model here — a pick deadline, a leaderboard, "this week's
     * games" — is built on weeks existing. So a league without them gets one
     * week per calendar week, which is what a pick'em for those sports is
     * anyway: you pick this week's games.
     *
     * @param array<string, Week>  $weeks
     * @param array<string, int>   $summary
     */
    protected function week(Season $season, League $league, array $game, Carbon $start, array &$weeks, array &$summary): Week
    {
        if ($league->hasWeeks && $game['week'] !== null) {
            $number = (int) $game['week'];
            $type = $game['season_type'];
            $name = $type === 'postseason' ? 'Postseason' : 'Week ' . $number;
        } else {
            /*
             * 🚨 ISO weeks, so a week begins on Monday and a Sunday game lands
             * in the week it was played rather than opening the next one. Every
             * league here plays across a weekend, and a Sunday-starting week
             * would split every one of them in half.
             */
            $number = (int) $start->isoWeek();
            $type = $game['season_type'];
            $name = 'Week of ' . $start->copy()->startOfWeek()->format('j M');
        }

        $key = $season->id . ':' . $type . ':' . $number;

        if (isset($weeks[$key])) {
            return $weeks[$key];
        }

        $week = Week::query()
            ->where('season_id', $season->id)
            ->where('season_type', $type)
            ->where('week_number', $number)
            ->first();

        if ($week === null) {
            $week = Week::query()->create([
                'season_id' => $season->id,
                'name' => $name,
                'week_number' => $number,
                'season_type' => $type,
                'start_date' => $start->copy()->startOfWeek()->toDateString(),
                'end_date' => $start->copy()->endOfWeek()->toDateString(),
                'is_open' => true,
            ]);

            $summary['weeks']++;
        }

        return $weeks[$key] = $week;
    }

    /* -------------------------------------------------------------- values */

    protected function when(string $date): ?Carbon
    {
        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function status(array $game): string
    {
        return match (true) {
            (bool) $game['completed'] => 'finished',
            $game['status'] === 'in' => 'in_progress',
            default => 'scheduled',
        };
    }

    /**
     * The in-play state, for anything drawing a scoreboard.
     *
     * 🚨 `clock_at` is stamped HERE rather than defaulted in the database,
     * because it has to mean "when this clock was true", not "when this row was
     * last touched". A fixture edited by hand three days later must not make a
     * three-day-old game clock look like it arrived a second ago.
     *
     * 🚨 Only stamped when a clock actually came back. A provider that answers
     * a fixture with no status block would otherwise refresh the timestamp on
     * stale numbers every sync, and the freshness check downstream — the whole
     * reason the timestamp exists — would never fire.
     *
     * @param  array<string, mixed> $game
     * @return array<string, mixed>
     */
    protected function liveState(array $game): array
    {
        $clock = trim((string) ($game['clock'] ?? ''));
        $period = (int) ($game['period'] ?? 0);

        return [
            'period' => $period,
            'clock' => $clock,
            'clock_detail' => (string) ($game['clock_detail'] ?? ''),
            'clock_at' => ($clock !== '' || $period > 0) ? time() : 0,
            'possession' => in_array($game['possession'] ?? '', ['home', 'away'], true)
                ? (string) $game['possession']
                : '',
            'down_distance' => (string) ($game['down_distance'] ?? ''),
            'ball_on' => (string) ($game['ball_on'] ?? ''),
            'red_zone' => (bool) ($game['red_zone'] ?? false),
        ];
    }

    /**
     * The lead-in: who is ranked, what each side has done, where, and on what.
     *
     * 🚨 Separate from `liveState` because it ages in the opposite direction.
     * A clock is worthless a minute later and these are worth most before a ball
     * is thrown — and unlike the clock, they are still true a year afterwards,
     * which is the reason the rank is frozen onto the fixture at all.
     *
     * @param  array<string, mixed> $game
     * @return array<string, mixed>
     */
    protected function leadIn(array $game): array
    {
        return [
            'home_rank' => (int) ($game['home_rank'] ?? 0),
            'away_rank' => (int) ($game['away_rank'] ?? 0),
            'home_record' => (string) ($game['home_record'] ?? ''),
            'away_record' => (string) ($game['away_record'] ?? ''),
            'venue' => (string) ($game['venue'] ?? ''),
            'venue_city' => (string) ($game['venue_city'] ?? ''),
            'broadcast' => (string) ($game['broadcast'] ?? ''),
        ];
    }

    protected function result(array $game): ?string
    {
        if (!$game['completed'] || $game['home_score'] === null || $game['away_score'] === null) {
            return null;
        }

        return match (true) {
            $game['home_score'] > $game['away_score'] => 'home',
            $game['home_score'] < $game['away_score'] => 'away',
            /*
             * 🚨 A draw is a real result in football and this column has never
             * had to hold one. Storing it as a null result would score every
             * pick on a drawn match as wrong, which is not what anybody picked.
             */
            default => 'draw',
        };
    }
}
