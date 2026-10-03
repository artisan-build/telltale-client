<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Facades;

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Testing\TelltaleFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void event(string $name, array<string, mixed> $props = [])
 * @method static void identify(string $opaqueUserId)
 * @method static void optOut()
 * @method static void optIn()
 * @method static void beforeSend(?callable $callback)
 * @method static \ArtisanBuild\TelltaleClient\Transport\DrainResult drain()
 */
final class Telltale extends Facade
{
    public static function fake(): TelltaleFake
    {
        $fake = new TelltaleFake;
        self::swap($fake);
        self::getFacadeApplication()?->instance(TelltaleClient::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return TelltaleClient::class;
    }
}
