<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient;

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Transport\DrainResult;
use ArtisanBuild\TelltaleContracts\ErrorDetails;
use ArtisanBuild\TelltaleContracts\EventType;
use Throwable;

final class NullTelltaleClient implements TelltaleClient
{
    public function event(string $name, array $props = []): void {}

    public function identify(string $opaqueUserId): void {}

    public function optOut(): void {}

    public function optIn(): void {}

    public function beforeSend(?callable $callback): void {}

    public function drain(): DrainResult
    {
        return DrainResult::failed();
    }

    public function capture(string $name, EventType $type, array $props = [], ?ErrorDetails $error = null): void {}

    public function report(Throwable $exception, string $source, array $props = []): void {}

    /** @param  array<string, mixed>  $props */
    public function reportRemote(
        string $class,
        string $message,
        ?string $trace,
        string $source,
        array $props = [],
    ): void {}

    public function endSession(string $reason): void {}

    public function correlationHeader(): ?string
    {
        return null;
    }
}
