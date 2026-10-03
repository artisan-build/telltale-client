<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Tests;

use ArtisanBuild\TelltaleClient\TelltaleClientServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $telltaleDatabasePath;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TelltaleClientServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $this->telltaleDatabasePath = sys_get_temp_dir().'/telltale-client-'.bin2hex(random_bytes(8)).'.sqlite';
        $config = $app->make(Repository::class);
        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $config->set('telltale.database', $this->telltaleDatabasePath);
        $config->set('telltale.url', 'https://telltale.test');
        $config->set('telltale.ingest', bin2hex(random_bytes(32)));
        $config->set('telltale.backoff.initial_seconds', 2);
        $config->set('telltale.backoff.maximum_seconds', 8);
        $config->set('telltale.queue.auto_dispatch', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();

        foreach (['', '-shm', '-wal'] as $suffix) {
            $path = $this->telltaleDatabasePath.$suffix;

            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
