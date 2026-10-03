<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Contracts;

use ArtisanBuild\TelltaleClient\Transport\DrainResult;
use ArtisanBuild\TelltaleContracts\ErrorDetails;
use ArtisanBuild\TelltaleContracts\EventType;
use Throwable;

interface TelltaleClient
{
    /**
     * @param  array<string, mixed>  $props
     */
    public function event(string $name, array $props = []): void;

    public function identify(string $opaqueUserId): void;

    public function optOut(): void;

    public function optIn(): void;

    /**
     * @param  (callable(array<string, mixed>): (array<string, mixed>|null))|null  $callback
     */
    public function beforeSend(?callable $callback): void;

    public function drain(): DrainResult;

    /**
     * @internal
     *
     * @param  array<string, mixed>  $props
     */
    public function capture(string $name, EventType $type, array $props = [], ?ErrorDetails $error = null): void;

    /**
     * @internal
     *
     * @param  array<string, mixed>  $props
     */
    public function report(Throwable $exception, string $source, array $props = []): void;

    /**
     * @internal
     *
     * @param  array<string, mixed>  $props
     */
    public function reportRemote(
        string $class,
        string $message,
        ?string $trace,
        string $source,
        array $props = [],
    ): void;

    /** @internal */
    public function endSession(string $reason): void;

    /** @internal */
    public function correlationHeader(): ?string;
}
