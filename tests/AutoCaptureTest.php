<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Capture\AutoCapture;
use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleClient\TelltaleClientServiceProvider;
use ArtisanBuild\TelltaleContracts\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Routing\Route;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\PowerMonitor\UserDidBecomeActive;
use Native\Desktop\Events\PowerMonitor\UserDidResignActive;
use Native\Desktop\Events\Windows\WindowBlurred;
use Native\Desktop\Events\Windows\WindowFocused;
use Native\Mobile\Events\App\UpdateInstalled;
use Native\Mobile\Events\Screen\ScreenMounted;
use Native\Mobile\Events\Screen\ScreenResumed;
use Native\Mobile\Events\Screen\ScreenUnmounted;
use Native\Mobile\Facades\Network;
use Native\Mobile\NetworkRoot;

it('registers safely when neither optional NativePHP runtime is installed', function (): void {
    $capture = new AutoCapture(
        app(Dispatcher::class),
        app(),
        static fn (): bool => false,
    );

    $capture->register();

    expect($capture->hasPlatform())->toBeFalse();
});

it('maps the authoritative mobile and desktop signals into local contract events', function (): void {
    require_once __DIR__.'/Fixtures/NativePhp.php';

    config()->set('nativephp.version', '1.7.0');
    config()->set('nativephp.version_code', 42);
    config()->set('telltale.capture.platform', 'mobile');
    Network::swap(new NetworkRoot((object) [
        'connected' => true,
        'type' => 'wifi',
        'isExpensive' => false,
        'isConstrained' => false,
        'address' => '192.0.2.1',
    ]));

    $handler = new class
    {
        /** @var list<Closure> */
        private array $callbacks = [];

        public function reportable(Closure $callback): void
        {
            $this->callbacks[] = $callback;
        }

        public function report(Throwable $exception): void
        {
            foreach ($this->callbacks as $callback) {
                $callback($exception);
            }
        }
    };
    $container = new Container;
    $container->instance(TelltaleClient::class, app(TelltaleClient::class));
    $container->instance(ExceptionHandler::class, $handler);
    $events = new EventDispatcher($container);
    (new AutoCapture($events, $container))->register();

    $events->dispatch(new ScreenMounted('App\\Screens\\Home', '/home'));
    $events->dispatch(new ScreenResumed('App\\Screens\\Home', '/home'));
    $events->dispatch(new ScreenUnmounted('App\\Screens\\Home', '/home'));
    $events->dispatch(new UpdateInstalled('1.8.0', time()));

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->once()->andReturn('App\\Jobs\\SyncData');
    $events->dispatch(new JobFailed('database', $job, new RuntimeException('Queue person@example.test')));
    $handler->report(new RuntimeException('Web-view route failure'));

    $events->dispatch(new WindowFocused('main'));
    $events->dispatch(new WindowBlurred('main'));
    $events->dispatch(new UserDidBecomeActive);
    $events->dispatch(new UserDidResignActive);
    $events->dispatch(new UpdateAvailable('2.5.0', [], '2026-10-03', 'Telltale 2.5'));
    $events->dispatch(new UpdateDownloaded('/tmp/telltale.zip', '2.5.0', [], '2026-10-03', 'Telltale 2.5'));

    $request = Request::create('/orders/123');
    $request->setRouteResolver(function (): Route {
        $route = new Route('GET', '/orders/{order}', fn (): string => 'ok');
        $route->name('orders.show');

        return $route;
    });
    $events->dispatch(new RequestHandled($request, new Response('ok', 200, ['Content-Type' => 'text/html'])));

    $events = array_column(app(ClientDatabase::class)->batch(100), 'event');
    $byName = [];
    foreach ($events as $captured) {
        $byName[$captured['name']][] = $captured;
    }

    expect($byName['/home'])->toHaveCount(3)
        ->and(array_column($byName['/home'], 'type'))->each->toBe('screen')
        ->and($byName['app_update_installed'][0]['props']['version'])->toBe('1.8.0')
        ->and($byName[RuntimeException::class])->toHaveCount(2)
        ->and($byName[RuntimeException::class][0]['error']['message'])->not->toContain('person@example.test')
        ->and(collect($byName[RuntimeException::class])->pluck('props.source'))->toContain('laravel')
        ->and($byName['window:main'])->toHaveCount(2)
        ->and(collect($events)->where('type', 'session_end')->pluck('props.reason'))
        ->toContain('window_blurred', 'user_resigned_active')
        ->and($byName['app_update_available'][0]['props'])->toBe(['version' => '2.5.0'])
        ->and($byName['app_update_downloaded'][0]['props'])->toBe(['version' => '2.5.0'])
        ->and($byName['orders.show'][0]['type'])->toBe('screen');

    foreach ($events as $captured) {
        expect(fn () => Event::fromArray($captured))->not->toThrow(Throwable::class);
    }
});

it('captures only distinct desktop GET navigations', function (): void {
    require_once __DIR__.'/Fixtures/NativePhp.php';

    $container = new Container;
    $container->instance(TelltaleClient::class, app(TelltaleClient::class));
    $events = new EventDispatcher($container);
    (new AutoCapture($events, $container))->register();

    $navigate = function (string $method, string $path, string $name, string $contentType = 'text/html') use ($events): void {
        $request = Request::create($path, $method, server: ['HTTP_ACCEPT' => 'text/html']);
        $request->setRouteResolver(function () use ($method, $path, $name): Route {
            $route = new Route($method, ltrim($path, '/'), fn (): string => 'ok');
            $route->name($name);

            return $route;
        });
        $events->dispatch(new RequestHandled($request, new Response('ok', 200, ['Content-Type' => $contentType])));
    };

    $navigate('GET', '/home', 'home');
    $navigate('GET', '/home', 'home');
    $navigate('POST', '/livewire/update', 'livewire.update');
    $navigate('GET', '/api/profile', 'api.profile', 'application/json');
    $navigate('GET', '/_native/status', 'nativephp.status');
    $navigate('GET', '/build/app.js', 'assets.app', 'application/javascript');
    $navigate('GET', '/health', 'health', 'text/plain');
    $navigate('GET', '/settings', 'settings');
    $navigate('GET', '/home', 'home');

    $screens = collect(app(ClientDatabase::class)->batch(100))
        ->pluck('event')
        ->filter(fn (array $event): bool => ($event['props']['signal'] ?? null) === 'route_changed')
        ->pluck('name')
        ->values()
        ->all();

    expect($screens)->toBe(['home', 'settings', 'home']);
});

it('registers the desktop scheduler drain primitive without requiring host code', function (): void {
    require_once __DIR__.'/Fixtures/NativePhp.php';

    (new TelltaleClientServiceProvider(app()))->boot();

    $events = collect(app(Schedule::class)->events());

    expect($events->contains(fn ($event): bool => $event->description === 'telltale:drain'))->toBeTrue();
});
