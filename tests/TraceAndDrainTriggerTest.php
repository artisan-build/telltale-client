<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Facades\Telltale;
use ArtisanBuild\TelltaleClient\Http\TraceHeaderMiddleware;
use ArtisanBuild\TelltaleClient\Jobs\DrainOutbox;
use ArtisanBuild\TelltaleClient\NullTelltaleClient;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleClient\Support\DrainScheduler;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

it('adds the versioned correlation header to Laravel HTTP and Guzzle without exposing identity or secrets', function (): void {
    Http::fake();
    $database = app(ClientDatabase::class);
    $installId = $database->installId();
    $token = bin2hex(random_bytes(48));
    $database->storeToken($token);
    $ingest = (string) config('telltale.ingest');

    Http::get('https://backend.example.test/profile');
    Http::post('https://telltale.test/api/ingest', []);

    $external = null;
    Http::assertSent(function ($request) use (&$external): bool {
        if ($request->url() === 'https://backend.example.test/profile') {
            $external = $request;
        }

        return true;
    });

    expect($external)->not->toBeNull();
    $header = $external->header(TraceHeaderMiddleware::HEADER)[0] ?? null;
    expect($header)->toMatch('/^v1;session=[0-9a-f-]{36};install=[a-f0-9]{64}$/')
        ->and($header)->not->toContain($installId)
        ->and($header)->not->toContain($ingest)
        ->and($header)->not->toContain($token);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://telltale.test/api/ingest'
        && ! $request->hasHeader(TraceHeaderMiddleware::HEADER));

    $seen = null;
    $handler = app(TraceHeaderMiddleware::class)(
        function (RequestInterface $request) use (&$seen): RequestInterface {
            return $seen = $request;
        },
    );
    $handler(new Request('GET', 'https://guzzle.example.test/data'), []);
    expect($seen?->getHeaderLine(TraceHeaderMiddleware::HEADER))->toBe($header);

    Telltale::optOut();
    Http::get('https://backend.example.test/after-opt-out');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://backend.example.test/after-opt-out'
        && ! $request->hasHeader(TraceHeaderMiddleware::HEADER));
});

it('queues one bounded database drain trigger without inline HTTP or duplicate storms', function (): void {
    config()->set('telltale.queue.auto_dispatch', true);
    config()->set('telltale.queue.connection', 'database');
    Http::preventStrayRequests();

    $queue = Mockery::mock(Queue::class);
    $queue->shouldReceive('later')
        ->once()
        ->with(0, Mockery::type(DrainOutbox::class), '', null)
        ->andReturn('job-id');
    $factory = Mockery::mock(QueueFactory::class);
    $factory->shouldReceive('connection')->once()->with('database')->andReturn($queue);
    app()->instance(QueueFactory::class, $factory);

    Telltale::event('first');
    Telltale::event('second');

    expect(app(ClientDatabase::class)->outboxCount())->toBe(4);
    Http::assertNothingSent();

    Telltale::optOut();
    Telltale::event('ignored');
    expect(app(ClientDatabase::class)->outboxCount())->toBe(0);
});

it('releases failed drain jobs with a bounded fallback delay', function (): void {
    config()->set('telltale.backoff.initial_seconds', 17);
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once()->with(17);
    $job = new DrainOutbox;
    $job->setJob($queueJob);

    $job->handle(new NullTelltaleClient, app(DrainScheduler::class));
});
