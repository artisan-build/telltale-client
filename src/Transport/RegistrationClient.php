<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Transport;

use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Throwable;

final readonly class RegistrationClient
{
    public function __construct(
        private ClientDatabase $database,
        private Factory $http,
        private Repository $config,
    ) {}

    public function register(bool $force = false): bool
    {
        try {
            if (! $force && $this->database->token() !== null) {
                return true;
            }

            $url = $this->baseUrl();
            $ingest = $this->config->get('telltale.ingest');

            if ($url === null || ! is_string($ingest) || $ingest === '') {
                return false;
            }

            $response = $this->http
                ->timeout(max(1, (int) $this->config->get('telltale.http_timeout_seconds', 10)))
                ->acceptJson()
                ->withHeaders(['X-Telltale-Ingest' => $ingest])
                ->post($url.'/api/register', ['install_id' => $this->database->installId()]);

            if (! in_array($response->status(), [200, 201], true)) {
                return false;
            }

            $token = $response->json('install_token');

            if (! is_string($token) || $token === '' || strlen($token) > 4096) {
                return false;
            }

            $this->database->storeToken($token);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function baseUrl(): ?string
    {
        $url = $this->config->get('telltale.url');

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return rtrim($url, '/');
    }
}
