<?php

namespace Resofire\Picks\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client as HttpClient;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Service\Providers\EspnProvider;
use Symfony\Component\Console\Input\InputOption;

/**
 * Fills in rank, record, venue and channel for fixtures that never had them.
 *
 * 🚨 This exists because the live poll can only ever see TODAY. It walks the
 * scoreboard for the current day, which is the right thing for a sync that runs
 * every minute and the wrong thing for a season that already has thirteen weeks
 * of fixtures in it — every game played before these columns existed, and every
 * game still to come, had nothing in them.
 *
 * 🚨 ONE REQUEST PER WEEK, not per day. `?dates=2026&seasontype=2&week=3`
 * answers a whole week — 99 games for week one — where walking the calendar a
 * day at a time would be a hundred and twenty requests at somebody else's
 * expense for the same answer. There is a hard ceiling below as well, because a
 * command that can be run by hand is a command that will eventually be run in a
 * loop.
 *
 * 🚨 The rank ESPN returns for a PAST game is the rank that side carried INTO
 * it, not today's — measured, not assumed: Alabama reads 13 in week one's
 * payload and 12 in this week's, on the same day. That single fact is what
 * makes backfilling honest. If the feed answered with today's poll, this
 * command could only ever write a plausible lie onto every finished game, and
 * it would not exist.
 */
class BackfillLeadInCommand extends AbstractCommand
{
    /**
     * 🚨 A ceiling on the whole run, not a pace.
     *
     * ESPN's scoreboard is public, unauthenticated and free, which is a reason
     * to be careful with it rather than a licence. A season is fifteen or so
     * weeks; anything asking for a hundred requests has been pointed at
     * something it should not have been, and stopping is the right answer.
     */
    private const MAX_REQUESTS = 40;

    /** Between requests, so a backfill is a trickle rather than a burst. */
    private const PAUSE_MICROSECONDS = 400000;

    private const BASE = 'https://site.api.espn.com/apis/site/v2/sports/football/college-football/scoreboard';

    private int $requests = 0;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected HttpClient $http
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('picks:backfill-lead-in')
            ->setDescription("Fill in each fixture's rank, record, venue and channel from the ESPN scoreboard.")
            ->addOption('season', null, InputOption::VALUE_REQUIRED, 'Season year. Defaults to the configured one.')
            ->addOption('weeks', null, InputOption::VALUE_REQUIRED, 'Weeks to walk: "1-15", "3", "1,2,7". Defaults to every week that has fixtures.')
            ->addOption('postseason', null, InputOption::VALUE_NONE, 'Walk the postseason instead of the regular season.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Rewrite fixtures that already carry a lead-in.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Say what would change and write nothing.');
    }

    protected function fire(): int
    {
        $year = (int) ($this->input->getOption('season') ?: $this->settings->get('ernestdefoe-picks.season_year', (int) date('Y')));
        $postseason = (bool) $this->input->getOption('postseason');
        $force = (bool) $this->input->getOption('force');
        $dry = (bool) $this->input->getOption('dry-run');

        $weeks = $this->weeks($year, $postseason);

        if ($weeks === []) {
            $this->error('No weeks with fixtures for ' . $year . '. Sync the schedule first.');

            return 1;
        }

        $this->info(sprintf(
            '%s %s %d, weeks %s.',
            $dry ? 'Would back-fill' : 'Backfilling',
            $postseason ? 'postseason' : 'regular season',
            $year,
            implode(', ', $weeks)
        ));

        $filled = 0;
        $skipped = 0;
        $unmatched = 0;

        foreach ($weeks as $week) {
            if ($this->requests >= self::MAX_REQUESTS) {
                $this->error('Stopped at ' . self::MAX_REQUESTS . ' requests. Narrow it with --weeks.');

                break;
            }

            $payload = $this->week($year, $week, $postseason);

            if ($payload === null) {
                $this->error('  week ' . $week . ': the feed did not answer.');

                continue;
            }

            $events = (array) ($payload['events'] ?? []);

            /*
             * 🚨 Matched on `cfbd_id`, which on this board holds the ESPN event
             * id — the same join the live poll uses. A fixture the feed has no
             * row for is counted and left alone rather than guessed at from the
             * team names, which is how two different games between the same two
             * sides end up sharing a rank.
             */
            $ids = [];

            foreach ($events as $event) {
                if (isset($event['id'])) {
                    $ids[] = (int) $event['id'];
                }
            }

            $rows = PickEvent::query()->whereIn('cfbd_id', $ids)->get()->keyBy('cfbd_id');

            $weekFilled = 0;

            foreach ($events as $event) {
                $row = $rows->get((int) ($event['id'] ?? 0));

                if ($row === null) {
                    $unmatched++;

                    continue;
                }

                if (! $force && trim((string) $row->venue) !== '') {
                    $skipped++;

                    continue;
                }

                $this->apply($row, (array) (($event['competitions'] ?? [[]])[0] ?? []));

                if (! $row->isDirty()) {
                    $skipped++;

                    continue;
                }

                if (! $dry) {
                    $row->save();
                }

                $filled++;
                $weekFilled++;
            }

            $this->info(sprintf('  week %-3s %3d games, %3d filled.', $week, count($events), $weekFilled));
        }

        $this->info(sprintf(
            '%s %d fixtures. %d already had one, %d in the feed matched nothing here.',
            $dry ? 'Would have filled' : 'Filled',
            $filled,
            $skipped,
            $unmatched
        ));

        return 0;
    }

    /**
     * Put one competition's lead-in onto a fixture.
     *
     * @param array<string, mixed> $competition
     */
    private function apply(PickEvent $row, array $competition): void
    {
        $home = $this->competitor($competition, 'home');
        $away = $this->competitor($competition, 'away');

        $row->home_rank = EspnProvider::rank($home);
        $row->away_rank = EspnProvider::rank($away);
        $row->venue = trim((string) (((array) ($competition['venue'] ?? []))['fullName'] ?? ''));
        $row->venue_city = EspnProvider::venueCity((array) ($competition['venue'] ?? []));
        $row->broadcast = EspnProvider::broadcast($competition);

        $finished = $row->status === PickEvent::STATUS_FINISHED;

        $row->home_record = $this->record($home, $finished, $row->result === PickEvent::RESULT_HOME, $row->result);
        $row->away_record = $this->record($away, $finished, $row->result === PickEvent::RESULT_AWAY, $row->result);
    }

    /**
     * A side's record as it was BEFORE this game.
     *
     * 🚨 The feed's record for a past game includes that game, so for a
     * finished fixture the result is subtracted back out — see
     * `EspnProvider::recordBefore`, where the arithmetic and its refusals live.
     * A game with no decided result cannot be unwound and gets nothing, which
     * is the honest answer.
     *
     * @param array<string, mixed> $side
     */
    private function record(array $side, bool $finished, bool $won, ?string $result): string
    {
        $record = EspnProvider::record($side);

        if (! $finished || $record === '') {
            return $record;
        }

        if ($result !== PickEvent::RESULT_HOME && $result !== PickEvent::RESULT_AWAY) {
            return '';
        }

        return EspnProvider::recordBefore($record, $won);
    }

    /**
     * @param  array<string, mixed> $competition
     * @return array<string, mixed>
     */
    private function competitor(array $competition, string $side): array
    {
        foreach ((array) ($competition['competitors'] ?? []) as $competitor) {
            if (is_array($competitor) && ($competitor['homeAway'] ?? '') === $side) {
                return $competitor;
            }
        }

        return [];
    }

    /**
     * Which weeks to walk.
     *
     * 🚨 Read from the DATABASE by default rather than counted to fifteen. A
     * board syncs the weeks its own calendar has, and asking the feed for a
     * week this season does not contain is a request that answers with today's
     * games — see EspnProvider, where the same trap is documented for the
     * fixture sync.
     *
     * @return list<int>
     */
    private function weeks(int $year, bool $postseason): array
    {
        $given = trim((string) $this->input->getOption('weeks'));

        if ($given !== '') {
            return $this->parseWeeks($given);
        }

        return \Resofire\Picks\Week::query()
            ->where('season_type', $postseason ? 'postseason' : 'regular')
            ->whereHas('season', fn ($q) => $q->where('year', $year))
            ->whereHas('events')
            ->orderBy('week_number')
            ->pluck('week_number')
            ->map(fn ($n) => (int) $n)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function parseWeeks(string $given): array
    {
        $out = [];

        foreach (explode(',', $given) as $part) {
            $part = trim($part);

            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                foreach (range((int) $m[1], (int) $m[2]) as $n) {
                    $out[] = $n;
                }

                continue;
            }

            if ($part !== '' && ctype_digit($part)) {
                $out[] = (int) $part;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, mixed>|null */
    private function week(int $year, int $week, bool $postseason): ?array
    {
        $this->requests++;

        if ($this->requests > 1) {
            usleep(self::PAUSE_MICROSECONDS);
        }

        $url = self::BASE . '?' . http_build_query([
            // 80 is FBS; the limit covers the biggest Saturday in the sport.
            'groups' => 80,
            'limit' => 300,
            'dates' => $year,
            'seasontype' => $postseason ? 3 : 2,
            'week' => $week,
        ]);

        try {
            /*
             * 🚨 NO User-Agent override, for the reason documented at length in
             * SyncScoresService: a custom agent gets a 403 from this endpoint
             * while curl's and Guzzle's own defaults get a 200. Sending nothing
             * is both the honest thing and the thing that works.
             */
            $response = $this->http->request('GET', $url, ['timeout' => 20, 'http_errors' => false]);
        } catch (\Throwable $e) {
            $this->error('  ' . $e->getMessage());

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            // The status, always. A bare failure here reads as "ESPN is down".
            $this->error('  HTTP ' . $response->getStatusCode() . ' for week ' . $week);

            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function error(string $line): void
    {
        $this->output->writeln('<error>' . $line . '</error>');
    }
}
