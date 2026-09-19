<?php

declare(strict_types=1);

return [
    // ADR-004 — tunable dispatcher/consumer parameters.
    'dispatch_interval_seconds' => env('OUTBOX_DISPATCH_INTERVAL_SECONDS', 5),
    'max_attempts' => env('OUTBOX_MAX_ATTEMPTS', 5),
    'retention_days' => env('OUTBOX_RETENTION_DAYS', 90),
];
