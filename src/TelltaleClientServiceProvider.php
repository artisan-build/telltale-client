<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient;

use ArtisanBuild\TelltaleClient\Capture\AutoCapture;
use ArtisanBuild\TelltaleClient\Capture\ContextCollector;
use ArtisanBuild\TelltaleClient\Capture\ErrorSanitizer;
use ArtisanBuild\TelltaleClient\Console\DoctorCommand;
use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Http\TraceHeaderMiddleware;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleClient\Support\DrainScheduler;
use ArtisanBuild\TelltaleClient\Transport\DrainService;
use ArtisanBuild\TelltaleClient\Transport\RegistrationClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
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
        $this->app->singleton(ContextCollector::class);
        $this->app->singleton(ErrorSanitizer::class);
        $this->app->singleton(DrainScheduler::class);
        $this->app->singleton(TelltaleClient::class, function (): TelltaleClient {
            try {
                return new TelltaleManager(
                    $this->app->make(ClientDatabase::class),
                    $this->app->make(DrainService::class),
                    $this->app->make(Repository::class),
                    $this->app->make(ContextCollector::class),
                    $this->app->make(ErrorSanitizer::class),
                    $this->app->make(DrainScheduler::class),
                );
            } catch (Throwable) {
                return new NullTelltaleClient;
            }
        });
        $this->app->singleton(TraceHeaderMiddleware::class);
        $this->app->singleton(AutoCapture::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/telltale.php' => config_path('telltale.php'),
        ], 'telltale-config');

        if ($this->app->runningInConsole()) {
            $this->commands([DoctorCommand::class]);
        }

        $capture = $this->app->make(AutoCapture::class);
        $capture->register();

        $this->app->afterResolving(Factory::class, function (Factory $factory): void {
            $factory->globalRequestMiddleware(
                fn (RequestInterface $request): RequestInterface => $this->app
                    ->make(TraceHeaderMiddleware::class)
                    ->add($request),
            );
        });

        if ($capture->hasDesktop()) {
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->call(fn () => $this->app->make(DrainScheduler::class)->schedule())
                    ->name('telltale:drain')
                    ->everyMinute()
                    ->withoutOverlapping(5);
            });
        }
    }
}
