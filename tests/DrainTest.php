<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Facades\Telltale;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleClient\Support\Backoff;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('registers then sends a FIFO gzip envelope with bearer auth and deletes acknowledged rows', function (): void {
    $token = bin2hex(random_bytes(48));
    $envelope = null;
    Http::fake(function (Request $request) use ($token, &$envelope) {
        if ($request->url() === 'https://telltale.test/api/register') {
            expect($request->hasHeader('X-Telltale-Ingest'))->toBeTrue()
                ->and($request->hasHeader('X-Telltale-Session'))->toBeFalse()
                ->and($request['install_id'])->toBe(app(ClientDatabase::class)->installId());

            return Http::response(['install_token' => $token], 201);
        }

        expect($request->hasHeader('Authorization', 'Bearer '.$token))->toBeTrue()
            ->and($request->hasHeader('X-Telltale-Session'))->toBeFalse()
            ->and($request->hasHeader('Content-Encoding', 'gzip'))->toBeTrue();
        $decoded = gzdecode($request->body());
        expect($decoded)->not->toBeFalse();
        $envelope = json_decode((string) $decoded, true, flags: JSON_THROW_ON_ERROR);

        return Http::response(['accepted' => 4, 'duplicates' => 0, 'dropped_events_total' => 0], 202);
    });

    Telltale::event('first');
    Telltale::event('second');
    Http::assertNothingSent();

    $result = Telltale::drain();

    expect($result->successful)->toBeTrue()
        ->and($result->acknowledged)->toBe(4)
        ->and(array_column($envelope['events'], 'name'))->toBe(['session_start', 'context', 'first', 'second'])
        ->and(app(ClientDatabase::class)->outboxCount())->toBe(0)
        ->and(app(ClientDatabase::class)->token())->toBe($token);
});

it('preserves event ids across an uncertain replay and deletes only after acknowledgement', function (): void {
    $database = app(ClientDatabase::class);
    $database->storeToken(bin2hex(random_bytes(48)));
    Telltale::event('replayed');
    $eventId = $database->batch(1)[0]->eventId;
    $attempt = 0;
    $seenIds = [];

    Http::fake(function (Request $request) use (&$attempt, &$seenIds) {
        $body = gzdecode($request->body());
        $payload = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
        $seenIds[] = $payload['events'][0]['event_id'];
        $attempt++;

        if ($attempt === 1) {
            throw new ConnectionException('offline');
        }

        return Http::response(['accepted' => 0, 'duplicates' => 1, 'dropped_events_total' => 0], 202);
    });

    $first = Telltale::drain();
    expect($first->successful)->toBeFalse()
        ->and($database->outboxCount())->toBe(3);

    $database->resetTransportBackoff();
    $second = Telltale::drain();
    expect($second->successful)->toBeTrue()
        ->and($seenIds)->toBe([$eventId, $eventId])
        ->and($database->outboxCount())->toBe(0);
});

it('recovers from an invalid bearer token by re-registering once', function (): void {
    $database = app(ClientDatabase::class);
    $oldToken = bin2hex(random_bytes(48));
    $newToken = bin2hex(random_bytes(48));
    $database->storeToken($oldToken);
    Telltale::event('recover');
    $ingestAttempts = 0;

    Http::fake(function (Request $request) use ($oldToken, $newToken, &$ingestAttempts) {
        if ($request->url() === 'https://telltale.test/api/register') {
            return Http::response(['install_token' => $newToken], 200);
        }

        $ingestAttempts++;

        if ($ingestAttempts === 1) {
            expect($request->hasHeader('Authorization', 'Bearer '.$oldToken))->toBeTrue();

            return Http::response(['message' => 'Invalid credentials.'], 401);
        }

        expect($request->hasHeader('Authorization', 'Bearer '.$newToken))->toBeTrue();

        return Http::response(['accepted' => 1, 'duplicates' => 0, 'dropped_events_total' => 0], 202);
    });

    $result = Telltale::drain();

    expect($result->successful)->toBeTrue()
        ->and($database->token())->toBe($newToken)
        ->and($database->outboxCount())->toBe(0);
});

it('contains registration, 4xx, 5xx, and network failures and avoids hot loops', function (string $failure): void {
    $database = app(ClientDatabase::class);
    Telltale::event('retained');

    if ($failure !== 'registration') {
        $database->storeToken(bin2hex(random_bytes(48)));
    }

    Http::fake(function () use ($failure) {
        if ($failure === 'network') {
            throw new ConnectionException('transport marker');
        }

        return Http::response(['message' => 'response marker'], match ($failure) {
            'registration', 'client' => 422,
            default => 503,
        });
    });

    $first = Telltale::drain();
    $second = Telltale::drain();

    expect($first->successful)->toBeFalse()
        ->and($first->retryAfterSeconds)->toBe(2)
        ->and($second->successful)->toBeFalse()
        ->and($second->retryAfterSeconds)->toBeGreaterThan(0)
        ->and($database->outboxCount())->toBe(3);
})->with(['registration', 'client', 'server', 'network']);

it('uses exponential bounded backoff', function (): void {
    expect(Backoff::delay(1, 2, 8))->toBe(2)
        ->and(Backoff::delay(2, 2, 8))->toBe(4)
        ->and(Backoff::delay(3, 2, 8))->toBe(8)
        ->and(Backoff::delay(30, 2, 8))->toBe(8);
});

it('reports the cumulative drop total in every later envelope', function (): void {
    $database = app(ClientDatabase::class);
    $database->storeToken(bin2hex(random_bytes(48)));
    Telltale::event('bootstrap');
    $database->acknowledge(array_column($database->batch(10), 'sequence'));
    config()->set('telltale.max_rows', 1);
    Telltale::event('evicted');
    Telltale::event('kept');
    $reported = null;

    Http::fake(function (Request $request) use (&$reported) {
        $body = gzdecode($request->body());
        $payload = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
        $reported = $payload['dropped_events_total'];

        return Http::response(['accepted' => 1, 'duplicates' => 0, 'dropped_events_total' => $reported], 202);
    });

    Telltale::drain();
    expect($reported)->toBe(1)
        ->and($database->droppedEventsTotal())->toBe(1);
});
