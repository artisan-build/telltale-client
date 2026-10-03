<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient;

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleClient\Support\Privacy;
use ArtisanBuild\TelltaleClient\Transport\DrainResult;
use ArtisanBuild\TelltaleClient\Transport\DrainService;
use ArtisanBuild\TelltaleContracts\Event;
use ArtisanBuild\TelltaleContracts\EventType;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Throwable;

final class TelltaleManager implements TelltaleClient
{
    /**
     * @var (Closure(array<string, mixed>): (array<string, mixed>|null))|null
     */
    private ?Closure $beforeSend = null;

    private readonly string $sessionId;

    public function __construct(
        private readonly ClientDatabase $database,
        private readonly DrainService $drainService,
        private readonly Repository $config,
    ) {
        $this->sessionId = (string) Str::uuid();
    }

    public function event(string $name, array $props = []): void
    {
        try {
            if ($this->database->isOptedOut()) {
                return;
            }

            $event = [
                'event_id' => (string) Str::ulid(),
                'name' => $name,
                'type' => EventType::Event->value,
                'ts' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                'session_id' => $this->sessionId,
                'props' => $props,
            ];

            if ($this->beforeSend !== null) {
                $event = ($this->beforeSend)($event);

                if ($event === null) {
                    return;
                }
            }

            $contract = Event::fromArray(Privacy::event($event));
            $now = time();
            $this->database->enqueue(
                eventId: $contract->eventId,
                event: $contract->toArray(),
                createdAt: $now,
                maxRows: max(1, (int) $this->config->get('telltale.max_rows', 10_000)),
                maxAgeSeconds: max(1, (int) $this->config->get('telltale.max_age_seconds', 2_592_000)),
            );
        } catch (Throwable) {
            // Telemetry must never affect the host application.
        }
    }

    public function identify(string $opaqueUserId): void
    {
        $this->event('identify', ['user_id' => $opaqueUserId]);
    }

    public function optOut(): void
    {
        try {
            $this->database->setOptedOut(true);
        } catch (Throwable) {
            // Consent controls remain safe if device storage is unavailable.
        }
    }

    public function optIn(): void
    {
        try {
            $this->database->setOptedOut(false);
        } catch (Throwable) {
            // Consent controls remain safe if device storage is unavailable.
        }
    }

    public function beforeSend(?callable $callback): void
    {
        $this->beforeSend = $callback === null ? null : Closure::fromCallable($callback);
    }

    public function drain(): DrainResult
    {
        try {
            return $this->drainService->drain();
        } catch (Throwable) {
            return DrainResult::failed();
        }
    }
}
