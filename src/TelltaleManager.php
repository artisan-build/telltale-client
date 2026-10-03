<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient;

use ArtisanBuild\TelltaleClient\Capture\ContextCollector;
use ArtisanBuild\TelltaleClient\Capture\ErrorSanitizer;
use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleClient\Support\DrainScheduler;
use ArtisanBuild\TelltaleClient\Support\Privacy;
use ArtisanBuild\TelltaleClient\Transport\DrainResult;
use ArtisanBuild\TelltaleClient\Transport\DrainService;
use ArtisanBuild\TelltaleContracts\ErrorDetails;
use ArtisanBuild\TelltaleContracts\Event;
use ArtisanBuild\TelltaleContracts\EventType;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

final class TelltaleManager implements TelltaleClient
{
    /**
     * @var (Closure(array<string, mixed>): (array<string, mixed>|null))|null
     */
    private ?Closure $beforeSend = null;

    private bool $capturing = false;

    /** @var WeakMap<Throwable, true> */
    private WeakMap $reported;

    public function __construct(
        private readonly ClientDatabase $database,
        private readonly DrainService $drainService,
        private readonly Repository $config,
        private readonly ContextCollector $context,
        private readonly ErrorSanitizer $errors,
        private readonly DrainScheduler $scheduler,
    ) {
        $this->reported = new WeakMap;
    }

    public function event(string $name, array $props = []): void
    {
        $this->capture($name, EventType::Event, $props);
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

    public function capture(string $name, EventType $type, array $props = [], ?ErrorDetails $error = null): void
    {
        if ($this->capturing) {
            return;
        }

        $this->capturing = true;

        try {
            if ($this->database->isOptedOut()) {
                return;
            }

            $now = CarbonImmutable::now('UTC');
            $sessionId = $this->ensureSession($now);
            $this->enqueue($name, $type, $props, $sessionId, $now, $error);
            $this->scheduler->schedule();
        } catch (Throwable) {
            // Telemetry must never affect the host application.
        } finally {
            $this->capturing = false;
        }
    }

    public function report(Throwable $exception, string $source, array $props = []): void
    {
        try {
            if (isset($this->reported[$exception])) {
                return;
            }

            $this->reported[$exception] = true;
            $props['source'] = $source;
            $this->capture($exception::class, EventType::Error, $props, $this->errors->fromThrowable($exception));
        } catch (Throwable) {
            // Error reporting cannot replace or escape the host exception path.
        }
    }

    /** @param  array<string, mixed>  $props */
    public function reportRemote(
        string $class,
        string $message,
        ?string $trace,
        string $source,
        array $props = [],
    ): void {
        try {
            $props['source'] = $source;
            $this->capture($class, EventType::Error, $props, $this->errors->fromRemote($class, $message, $trace));
        } catch (Throwable) {
            // Remote task failures are also best-effort telemetry.
        }
    }

    public function endSession(string $reason): void
    {
        if ($this->capturing) {
            return;
        }

        $this->capturing = true;

        try {
            if ($this->database->isOptedOut()) {
                return;
            }

            $state = $this->database->sessionState();

            if ($state['id'] === null) {
                return;
            }

            $this->enqueue(
                'session_end',
                EventType::SessionEnd,
                ['reason' => $reason],
                $state['id'],
                CarbonImmutable::now('UTC'),
            );
            $this->database->clearSession();
            $this->scheduler->schedule();
        } catch (Throwable) {
            // Missing lifecycle telemetry must not disturb the host.
        } finally {
            $this->capturing = false;
        }
    }

    public function correlationHeader(): ?string
    {
        if ($this->capturing) {
            return null;
        }

        $this->capturing = true;

        try {
            if ($this->database->isOptedOut()) {
                return null;
            }

            $sessionId = $this->ensureSession(CarbonImmutable::now('UTC'));
            $installHash = hash('sha256', 'telltale-install-v1:'.$this->database->installId());
            $this->scheduler->schedule();

            return "v1;session={$sessionId};install={$installHash}";
        } catch (Throwable) {
            return null;
        } finally {
            $this->capturing = false;
        }
    }

    private function ensureSession(CarbonImmutable $now): string
    {
        $state = $this->database->sessionState();
        $inactivity = max(1, (int) $this->config->get('telltale.session.inactivity_seconds', 1800));
        $sessionId = $state['id'];
        $nowMilliseconds = $now->getTimestampMs();

        if ($sessionId !== null
            && $state['last_activity_at'] !== null
            && $nowMilliseconds - $state['last_activity_at'] > ($inactivity * 1000)) {
            $this->enqueue(
                'session_end',
                EventType::SessionEnd,
                ['reason' => 'inactivity'],
                $sessionId,
                CarbonImmutable::createFromTimestampMsUTC($state['last_activity_at'] + ($inactivity * 1000)),
            );
            $sessionId = null;
        }

        if ($sessionId === null) {
            $sessionId = (string) Str::uuid();
            $this->database->storeSession($sessionId, $nowMilliseconds);
            $this->enqueue('session_start', EventType::SessionStart, [], $sessionId, $now);
            $state['context_session_id'] = null;
        } else {
            $this->database->storeSession($sessionId, $nowMilliseconds);
        }

        if ($state['context_session_id'] !== $sessionId) {
            $this->enqueue('context', EventType::Context, $this->context->collect(), $sessionId, $now);
            $this->database->markSessionContext($sessionId);
        }

        return $sessionId;
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function enqueue(
        string $name,
        EventType $type,
        array $props,
        string $sessionId,
        CarbonImmutable $timestamp,
        ?ErrorDetails $error = null,
    ): void {
        $event = [
            'event_id' => (string) Str::ulid(),
            'name' => $name,
            'type' => $type->value,
            'ts' => $timestamp->format('Y-m-d\TH:i:s.v\Z'),
            'session_id' => $sessionId,
            'props' => $props,
        ];

        if ($error !== null) {
            $event['error'] = $error->toArray();
        }

        if ($this->beforeSend !== null) {
            $event = ($this->beforeSend)($event);

            if ($event === null) {
                return;
            }
        }

        $contract = Event::fromArray(Privacy::event($event));
        $this->database->enqueue(
            eventId: $contract->eventId,
            event: $contract->toArray(),
            createdAt: $timestamp->getTimestamp(),
            maxRows: max(1, (int) $this->config->get('telltale.max_rows', 10_000)),
            maxAgeSeconds: max(1, (int) $this->config->get('telltale.max_age_seconds', 2_592_000)),
        );
    }
}
