<?php

namespace Resofire\Picks\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use RuntimeException;

class CfbdService
{
    protected const BASE_URL = 'https://api.collegefootballdata.com';
    protected const TIMEOUT  = 30;

    public function __construct(
        protected SettingsRepositoryInterface $settings
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
     * Make a GET request to the CFBD API using curl.
     *
     * @throws RuntimeException
     */
    private function request(string $endpoint, array $params, string $apiKey): array
    {
        $url = self::BASE_URL . $endpoint;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException('CFBD request failed: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new RuntimeException('CFBD API returned HTTP ' . $httpCode . ' for ' . $endpoint);
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('CFBD response was not valid JSON.');
        }

        return $decoded ?? [];
    }
}
