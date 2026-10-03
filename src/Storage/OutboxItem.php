<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Storage;

final readonly class OutboxItem
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(
        public int $sequence,
        public string $eventId,
        public array $event,
    ) {}
}
