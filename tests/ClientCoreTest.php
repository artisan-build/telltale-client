<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Facades\Telltale;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Facades\Http;

it('persists a stable random install id and encrypts the bearer token at rest', function (): void {
    $database = app(ClientDatabase::class);
    $installId = $database->installId();
    $token = bin2hex(random_bytes(48));
    $database->storeToken($token);

    $reopened = new ClientDatabase($this->telltaleDatabasePath, app(StringEncrypter::class));

    expect($reopened->installId())->toBe($installId)
        ->and($reopened->token())->toBe($token)
        ->and(file_get_contents($this->telltaleDatabasePath))->not->toContain($token);
});

it('captures to SQLite without network IO and preserves FIFO order', function (): void {
    Http::preventStrayRequests();

    Telltale::event('first', ['position' => 1]);
    Telltale::event('second', ['position' => 2]);

    $items = app(ClientDatabase::class)->batch(10);

    expect($items)->toHaveCount(2)
        ->and($items[0]->event['name'])->toBe('first')
        ->and($items[1]->event['name'])->toBe('second');
    Http::assertNothingSent();
});

it('scrubs obvious PII after beforeSend transformations and can drop safely', function (): void {
    Telltale::beforeSend(function (array $event): array {
        $event['name'] = 'transformed';
        $event['props']['added'] = 'Contact person@example.test or +1 (415) 555-0123';

        return $event;
    });
    Telltale::identify('person@example.test');

    $event = app(ClientDatabase::class)->batch(10)[0]->event;

    expect($event['name'])->toBe('transformed')
        ->and($event['props']['user_id'])->toBe('[redacted-email]')
        ->and($event['props']['added'])->toBe('Contact [redacted-email] or [redacted-phone]');

    Telltale::beforeSend(fn (): null => null);
    Telltale::event('dropped');
    expect(app(ClientDatabase::class)->outboxCount())->toBe(1);
});

it('contains throwing callbacks and invalid contract input', function (): void {
    Telltale::beforeSend(function (): never {
        throw new RuntimeException('callback marker');
    });

    Telltale::event('safe');
    Telltale::beforeSend(null);
    $resource = fopen('php://memory', 'r');
    Telltale::event('', ['unsupported' => $resource]);

    if (is_resource($resource)) {
        fclose($resource);
    }

    expect(app(ClientDatabase::class)->outboxCount())->toBe(0);
});

it('evicts row and age overflow oldest first and accumulates drop totals', function (): void {
    config()->set('telltale.max_rows', 2);
    Telltale::event('first');
    Telltale::event('second');
    Telltale::event('third');

    $database = app(ClientDatabase::class);
    $events = $database->batch(10);

    expect(array_column(array_column($events, 'event'), 'name'))->toBe(['second', 'third'])
        ->and($database->droppedEventsTotal())->toBe(1);

    $oldEvent = $events[0]->event;
    $oldEvent['event_id'] = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $database->enqueue(
        eventId: $oldEvent['event_id'],
        event: $oldEvent,
        createdAt: time() - 100,
        maxRows: 10,
        maxAgeSeconds: 10,
    );
    $database->prune(time(), 10, 10);

    expect($database->droppedEventsTotal())->toBe(2);
});

it('persists opt out, clears immediately, suppresses capture and drain, then recovers', function (): void {
    Http::preventStrayRequests();
    Telltale::event('before');
    Telltale::optOut();

    $database = app(ClientDatabase::class);
    expect($database->isOptedOut())->toBeTrue()
        ->and($database->outboxCount())->toBe(0);

    Telltale::event('during');
    $result = Telltale::drain();
    expect($result->successful)->toBeTrue()
        ->and($database->outboxCount())->toBe(0);
    Http::assertNothingSent();

    Telltale::optIn();
    Telltale::event('after');
    expect($database->isOptedOut())->toBeFalse()
        ->and($database->batch(10)[0]->event['name'])->toBe('after');
});
