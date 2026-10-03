<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Transport;

final readonly class DrainResult
{
    private function __construct(
        public bool $successful,
        public int $acknowledged,
        public ?int $retryAfterSeconds,
    ) {}

    public static function succeeded(int $acknowledged): self
    {
        return new self(true, $acknowledged, null);
    }

    public static function failed(?int $retryAfterSeconds = null): self
    {
        return new self(false, 0, $retryAfterSeconds);
    }

    public static function deferred(int $retryAfterSeconds): self
    {
        return new self(false, 0, max(1, $retryAfterSeconds));
    }
}
