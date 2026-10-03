<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Capture\ErrorSanitizer;
use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Facades\Telltale;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleContracts\ErrorDetails;

it('sanitizes and bounds error details with a stable privacy-safe fingerprint', function (): void {
    $throwAtStableSite = static function (string $message): void {
        throw new RuntimeException($message);
    };
    $exceptions = [];

    foreach (['person@example.test '.str_repeat('x', 5000), 'other@example.test changed'] as $message) {
        try {
            $throwAtStableSite($message);
        } catch (Throwable $exception) {
            $exceptions[] = $exception;
        }
    }

    $sanitizer = app(ErrorSanitizer::class);
    $first = $sanitizer->fromThrowable($exceptions[0]);
    $second = $sanitizer->fromThrowable($exceptions[1]);
    $remote = $sanitizer->fromRemote(
        RuntimeException::class,
        'remote',
        implode("\n", array_fill(0, 150, '#0 /private/person@example.test/Worker.php(20): run()')),
    );

    expect(strlen($first->message))->toBeLessThanOrEqual(ErrorDetails::MAX_MESSAGE_LENGTH)
        ->and($first->message)->not->toContain('person@example.test')
        ->and($first->file)->not->toStartWith('/')
        ->and(count($first->stack))->toBeLessThanOrEqual(ErrorDetails::MAX_STACK_FRAMES)
        ->and($first->fingerprint)->toMatch('/^[a-f0-9]{64}$/')
        ->and($second->fingerprint)->toBe($first->fingerprint)
        ->and($remote->stack)->toHaveCount(ErrorDetails::MAX_STACK_FRAMES)
        ->and($remote->stack[0])->not->toContain('/private/', 'person@example.test');
});

it('contains recursive reporting and never lets error capture escape into the host', function (): void {
    $client = app(TelltaleClient::class);
    Telltale::beforeSend(function (array $event): array {
        if (($event['type'] ?? null) === 'error') {
            Telltale::event('recursive-event');
            $event['error']['message'] = 'Transformed person@example.test';
            $event['error']['file'] = '/private/person@example.test/Secret.php';
            $event['error']['stack'] = ['/private/person@example.test/Secret.php(1): fail()'];
            $event['error']['fingerprint'] = '+1 (415) 555-0123';
        }

        return $event;
    });

    expect(fn () => $client->report(
        new RuntimeException('Failure for +1 (415) 555-0123'),
        'laravel',
    ))->not->toThrow(Throwable::class);

    $events = array_map(static fn ($item): array => $item->event, app(ClientDatabase::class)->batch(100));
    $errors = collect($events)->where('type', 'error')->values();

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['props']['source'])->toBe('laravel')
        ->and($errors[0]['error']['message'])->toBe('Transformed [redacted-email]')
        ->and($errors[0]['error']['file'])->toBe('Secret.php')
        ->and($errors[0]['error']['stack'])->toBe(['Secret.php(1): fail()'])
        ->and($errors[0]['error']['fingerprint'])->toBe('[redacted-phone]')
        ->and(collect($events)->pluck('name'))->not->toContain('recursive-event');

    Telltale::beforeSend(function (): never {
        throw new RuntimeException('callback failure');
    });

    expect(fn () => $client->report(new RuntimeException('host failure'), 'laravel'))
        ->not->toThrow(Throwable::class);
});
