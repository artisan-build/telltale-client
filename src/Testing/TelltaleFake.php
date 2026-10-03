<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Testing;

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Transport\DrainResult;
use Closure;
use PHPUnit\Framework\Assert;
use Throwable;

final class TelltaleFake implements TelltaleClient
{
    /**
     * @var list<array{name: string, props: array<string, mixed>}>
     */
    private array $events = [];

    private bool $optedOut = false;

    /**
     * @var (Closure(array<string, mixed>): (array<string, mixed>|null))|null
     */
    private ?Closure $beforeSend = null;

    public function event(string $name, array $props = []): void
    {
        if ($this->optedOut) {
            return;
        }

        $event = ['name' => $name, 'props' => $props];

        if ($this->beforeSend !== null) {
            try {
                $event = ($this->beforeSend)($event);
            } catch (Throwable) {
                return;
            }

            if ($event === null) {
                return;
            }
        }

        $eventName = $event['name'] ?? null;
        $eventProps = $event['props'] ?? null;

        if (is_string($eventName) && is_array($eventProps)) {
            $this->events[] = ['name' => $eventName, 'props' => $eventProps];
        }
    }

    public function identify(string $opaqueUserId): void
    {
        $this->event('identify', ['user_id' => $opaqueUserId]);
    }

    public function optOut(): void
    {
        $this->optedOut = true;
        $this->events = [];
    }

    public function optIn(): void
    {
        $this->optedOut = false;
    }

    public function beforeSend(?callable $callback): void
    {
        $this->beforeSend = $callback === null ? null : Closure::fromCallable($callback);
    }

    public function drain(): DrainResult
    {
        return DrainResult::succeeded(0);
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $predicate
     */
    public function assertEventSent(string $name, ?Closure $predicate = null): void
    {
        $matches = array_filter(
            $this->events,
            static fn (array $event): bool => $event['name'] === $name
                && ($predicate === null || $predicate($event['props'])),
        );

        Assert::assertNotEmpty($matches, "The expected Telltale event [{$name}] was not recorded.");
    }

    public function assertIdentified(string $opaqueUserId): void
    {
        $this->assertEventSent(
            'identify',
            static fn (array $props): bool => ($props['user_id'] ?? null) === $opaqueUserId,
        );
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->events, 'Telltale recorded unexpected events.');
    }

    /**
     * @return list<array{name: string, props: array<string, mixed>}>
     */
    public function events(): array
    {
        return $this->events;
    }
}
