<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Support;

final class Backoff
{
    public static function delay(int $failures, int $initialSeconds, int $maximumSeconds): int
    {
        $initial = max(1, $initialSeconds);
        $maximum = max($initial, $maximumSeconds);
        $exponent = min(max(0, $failures - 1), 30);

        return min($maximum, $initial * (2 ** $exponent));
    }
}
