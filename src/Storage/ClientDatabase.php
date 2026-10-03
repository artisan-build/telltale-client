<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Storage;

use ArtisanBuild\TelltaleClient\Support\Backoff;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;
use Throwable;

final class ClientDatabase
{
    private SQLite3 $database;

    public function __construct(
        string $path,
        private readonly StringEncrypter $encrypter,
    ) {
        if ($path !== ':memory:') {
            $directory = dirname($path);

            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException('The Telltale storage directory could not be created.');
            }
        }

        $this->database = new SQLite3($path);
        $this->database->busyTimeout(5_000);
        $this->database->exec('PRAGMA journal_mode = WAL');
        $this->database->exec('PRAGMA synchronous = NORMAL');
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS telltale_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS telltale_outbox ('
            .'sequence INTEGER PRIMARY KEY AUTOINCREMENT, '
            .'event_id TEXT NOT NULL UNIQUE, '
            .'payload TEXT NOT NULL, '
            .'created_at INTEGER NOT NULL)'
        );
        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS telltale_outbox_created_at_sequence '
            .'ON telltale_outbox (created_at, sequence)'
        );
    }

    public function installId(): string
    {
        $installId = $this->setting('install_id');

        if ($installId !== null && Str::isUuid($installId)) {
            return $installId;
        }

        $installId = (string) Str::uuid();
        $this->putSetting('install_id', $installId);

        return $installId;
    }

    public function token(): ?string
    {
        $encrypted = $this->setting('install_token');

        if ($encrypted === null) {
            return null;
        }

        try {
            return $this->encrypter->decryptString($encrypted);
        } catch (Throwable) {
            $this->deleteSetting('install_token');

            return null;
        }
    }

    public function storeToken(string $token): void
    {
        $this->putSetting('install_token', $this->encrypter->encryptString($token));
    }

    public function clearToken(): void
    {
        $this->deleteSetting('install_token');
    }

    public function isOptedOut(): bool
    {
        return $this->setting('opted_out') === '1';
    }

    public function setOptedOut(bool $optedOut): void
    {
        $this->transaction(function () use ($optedOut): void {
            $this->putSetting('opted_out', $optedOut ? '1' : '0');

            if ($optedOut) {
                $this->database->exec('DELETE FROM telltale_outbox');
                $this->deleteSetting('active_session_id');
                $this->deleteSetting('session_last_activity_at');
                $this->deleteSetting('context_session_id');
                $this->deleteSetting('drain_scheduled_until');
            }
        });
    }

    /**
     * @return array{id: string|null, last_activity_at: int|null, context_session_id: string|null}
     */
    public function sessionState(): array
    {
        $id = $this->setting('active_session_id');
        $lastActivity = $this->setting('session_last_activity_at');

        return [
            'id' => $id !== null && Str::isUuid($id) ? $id : null,
            'last_activity_at' => $lastActivity !== null ? (int) $lastActivity : null,
            'context_session_id' => $this->setting('context_session_id'),
        ];
    }

    public function storeSession(string $sessionId, int $lastActivityAt): void
    {
        $this->putSetting('active_session_id', $sessionId);
        $this->putSetting('session_last_activity_at', (string) $lastActivityAt);
    }

    public function markSessionContext(string $sessionId): void
    {
        $this->putSetting('context_session_id', $sessionId);
    }

    public function clearSession(): void
    {
        $this->deleteSetting('active_session_id');
        $this->deleteSetting('session_last_activity_at');
    }

    public function claimDrainSchedule(int $now, int $deduplicateSeconds): bool
    {
        return $this->transaction(function () use ($now, $deduplicateSeconds): bool {
            if ((int) ($this->setting('drain_scheduled_until') ?? 0) > $now) {
                return false;
            }

            $this->putSetting(
                'drain_scheduled_until',
                (string) ($now + max(1, $deduplicateSeconds)),
            );

            return true;
        });
    }

    public function deferDrainSchedule(int $until): void
    {
        $this->putSetting('drain_scheduled_until', (string) max(0, $until));
    }

    public function clearDrainSchedule(): void
    {
        $this->deleteSetting('drain_scheduled_until');
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function enqueue(
        string $eventId,
        array $event,
        int $createdAt,
        int $maxRows,
        int $maxAgeSeconds,
    ): void {
        $payload = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->transaction(function () use ($eventId, $payload, $createdAt, $maxRows, $maxAgeSeconds): void {
            $this->pruneUnsafe($createdAt, $maxRows, $maxAgeSeconds);

            $statement = $this->prepare(
                'INSERT OR IGNORE INTO telltale_outbox (event_id, payload, created_at) '
                .'VALUES (:event_id, :payload, :created_at)'
            );
            $statement->bindValue(':event_id', $eventId, SQLITE3_TEXT);
            $statement->bindValue(':payload', $payload, SQLITE3_TEXT);
            $statement->bindValue(':created_at', $createdAt, SQLITE3_INTEGER);
            $this->execute($statement);

            $this->pruneUnsafe($createdAt, $maxRows, $maxAgeSeconds);
        });
    }

    public function prune(int $now, int $maxRows, int $maxAgeSeconds): int
    {
        return $this->transaction(fn (): int => $this->pruneUnsafe($now, $maxRows, $maxAgeSeconds));
    }

    /**
     * @return list<OutboxItem>
     */
    public function batch(int $limit): array
    {
        $statement = $this->prepare(
            'SELECT sequence, event_id, payload FROM telltale_outbox '
            .'ORDER BY sequence ASC LIMIT :batch_limit'
        );
        $statement->bindValue(':batch_limit', max(1, $limit), SQLITE3_INTEGER);
        $result = $this->execute($statement);
        $items = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            try {
                $event = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (! is_array($event)) {
                continue;
            }

            $items[] = new OutboxItem(
                sequence: (int) $row['sequence'],
                eventId: (string) $row['event_id'],
                event: $event,
            );
        }

        return $items;
    }

    /**
     * @param  list<int>  $sequences
     */
    public function acknowledge(array $sequences): void
    {
        if ($sequences === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($sequences), '?'));
        $statement = $this->prepare("DELETE FROM telltale_outbox WHERE sequence IN ({$placeholders})");

        foreach ($sequences as $index => $sequence) {
            $statement->bindValue($index + 1, $sequence, SQLITE3_INTEGER);
        }

        $this->execute($statement);
    }

    public function outboxCount(): int
    {
        return (int) $this->database->querySingle('SELECT COUNT(*) FROM telltale_outbox');
    }

    public function droppedEventsTotal(): int
    {
        return max(0, (int) ($this->setting('dropped_events_total') ?? 0));
    }

    public function nextAttemptAt(): int
    {
        return max(0, (int) ($this->setting('next_attempt_at') ?? 0));
    }

    public function transportFailures(): int
    {
        return max(0, (int) ($this->setting('transport_failures') ?? 0));
    }

    public function markTransportFailure(int $now, int $initialSeconds, int $maximumSeconds): int
    {
        $failures = $this->transportFailures() + 1;
        $delay = Backoff::delay($failures, $initialSeconds, $maximumSeconds);
        $this->putSetting('transport_failures', (string) $failures);
        $this->putSetting('next_attempt_at', (string) ($now + $delay));

        return $delay;
    }

    public function resetTransportBackoff(): void
    {
        $this->putSetting('transport_failures', '0');
        $this->putSetting('next_attempt_at', '0');
    }

    private function pruneUnsafe(int $now, int $maxRows, int $maxAgeSeconds): int
    {
        $cutoff = $now - max(1, $maxAgeSeconds);
        $statement = $this->prepare('DELETE FROM telltale_outbox WHERE created_at < :cutoff');
        $statement->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $this->execute($statement);
        $dropped = $this->database->changes();
        $count = $this->outboxCount();
        $overflow = max(0, $count - max(1, $maxRows));

        if ($overflow > 0) {
            $statement = $this->prepare(
                'DELETE FROM telltale_outbox WHERE sequence IN ('
                .'SELECT sequence FROM telltale_outbox ORDER BY sequence ASC LIMIT :overflow)'
            );
            $statement->bindValue(':overflow', $overflow, SQLITE3_INTEGER);
            $this->execute($statement);
            $dropped += $this->database->changes();
        }

        if ($dropped > 0) {
            $this->putSetting(
                'dropped_events_total',
                (string) ($this->droppedEventsTotal() + $dropped),
            );
        }

        return $dropped;
    }

    private function setting(string $key): ?string
    {
        $statement = $this->prepare('SELECT value FROM telltale_settings WHERE key = :key');
        $statement->bindValue(':key', $key, SQLITE3_TEXT);
        $result = $this->execute($statement);
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row === false ? null : (string) $row['value'];
    }

    private function putSetting(string $key, string $value): void
    {
        $statement = $this->prepare(
            'INSERT INTO telltale_settings (key, value) VALUES (:key, :value) '
            .'ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $statement->bindValue(':key', $key, SQLITE3_TEXT);
        $statement->bindValue(':value', $value, SQLITE3_TEXT);
        $this->execute($statement);
    }

    private function deleteSetting(string $key): void
    {
        $statement = $this->prepare('DELETE FROM telltale_settings WHERE key = :key');
        $statement->bindValue(':key', $key, SQLITE3_TEXT);
        $this->execute($statement);
    }

    private function prepare(string $query): SQLite3Stmt
    {
        $statement = $this->database->prepare($query);

        if (! $statement instanceof SQLite3Stmt) {
            throw new RuntimeException('Telltale could not prepare its local database operation.');
        }

        return $statement;
    }

    private function execute(SQLite3Stmt $statement): SQLite3Result
    {
        $result = $statement->execute();

        if (! $result instanceof SQLite3Result) {
            throw new RuntimeException('Telltale could not complete its local database operation.');
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        if (! $this->database->exec('BEGIN IMMEDIATE')) {
            throw new RuntimeException('Telltale could not start its local database operation.');
        }

        try {
            $result = $callback();
            $this->database->exec('COMMIT');

            return $result;
        } catch (Throwable $exception) {
            $this->database->exec('ROLLBACK');

            throw $exception;
        }
    }
}
