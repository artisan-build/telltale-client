<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Console;

use ArtisanBuild\TelltaleClient\Transport\RegistrationClient;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'telltale:doctor';

    protected $description = 'Check Telltale client configuration and registration';

    public function handle(
        Repository $config,
        Factory $http,
        RegistrationClient $registration,
    ): int {
        $url = $config->get('telltale.url');
        $ingest = $config->get('telltale.ingest');

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            || ! is_string($ingest) || $ingest === '') {
            $this->components->error('Telltale configuration is incomplete or invalid.');

            return self::FAILURE;
        }

        $this->components->info('Configuration is valid.');

        try {
            $response = $http
                ->timeout(max(1, (int) $config->get('telltale.http_timeout_seconds', 10)))
                ->get(rtrim($url, '/'));

            if ($response->serverError()) {
                $this->components->error('The Telltale server is not healthy.');

                return self::FAILURE;
            }
        } catch (Throwable) {
            $this->components->error('The Telltale server could not be reached.');

            return self::FAILURE;
        }

        $this->components->info('The Telltale server is reachable.');

        if (! $registration->register(force: true)) {
            $this->components->error('Registration failed. Check the configured server and ingest value.');

            return self::FAILURE;
        }

        $this->components->info('Registration succeeded.');

        return self::SUCCESS;
    }
}
