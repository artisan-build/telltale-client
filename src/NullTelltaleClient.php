<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient;

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Transport\DrainResult;

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
}
