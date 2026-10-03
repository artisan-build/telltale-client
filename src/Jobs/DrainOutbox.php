<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Jobs;

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Support\DrainScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DrainOutbox implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $connection = config('telltale.queue.connection', 'database');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
    }

    public function tries(): int
    {
        return max(1, (int) config('telltale.queue.tries', 10));
    }

    public function handle(TelltaleClient $telltale, DrainScheduler $scheduler): void
    {
        $result = $telltale->drain();

        if (! $result->successful) {
            $retryAfter = $result->retryAfterSeconds
                ?? max(1, (int) config('telltale.backoff.initial_seconds', 15));

            $scheduler->deferred($retryAfter);
            $this->job?->release($retryAfter);

            return;
        }

        $scheduler->completed();
    }
}
