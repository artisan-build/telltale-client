<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient;

use ArtisanBuild\TelltaleClient\Console\DoctorCommand;
use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleClient\Transport\DrainService;
use ArtisanBuild\TelltaleClient\Transport\RegistrationClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class TelltaleClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telltale.php', 'telltale');

        $this->app->singleton(ClientDatabase::class, function (): ClientDatabase {
            $path = config('telltale.database');

            return new ClientDatabase(
                is_string($path) && $path !== '' ? $path : database_path('telltale.sqlite'),
                $this->app->make(StringEncrypter::class),
            );
        });
        $this->app->singleton(RegistrationClient::class, fn (): RegistrationClient => new RegistrationClient(
            $this->app->make(ClientDatabase::class),
            $this->app->make(Factory::class),
            $this->app->make(Repository::class),
        ));
        $this->app->singleton(DrainService::class, fn (): DrainService => new DrainService(
            $this->app->make(ClientDatabase::class),
            $this->app->make(RegistrationClient::class),
            $this->app->make(Factory::class),
            $this->app->make(Repository::class),
        ));
        $this->app->singleton(TelltaleClient::class, function (): TelltaleClient {
            try {
                return new TelltaleManager(
                    $this->app->make(ClientDatabase::class),
                    $this->app->make(DrainService::class),
                    $this->app->make(Repository::class),
                );
            } catch (Throwable) {
                return new NullTelltaleClient;
            }
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/telltale.php' => config_path('telltale.php'),
        ], 'telltale-config');

        if ($this->app->runningInConsole()) {
            $this->commands([DoctorCommand::class]);
        }
    }
}
