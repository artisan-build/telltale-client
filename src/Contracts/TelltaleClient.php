<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Contracts;

use ArtisanBuild\TelltaleClient\Transport\DrainResult;

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
}
