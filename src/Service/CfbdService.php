<?php

namespace Resofire\Picks\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client as HttpClient;
use RuntimeException;

class CfbdService
{
    protected const BASE_URL = 'https://api.collegefootballdata.com';
    protected const TIMEOUT  = 30;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected HttpClient $http
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
            throw new RuntimeException('CFBD API key is not configured.');
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
            throw new RuntimeException('CFBD API key is not configured.');
        }

        $params = [
            'year'           => $year,
            'seasonType'     => $seasonType,
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
            throw new RuntimeException('CFBD API key is not configured.');
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
            throw new RuntimeException('CFBD API key is not configured.');
        }

        $rows = $this->request($endpoint, [
            'year'           => $year,
            'seasonType'     => $seasonType,
            'week'           => $week,
            'classification' => 'fbs',
        ], $apiKey);

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
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
            if ($id < 1 || !is_array($sides) || count($sides) < 2) {
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
        try {
            $response = $this->http->request('GET', self::BASE_URL . $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept'        => 'application/json',
                ],
                'query'       => $params,
                'timeout'     => self::TIMEOUT,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('CFBD request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('CFBD API returned HTTP ' . $status . ' for ' . $endpoint);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('CFBD response was not valid JSON.');
        }

        return $decoded;
    }
}
