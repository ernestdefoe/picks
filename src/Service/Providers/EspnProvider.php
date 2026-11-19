<?php

namespace Resofire\Picks\Service\Providers;

use Flarum\Locale\TranslatorInterface;
use GuzzleHttp\Client as HttpClient;
use Resofire\Picks\Service\Leagues\League;
use Resofire\Picks\Service\Providers\Espn\BoxScoreParser;
use Resofire\Picks\Service\Providers\Espn\FixtureParser;
use RuntimeException;

/**
 * ESPN — the NFL, the NBA, MLB, the NHL and league football, in one shape.
 *
 * 🚨 The single most useful fact about this API: every sport answers the same
 * two endpoints with the same envelope. A scoreboard is
 * `{events: [{id, date, competitions: [{competitors: [{homeAway, score, team}], status}]}]}`
 * whether it is the Premier League or MLB, which is why one adapter covers
 * every league here and why adding another is a line in the registry.
 *
 * 🚨 It needs NO API KEY, which is the other reason it is the multi-sport
 * backbone. CollegeFootballData stays where it is used because it is richer for
 * college football, not because ESPN could not answer.
 *
 * Everything below was read off live responses in September 2026 rather than
 * from documentation, because ESPN publishes none for this.
 */
class EspnProvider implements Provider
{
    protected const BASE = 'https://site.api.espn.com/apis/site/v2/sports';
    protected const TIMEOUT = 20;

    /**
     * 🚨 A hard ceiling on summary fetches per run, because a box score here is
     * ONE CALL PER GAME — unlike CollegeFootballData, which answers a whole
     * week at once. A full MLB day is fifteen games and a Saturday of college
     * basketball is a hundred and fifty; without this, a single scheduled job
     * would fire a hundred and fifty outbound requests inside a minute. That
     * has caused a real outage on this stack before.
     *
     * Whatever is not fetched this run is fetched the next one. A box score
     * arriving an hour later is invisible; a queue worker taken out by its own
     * traffic is not.
     */
    public const MAX_SUMMARIES_PER_RUN = 25;

    /** @var array<string, array<string, mixed>> summary responses already fetched this process */
    protected array $summaries = [];

    protected int $fetched = 0;

    public function __construct(protected HttpClient $http, protected TranslatorInterface $translator)
    {
    }

    public function key(): string
    {
        return 'espn';
    }

    public function supports(League $league): bool
    {
        return $league->espnPath !== '';
    }

    public function games(League $league, int $year, ?int $week = null, string $seasonType = 'regular'): array
    {
        if (! $this->supports($league)) {
            return [];
        }

        $params = ['limit' => 1000];

        /*
         * 🚨 Weeks and dates are not interchangeable, and asking for the wrong
         * one returns TODAY rather than an error. ESPN understands `week` for
         * the sports that have them and silently ignores it for the rest — so a
         * basketball season asked for "week 3" would answer with tonight's
         * games, and the sync would happily store them as week 3.
         */
        if ($league->hasWeeks && $week !== null) {
            $params['week'] = $week;
            $params['seasontype'] = $seasonType === 'postseason' ? 3 : 2;
            $params['dates'] = $year;
        } else {
            $params['dates'] = $year;
        }

        $response = $this->get($league->espnPath.'/scoreboard', $params);

        $games = [];

        foreach ((array) ($response['events'] ?? []) as $event) {
            $game = FixtureParser::game($event, $league);

            if ($game !== null) {
                $games[] = $game;
            }
        }

        return $games;
    }

    public function boxScore(League $league, string $externalId, int $year, ?int $week = null, string $seasonType = 'regular'): ?array
    {
        if (! $this->supports($league) || $externalId === '') {
            return null;
        }

        if ($this->fetched >= self::MAX_SUMMARIES_PER_RUN && ! isset($this->summaries[$externalId])) {
            return null;
        }

        $summary = $this->summary($league, $externalId);

        if ($summary === null) {
            return null;
        }

        $box = (array) ($summary['boxscore'] ?? []);
        $teams = BoxScoreParser::teamSides((array) ($box['teams'] ?? []));

        // No team statistics is no box score. Half of one is worse than none.
        if (count($teams) < 2) {
            return null;
        }

        /*
         * 🚨 The quarter scores, the scoring plays and the market come out of
         * the SAME response, at no extra cost.
         *
         * This endpoint is already one request per finished game, and it was
         * answering with all of this while only the two statistics blocks were
         * being read. A recap that wants to say how a game was won needs the
         * scoring plays; fetching them separately would have doubled the
         * traffic for something already on the wire.
         */
        $competition = (array) (((array) (($summary['header'] ?? [])['competitions'] ?? [[]]))[0] ?? []);

        return [
            'teams' => $teams,
            'players' => BoxScoreParser::playerSides((array) ($box['players'] ?? []), (array) ($box['teams'] ?? [])),
            'linescores' => BoxScoreParser::lineScores($competition),
            'scoring' => BoxScoreParser::scoringPlays((array) ($summary['scoringPlays'] ?? [])),
            'market' => BoxScoreParser::market((array) ($summary['pickcenter'] ?? [])),
            'swing' => BoxScoreParser::biggestSwing(
                (array) ($summary['winprobability'] ?? []),
                (array) ($summary['drives'] ?? [])
            ),
            'neutral' => (bool) ($competition['neutralSite'] ?? false),
        ];
    }

    /**
     * "2nd & 10", from whichever the feed happened to send.
     *
     * 🚨 The text is not always there. ESPN's situation block changes shape
     * through a game — between possessions it can carry `down` and `distance`
     * as bare numbers with no sentence built from them, and after a score it
     * carries `down = 0`, which means there is no down rather than a zeroth one.
     *
     * @param array<string, mixed> $situation
     */
    public static function downAndDistance(array $situation): string
    {
        $text = trim((string) ($situation['shortDownDistanceText'] ?? ''));

        /*
         * 🚨 A negative distance is refused, not printed.
         *
         * Caught live on the Convoro board: ESPN sent "4th & -1" for a minute
         * either side of the half and it went straight onto the scoreboard. A
         * board reading "4th & -1" is not a board with a small mistake on it,
         * it is one nobody trusts again — and a feed briefly disagreeing with
         * itself is an ordinary event, not an exceptional one.
         *
         * Showing nothing is the honest answer meanwhile; the score and clock
         * beside it are unaffected.
         */
        if ($text !== '' && ! preg_match('/-\s*\d/', $text)) {
            return $text;
        }

        $down = (int) ($situation['down'] ?? 0);

        if ($down < 1 || $down > 4) {
            return '';
        }

        $distance = (int) ($situation['distance'] ?? 0);
        $ordinal = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'][$down];

        // "& Goal" is what a scoreboard says when the distance IS the end zone.
        return $distance > 0 ? $ordinal.' & '.$distance : $ordinal.' & Goal';
    }

    /**
     * Where a team sits in the poll, or 0 for outside it.
     *
     * 🚨 99 IS THE FEED'S WORD FOR UNRANKED, and it is the only trap in this
     * whole block. Every competitor carries a `curatedRank`, so a reader that
     * takes the number at face value puts "#99" beside two thirds of the teams
     * playing on any given Saturday — a board that looks like it is counting
     * something and is not. It is turned into 0 here, once, so nothing
     * downstream has to know that 99 ever meant anything.
     *
     * 🚨 "Curated" rather than AP, and the distinction is ESPN's rather than
     * ours: in college football it is the AP poll until the selection committee
     * publishes, and the committee's ranking after that. Which is exactly what
     * a scoreboard should show — once the CFP rankings exist, they are the ones
     * every broadcast puts beside a team's name.
     *
     * @param array<string, mixed> $side
     */
    public static function rank(array $side): int
    {
        /*
         * 🚨 The lookup is bracketed, not cast-then-defaulted. `(int) $a['k'] ?? 0`
         * binds the CAST first, so a missing key warns and is only then
         * defaulted — a notice in the log on every fixture the feed sends
         * without a rank block, which is most of them out of season.
         */
        $curated = (array) ($side['curatedRank'] ?? []);
        $rank = (int) ($curated['current'] ?? 0);

        // Anything outside a 25-team poll is unranked, whatever number the feed
        // used to say so.
        return $rank > 0 && $rank <= 25 ? $rank : 0;
    }

    /**
     * A team's record — "2-0", "7-4-1" — as the feed writes it.
     *
     * 🚨 The OVERALL one, picked by type rather than by position. The array also
     * carries home, road and conference records, and taking the first would be
     * right until the day the feed reordered them and every board quietly began
     * showing road records instead.
     *
     * @param array<string, mixed> $side
     */
    public static function record(array $side): string
    {
        foreach ((array) ($side['records'] ?? []) as $record) {
            if (is_array($record) && (($record['type'] ?? '') === 'total' || ($record['name'] ?? '') === 'overall')) {
                return trim((string) ($record['summary'] ?? ''));
            }
        }

        return '';
    }

    /**
     * A record with one game taken back out of it.
     *
     * 🚨 The feed's record for a PAST game already includes that game — Ball
     * State read 0-1 the day they lost their opener, not 0-0 — and everything
     * that draws these columns says "what they brought in". A board showing the
     * loser of the game you are looking at already carrying the loss is not
     * inconsistent in some abstract way; it has given away the result above the
     * result.
     *
     * So the outcome is subtracted back out. Exact arithmetic on a result this
     * database already holds, not an estimate.
     *
     * 🚨 It REFUSES rather than guesses. An unparseable record or a subtraction
     * that would go negative yields an empty string, because a board with no
     * record on it reads as a board that does not have one — which is true — and
     * a wrong record reads as a fact.
     */
    public static function recordBefore(string $record, bool $won): string
    {
        if (! preg_match('/^(\d+)\s*-\s*(\d+)(.*)$/', trim($record), $m)) {
            return '';
        }

        $wins = (int) $m[1] - ($won ? 1 : 0);
        $losses = (int) $m[2] - ($won ? 0 : 1);

        if ($wins < 0 || $losses < 0) {
            return '';
        }

        // Whatever trailed it — a ties column — is carried through untouched.
        return $wins.'-'.$losses.$m[3];
    }

    /**
     * "College Station, TX" — or as much of it as the feed sent.
     *
     * 🚨 Composed from the parts that are actually there. The address block
     * routinely arrives with a city and no state (and, abroad, a country
     * instead), so a format string would print "London, " with a comma hanging
     * off the end of it on a fixture that is not missing anything at all.
     *
     * @param array<string, mixed> $venue
     */
    public static function venueCity(array $venue): string
    {
        $address = (array) ($venue['address'] ?? []);

        $parts = array_values(array_filter([
            trim((string) ($address['city'] ?? '')),
            trim((string) ($address['state'] ?? $address['country'] ?? '')),
        ], fn (string $part): bool => $part !== ''));

        return implode(', ', $parts);
    }

    /**
     * Who is showing it.
     *
     * 🚨 The NATIONAL listing, where there is one. `broadcasts` also carries
     * per-market entries — the home team's regional channel, the away team's —
     * and a reader that took the first would tell everybody on the board to
     * watch a station most of them cannot receive.
     *
     * @param array<string, mixed> $competition
     */
    public static function broadcast(array $competition): string
    {
        $names = [];

        foreach ((array) ($competition['broadcasts'] ?? []) as $broadcast) {
            if (! is_array($broadcast)) {
                continue;
            }

            $name = trim((string) (((array) ($broadcast['names'] ?? []))[0] ?? ''));

            if ($name === '') {
                continue;
            }

            if (($broadcast['market'] ?? '') === 'national') {
                return $name;
            }

            $names[] = $name;
        }

        // No national listing: the first regional one is better than silence,
        // and on a board built around one team it is usually the right channel.
        return $names[0] ?? '';
    }

    /**
     * Every listing for a game, and the feed's own watch link when there is one.
     *
     * 🚨 `geoBroadcasts` first, because it is the only shape that says WHAT a
     * listing is: `type.shortName` is TV, Streaming or Radio and `market.type`
     * is National, Home or Away. `broadcasts` carries the market and the names
     * and nothing about the medium, so it is the fallback, read as television.
     *
     * 🚨 Case-folded on the way in. College football sends "Streaming", MLS
     * sends "STREAMING" — matched as written, every MLS stream would arrive as
     * an unknown medium.
     *
     * 🚨 The watch link is taken only from ESPN's own host, and only over
     * HTTPS. It is the one value here that becomes something a reader clicks,
     * and a feed is not a reason to send people anywhere it names. It appears
     * on the EVENT's links while a game is on (rel `live`/`watch`) and is
     * absent before kickoff, which is why PickEvent keeps the last one it saw.
     *
     * @param  array<string, mixed> $event  the whole event, for its links
     * @return array{listings: list<array{name: string, type: string, market: string}>, watch: string, premium: bool}
     */
    public static function broadcasts(array $event): array
    {
        $competition = (array) (($event['competitions'] ?? [[]])[0] ?? []);
        $listings = [];

        $add = function (string $name, string $type, string $market) use (&$listings): void {
            $name = trim($name);

            if ($name === '') {
                return;
            }

            $type = match (strtolower(trim($type))) {
                'streaming', 'stream' => 'streaming',
                'radio' => 'radio',
                default => 'tv',
            };

            $market = match (strtolower(trim($market))) {
                'home' => 'home',
                'away' => 'away',
                default => 'national',
            };

            // One chip per channel per market: the two shapes overlap.
            $listings[strtolower($name).'|'.$market] ??= ['name' => $name, 'type' => $type, 'market' => $market];
        };

        foreach ((array) ($competition['geoBroadcasts'] ?? []) as $geo) {
            if (! is_array($geo)) {
                continue;
            }

            $add(
                (string) (((array) ($geo['media'] ?? []))['shortName'] ?? ''),
                (string) (((array) ($geo['type'] ?? []))['shortName'] ?? ''),
                (string) (((array) ($geo['market'] ?? []))['type'] ?? '')
            );
        }

        if ($listings === []) {
            foreach ((array) ($competition['broadcasts'] ?? []) as $broadcast) {
                if (! is_array($broadcast)) {
                    continue;
                }

                foreach ((array) ($broadcast['names'] ?? []) as $name) {
                    $add((string) $name, 'tv', (string) ($broadcast['market'] ?? ''));
                }
            }
        }

        $watch = '';
        $premium = false;

        foreach ((array) ($event['links'] ?? []) as $link) {
            if (! is_array($link)) {
                continue;
            }

            $rel = array_map('strval', (array) ($link['rel'] ?? []));
            $href = trim((string) ($link['href'] ?? ''));

            if (! array_intersect($rel, ['live', 'watch', 'watchespn']) || ! self::isEspnWatchUrl($href)) {
                continue;
            }

            $watch = $href;
            $premium = ! empty($link['isPremium']);
            break;
        }

        return ['listings' => array_values($listings), 'watch' => $watch, 'premium' => $premium];
    }

    /**
     * A finished game's highlight clips, straight from its summary.
     *
     * One request, made only when asked — see Service\GameHighlights for who
     * asks and how often. Null when the summary could not be fetched, which is
     * different from an empty list: an empty list is "nothing published yet".
     *
     * @return list<array{id: string, title: string, duration: int, image: string, published: string}>|null
     */
    public function highlights(League $league, string $eventId, int $max = 6): ?array
    {
        if (! $this->supports($league) || ! ctype_digit($eventId)) {
            return null;
        }

        $summary = $this->summary($league, $eventId);

        return $summary === null ? null : self::parseHighlights($summary, $max);
    }

    /**
     * The clips worth showing, best first.
     *
     * 🚨 The id must be DIGITS and nothing else. It is the only part of a clip
     * the page builds a player from — `espn.com/core/video/iframe/_/id/{id}/`
     * — so a value that is anything other than a number never leaves here.
     *
     * 🚨 The full-game package first, then the plays in the order they
     * happened. ESPN lists the package first on a final today, but a list
     * scraped in the hours after a game is in publication order, and a
     * one-yard touchdown leading the strip over "Full Highlights" reads as a
     * strip that picked at random.
     *
     * A clip still under embargo, or whitelisted away from desktop players, is
     * left out: the syndicated player would only show an error for it.
     *
     * @param  array<string, mixed> $summary
     * @return list<array{id: string, title: string, duration: int, image: string, published: string}>
     */
    public static function parseHighlights(array $summary, int $max = 6, ?int $now = null): array
    {
        $now ??= time();
        $full = [];
        $plays = [];

        foreach ((array) ($summary['videos'] ?? []) as $video) {
            if (! is_array($video)) {
                continue;
            }

            $id = (string) ($video['id'] ?? '');

            if ($id === '' || ! ctype_digit($id)) {
                continue;
            }

            $embargo = strtotime((string) (((array) ($video['timeRestrictions'] ?? []))['embargoDate'] ?? '')) ?: 0;

            if ($embargo > $now) {
                continue;
            }

            $devices = (array) ($video['deviceRestrictions'] ?? []);

            if (($devices['type'] ?? '') === 'whitelist' && ! in_array('desktop', (array) ($devices['devices'] ?? []), true)) {
                continue;
            }

            $image = trim((string) ($video['thumbnail'] ?? ''));

            $clip = [
                'id' => $id,
                'title' => trim((string) ($video['headline'] ?? '')),
                'duration' => max(0, (int) ($video['duration'] ?? 0)),
                // Only an https image; anything else is no image, not a broken one.
                'image' => str_starts_with($image, 'https://') ? $image : '',
                'published' => (string) ($video['originalPublishDate'] ?? ''),
            ];

            $coverage = strtolower((string) (((array) ($video['tracking'] ?? []))['coverageType'] ?? ''));

            if (str_contains($coverage, 'final game highlight') || preg_match('/\bfull highlights\b/i', $clip['title'])) {
                $full[] = $clip;
            } else {
                $plays[] = $clip;
            }
        }

        usort($plays, fn (array $a, array $b): int => strcmp($a['published'], $b['published']));

        return array_slice(array_merge($full, $plays), 0, max(0, $max));
    }

    /** An https link on espn.com (or a subdomain of it) with /watch in its path. */
    public static function isEspnWatchUrl(string $href): bool
    {
        $parts = parse_url($href);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($host === 'espn.com' || str_ends_with($host, '.espn.com'))
            && str_contains((string) ($parts['path'] ?? ''), '/watch');
    }

    /* -------------------------------------------------------------- the wire */

    /** @return array<string, mixed>|null */
    protected function summary(League $league, string $eventId): ?array
    {
        if (isset($this->summaries[$eventId])) {
            return $this->summaries[$eventId];
        }

        $this->fetched++;

        try {
            $summary = $this->get($league->espnPath.'/summary', ['event' => $eventId]);
        } catch (RuntimeException) {
            /*
             * 🚨 Swallowed on purpose, and only here. A game whose summary is
             * missing or malformed is one thread without statistics; letting it
             * out would take down the whole run, and every other game in it.
             */
            return null;
        }

        return $this->summaries[$eventId] = $summary;
    }

    /** @return array<string, mixed> */
    protected function get(string $path, array $params): array
    {
        $response = $this->http->get(self::BASE.'/'.ltrim($path, '/'), [
            'query' => $params,
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-picks.api.errors.espn_http', ['status' => $response->getStatusCode(), 'path' => $path]));
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded)) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-picks.api.errors.espn_bad_json', ['path' => $path]));
        }

        return $decoded;
    }
}
