<?php

namespace Resofire\Picks\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Intervention\Image\ImageManager;

class LogoService
{
    protected const ESPN_LOGO_URL  = 'https://a.espncdn.com/i/teamlogos/ncaa/500/%d.png';
    protected const ESPN_DARK_URL  = 'https://a.espncdn.com/i/teamlogos/ncaa/500-dark/%d.png';
    protected const LOGO_DIRECTORY = 'picks/logos';
    protected const WEBP_QUALITY   = 85;
    protected const TIMEOUT        = 15;

    public function __construct(
        protected ImageManager $imageManager,
        protected FilesystemFactory $filesystem,
        protected HttpClient $http,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * Download both standard and dark logos using explicit URLs from CFBD.
     * This is the primary method used during sync since CFBD provides URLs directly.
     */
    public function downloadFromUrls(?string $logoUrl, ?string $logoDarkUrl, string $slug): array
    {
        return [
            'logo_path'      => $logoUrl
                ? $this->processLogo($logoUrl, $slug, '')
                : null,
            'logo_dark_path' => $logoDarkUrl
                ? $this->processLogo($logoDarkUrl, $slug, '-dark')
                : null,
        ];
    }

    /**
     * Download both standard and dark logos for a team from ESPN CDN by ID.
     * Used for individual team logo refresh when only espn_id is available.
     */
    public function downloadAndStore(int $espnId, string $slug): array
    {
        return [
            'logo_path'      => $this->processLogo(
                sprintf(self::ESPN_LOGO_URL, $espnId),
                $slug,
                ''
            ),
            'logo_dark_path' => $this->processLogo(
                sprintf(self::ESPN_DARK_URL, $espnId),
                $slug,
                '-dark'
            ),
        ];
    }

    /**
     * Download, convert, and save one logo variant.
     * Returns the public-relative path on success, null on any failure.
     */
    private function processLogo(string $url, string $slug, string $suffix): ?string
    {
        $imageData = $this->download($url);

        if ($imageData === null) {
            return null;
        }

        return $this->convertAndSave($imageData, $slug, $suffix);
    }

    /**
     * Download raw image bytes via Guzzle (honours host proxy/SSL config and
     * follows redirects by default). Replaces the previous raw-curl call.
     * Returns null on 404, network error, or empty body.
     */
    private function download(string $url): ?string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers'         => ['User-Agent' => 'ernestdefoe/picks'],
                'timeout'         => self::TIMEOUT,
                'allow_redirects' => true,
                'http_errors'     => false,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if ($status < 200 || $status >= 300 || $body === '') {
            return null;
        }

        return $body;
    }

    /**
     * Convert raw image bytes to WebP and write to disk.
     * Returns the public-relative path on success, null on failure.
     */
    private function convertAndSave(string $imageData, string $slug, string $suffix): ?string
    {
        try {
            $encoded = $this->imageManager
                ->read($imageData)
                ->toWebp(self::WEBP_QUALITY);

            $filename = $slug . $suffix . '.webp';

            // Write through the flarum-assets disk (public/assets) so the
            // extension works on cloud/CDN-backed public disks too. put()
            // creates any missing directories.
            $this->filesystem
                ->disk('flarum-assets')
                ->put(self::LOGO_DIRECTORY . '/' . $filename, (string) $encoded);

            return 'assets/' . self::LOGO_DIRECTORY . '/' . $filename;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
