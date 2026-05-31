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
