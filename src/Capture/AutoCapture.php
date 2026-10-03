<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Capture;

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Jobs\DrainOutbox;
use ArtisanBuild\TelltaleContracts\EventType;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

final class AutoCapture
{
    private Closure $classExists;

    private ?string $lastDesktopScreen = null;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly Container $container,
        ?callable $classExists = null,
    ) {
        $this->classExists = Closure::fromCallable($classExists ?? class_exists(...));
    }

    public function register(): void
    {
        if (! config('telltale.capture.enabled', true)) {
            return;
        }

        if ($this->hasMobile()) {
            $this->registerMobile();
        }

        if ($this->hasDesktop()) {
            $this->registerDesktop();
        }

        if ($this->hasPlatform()) {
            $this->registerExceptions();
        }
    }

    public function hasPlatform(): bool
    {
        return $this->hasMobile() || $this->hasDesktop();
    }

    public function hasMobile(): bool
    {
        return ($this->classExists)('Native\\Mobile\\NativeServiceProvider');
    }

    public function hasDesktop(): bool
    {
        return ($this->classExists)('Native\\Desktop\\NativeServiceProvider');
    }

    private function registerMobile(): void
    {
        foreach ([
            'Native\\Mobile\\Events\\Screen\\ScreenMounted' => 'mounted',
            'Native\\Mobile\\Events\\Screen\\ScreenResumed' => 'resumed',
            'Native\\Mobile\\Events\\Screen\\ScreenUnmounted' => 'unmounted',
        ] as $eventClass => $signal) {
            if (! ($this->classExists)($eventClass)) {
                continue;
            }

            $this->events->listen($eventClass, function (object $event) use ($signal): void {
                $this->safe(function (TelltaleClient $client) use ($event, $signal): void {
                    $component = is_string($event->component ?? null) ? $event->component : 'screen';
                    $uri = is_string($event->uri ?? null) ? $event->uri : null;
                    $client->capture($uri !== null && $uri !== '' ? $uri : $component, EventType::Screen, [
                        'signal' => $signal,
                        'component' => $component,
                        'uri' => $uri,
                    ]);
                });
            });
        }

        $updateInstalled = 'Native\\Mobile\\Events\\App\\UpdateInstalled';
        if (($this->classExists)($updateInstalled)) {
            $this->events->listen($updateInstalled, function (object $event): void {
                $this->safe(fn (TelltaleClient $client) => $client->capture(
                    'app_update_installed',
                    EventType::Event,
                    ['version' => is_scalar($event->version ?? null) ? (string) $event->version : 'unknown'],
                ));
            });
        }

        $this->events->listen('Illuminate\\Queue\\Events\\JobFailed', function (object $event): void {
            $this->safe(function (TelltaleClient $client) use ($event): void {
                $exception = $event->exception ?? null;
                $job = $event->job ?? null;
                $jobName = is_object($job) && method_exists($job, 'resolveName') ? $job->resolveName() : 'queued_job';

                if (! $exception instanceof Throwable || $jobName === DrainOutbox::class) {
                    return;
                }

                $client->report($exception, 'mobile_queue_job', [
                    'connection' => is_string($event->connectionName ?? null) ? $event->connectionName : 'unknown',
                    'job' => is_string($jobName) ? $jobName : 'queued_job',
                ]);
            });
        });
    }

    private function registerDesktop(): void
    {
        foreach ([
            'Native\\Desktop\\Events\\Windows\\WindowFocused' => 'focused',
            'Native\\Desktop\\Events\\Windows\\WindowBlurred' => 'blurred',
        ] as $eventClass => $signal) {
            if (! ($this->classExists)($eventClass)) {
                continue;
            }

            $this->events->listen($eventClass, function (object $event) use ($signal): void {
                $this->safe(function (TelltaleClient $client) use ($event, $signal): void {
                    $window = is_string($event->id ?? null) ? $event->id : 'window';
                    $client->capture("window:{$window}", EventType::Screen, ['signal' => $signal]);

                    if ($signal === 'blurred') {
                        $client->endSession('window_blurred');
                    }
                });
            });
        }

        $active = 'Native\\Desktop\\Events\\PowerMonitor\\UserDidBecomeActive';
        if (($this->classExists)($active)) {
            $this->events->listen($active, fn (): mixed => $this->safe(
                fn (TelltaleClient $client) => $client->capture('app_active', EventType::Event),
            ));
        }

        $resigned = 'Native\\Desktop\\Events\\PowerMonitor\\UserDidResignActive';
        if (($this->classExists)($resigned)) {
            $this->events->listen($resigned, fn (): mixed => $this->safe(
                fn (TelltaleClient $client) => $client->endSession('user_resigned_active'),
            ));
        }

        foreach ([
            'Native\\Desktop\\Events\\AutoUpdater\\UpdateAvailable' => 'app_update_available',
            'Native\\Desktop\\Events\\AutoUpdater\\UpdateDownloaded' => 'app_update_downloaded',
        ] as $eventClass => $name) {
            if (! ($this->classExists)($eventClass)) {
                continue;
            }

            $this->events->listen($eventClass, function (object $event) use ($name): void {
                $this->safe(fn (TelltaleClient $client) => $client->capture(
                    $name,
                    EventType::Event,
                    ['version' => is_scalar($event->version ?? null) ? (string) $event->version : 'unknown'],
                ));
            });
        }

        $this->events->listen('Illuminate\\Foundation\\Http\\Events\\RequestHandled', function (object $event): void {
            $this->safe(function (TelltaleClient $client) use ($event): void {
                $screen = $this->desktopScreen($event);

                if ($screen === null || $screen === $this->lastDesktopScreen) {
                    return;
                }

                $client->capture($screen, EventType::Screen, ['signal' => 'route_changed']);
                $this->lastDesktopScreen = $screen;
            });
        });
    }

    private function desktopScreen(object $event): ?string
    {
        $request = $event->request ?? null;

        if (! is_object($request)
            || ! method_exists($request, 'isMethod')
            || ! $request->isMethod('GET')
            || ! method_exists($request, 'route')
            || ! method_exists($request, 'path')) {
            return null;
        }

        $route = $request->route();
        $name = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
        $routeName = is_string($name) ? strtolower($name) : '';
        $path = ltrim((string) $request->path(), '/');
        $normalizedPath = strtolower($path);

        foreach (['api.', 'livewire.', 'nativephp.', 'ignition.', 'debugbar.'] as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return null;
            }
        }

        foreach (['api/', 'livewire/', 'nativephp/', '_'] as $prefix) {
            if (str_starts_with($normalizedPath, $prefix)) {
                return null;
            }
        }

        if (preg_match('/\.(?:css|js|mjs|map|json|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot)$/i', $path) === 1) {
            return null;
        }

        $response = $event->response ?? null;
        $contentType = is_object($response)
            && isset($response->headers)
            && is_object($response->headers)
            && method_exists($response->headers, 'get')
                ? $response->headers->get('Content-Type')
                : null;

        if (is_string($contentType) && ! str_starts_with(strtolower($contentType), 'text/html')) {
            return null;
        }

        $path = '/'.$path;
        $path = preg_replace('/\/(?:\d+|[0-9a-f]{8}-[0-9a-f-]{27,})(?=\/|$)/i', '/{id}', $path) ?? $path;

        return $routeName !== '' ? (string) $name : $path;
    }

    private function registerExceptions(): void
    {
        try {
            $handler = $this->container->make(ExceptionHandler::class);
            $handler->reportable(function (Throwable $exception): void {
                $this->safe(fn (TelltaleClient $client) => $client->report($exception, 'laravel'));
            });
        } catch (Throwable) {
            // Hosts without Laravel's reportable handler keep working normally.
        }
    }

    private function safe(callable $callback): mixed
    {
        try {
            $client = $this->container->make(TelltaleClient::class);

            return $callback($client);
        } catch (Throwable) {
            return null;
        }
    }
}
