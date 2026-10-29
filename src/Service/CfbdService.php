<?php

namespace Resofire\Picks\Service;

use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use RuntimeException;

class CfbdService
{
    protected const BASE_URL = 'https://api.collegefootballdata.com';
    protected const TIMEOUT = 30;

    /** How long every CFBD call waits after a 429 that names no Retry-After. */
    protected const PAUSE_SECONDS = 21600;

    protected const PAUSE_KEY = 'picks.cfbd.paused_until';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected HttpClient $http,
        protected TranslatorInterface $translator,
        protected ?Cache $cache = null
    ) {
    }

    /**
     * Fetch all FBS teams, optionally filtered by conference.
     *
     * @throws RuntimeException
     */
    public function fetchTeams(): array
    {
        $apiKey = $this->settings->get('ernestdefoe-picks.cfbd_api_key');

        if (empty($apiKey)) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-picks.api.errors.cfbd_no_key'));
        }

        $params = ['classification' => 'fbs'];

        $conferenceFilter = trim((string) $this->settings->get('ernestdefoe-picks.conference_filter', ''));
        if ($conferenceFilter !== '') {
            $params['conference'] = $conferenceFilter;
        }

        return $this->request('/teams', $params, $apiKey);
    }

    /**
     * Fetch games for a given year and season type.
     *
     * @throws RuntimeException
     */
    public function fetchGames(int $year, string $seasonType, ?int $week = null, ?string $conference = null): array
    {
        $apiKey = $this->settings->get('ernestdefoe-picks.cfbd_api_key');

        if (empty($apiKey)) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-picks.api.errors.cfbd_no_key'));
        }

        $params = [
            'year' => $year,
            'seasonType' => $seasonType,
            'classification' => 'fbs',
        ];

        if ($week !== null) {
            $params['week'] = $week;
        }

        if ($conference !== null) {
            $params['conference'] = $conference;
        }

        return $this->request('/games', $params, $apiKey);
    }

    /**
     * Fetch the season calendar (week definitions) for a given year.
     *
     * @throws RuntimeException
     */
    public function fetchCalendar(int $year): array
    {
        $apiKey = $this->settings->get('ernestdefoe-picks.cfbd_api_key');

        if (empty($apiKey)) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-picks.api.errors.cfbd_no_key'));
        }

        return $this->request('/calendar', ['year' => $year], $apiKey);
    }

    /**
     * The team box score for every game in a week.
     *
     * 🚨 A WEEK, not a game, and that is a budget decision. CollegeFootballData
     * spends a monthly allowance per call and a Saturday has sixty games on it,
     * so asking per game is sixty calls for what one answers. Two calls — this
     * and the player one — cover a whole week.
     *
     * Answered as `[cfbd game id => the provider's own `teams` array]`, still
     * in its shape. Normalising happens once, in BoxScoreService.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function fetchTeamBoxScores(int $year, string $seasonType, int $week): array
    {
        return $this->boxScores('/games/teams', $year, $seasonType, $week);
    }

    /**
     * The player box score for every game in a week.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function fetchPlayerBoxScores(int $year, string $seasonType, int $week): array
    {
        return $this->boxScores('/games/players', $year, $seasonType, $week);
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function boxScores(string $endpoint, int $year, string $seasonType, int $week): array
    {
        $apiKey = $this->settings->get('ernestdefoe-picks.cfbd_api_key');

        if (empty($apiKey)) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-picks.api.errors.cfbd_no_key'));
        }

        $rows = $this->request($endpoint, [
            'year' => $year,
            'seasonType' => $seasonType,
            'week' => $week,
            'classification' => 'fbs',
        ], $apiKey);

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $sides = $row['teams'] ?? null;

            /*
             * 🚨 A game with no id, or with one side missing, is dropped rather
             * than half-kept. A box score showing one team's numbers beside a
             * blank column reads as the other team having done nothing, which
             * is worse than showing no box score at all.
             */
            if ($id < 1 || ! is_array($sides) || count($sides) < 2) {
                continue;
            }

            $out[$id] = array_values(array_filter($sides, 'is_array'));
        }

        return $out;
    }

    /**
     * Make a GET request to the CFBD API via Guzzle (honours host proxy/SSL
     * config and is mockable in tests). Replaces the previous raw-curl call.
     *
     * @throws RuntimeException
     */
    private function request(string $endpoint, array $params, string $apiKey): array
    {
        /*
         * 🚨 After a 429, nothing is asked until the pause is over. The free
         * plan is about a thousand calls a month: asking again every hour while
         * refused only spends what is left the moment the window reopens.
         */
        $until = (int) ($this->cache?->get(self::PAUSE_KEY) ?? 0);
        if ($until > time()) {
            throw new CfbdPausedException($this->translator->trans('ernestdefoe-picks.api.errors.cfbd_paused', ['endpoint' => $endpoint, 'minutes' => (int) ceil(($until - time()) / 60)]));
        }

        try {
            $response = $this->http->request('GET', self::BASE_URL.$endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Accept' => 'application/json',
                ],
                'query' => $params,
                'timeout' => self::TIMEOUT,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-picks.api.errors.cfbd_request_failed', ['error' => $e->getMessage()]), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status === 429) {
            $wait = (int) $response->getHeaderLine('Retry-After');
            $wait = $wait > 0 && $wait <= 7 * 86400 ? $wait : self::PAUSE_SECONDS;
            $this->cache?->put(self::PAUSE_KEY, time() + $wait, $wait);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-picks.api.errors.cfbd_http', ['status' => $status, 'endpoint' => $endpoint]));
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded)) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-picks.api.errors.cfbd_bad_json'));
        }

        return $decoded;
    }
}
