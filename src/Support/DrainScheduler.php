<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Support;

use ArtisanBuild\TelltaleClient\Jobs\DrainOutbox;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

final class DrainScheduler
{
    public function __construct(
        private readonly ClientDatabase $database,
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    public function schedule(int $delaySeconds = 0): void
    {
        try {
            if (! $this->config->get('telltale.queue.auto_dispatch', true)
                || $this->database->isOptedOut()
                || $this->database->outboxCount() === 0
                || ! $this->container->bound(QueueFactory::class)) {
                return;
            }

            $connection = $this->config->get('telltale.queue.connection', 'database');

            if (! is_string($connection) || $connection === '' || $connection === 'sync') {
                return;
            }

            $now = time();
            $deduplicate = max(1, (int) $this->config->get('telltale.queue.deduplicate_seconds', 15));

            if (! $this->database->claimDrainSchedule($now, max($deduplicate, $delaySeconds))) {
                return;
            }

            $queue = $this->container->make(QueueFactory::class)->connection($connection);
            $queueName = $this->config->get('telltale.queue.name');
            $queue->later(
                max(0, $delaySeconds),
                new DrainOutbox,
                '',
                is_string($queueName) && $queueName !== '' ? $queueName : null,
            );
        } catch (Throwable) {
            try {
                $this->database->clearDrainSchedule();
            } catch (Throwable) {
                // Scheduling remains best-effort when host queue storage is unavailable.
            }
        }
    }

    public function completed(): void
    {
        try {
            $this->database->clearDrainSchedule();

            if ($this->database->outboxCount() > 0) {
                $this->schedule(1);
            }
        } catch (Throwable) {
            // The next capture or scheduler tick can retry.
        }
    }

    public function deferred(int $retryAfterSeconds): void
    {
        try {
            $this->database->deferDrainSchedule(time() + max(1, $retryAfterSeconds));
        } catch (Throwable) {
            // Backoff remains persisted by DrainService even if this hint fails.
        }
    }
}
