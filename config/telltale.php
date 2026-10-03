<?php

declare(strict_types=1);

return [
    'url' => env('TELLTALE_URL'),
    'ingest' => env('TELLTALE_INGEST'),
    'database' => database_path('telltale.sqlite'),
    'client_version' => '1.0.0',
    'batch_size' => 100,
    'max_rows' => 10_000,
    'max_age_seconds' => 60 * 60 * 24 * 30,
    'http_timeout_seconds' => 10,
    'backoff' => [
        'initial_seconds' => 15,
        'maximum_seconds' => 60 * 60,
    ],
    'queue' => [
        'connection' => 'database',
        'tries' => 10,
    ],
];
