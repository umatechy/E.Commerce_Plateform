<?php

declare(strict_types=1);

/**
 * Only the platform-specific channel lives here; Laravel merges this
 * file's `channels` into its own default logging config.
 *
 * `audit` is the channel every staff/Super Admin mutation writes to via
 * Log::channel('audit') (Phases B2, B16-B19). It was used in 27 places
 * but never defined, so each of those calls threw "Log [audit] is not
 * defined" on the first real run. Kept in its own file, separate from
 * the application log, so it can be retained and shipped independently.
 */
return [
    'channels' => [
        'audit' => [
            'driver' => 'daily',
            'path' => storage_path('logs/audit.log'),
            'level' => 'info',
            'days' => (int) env('LOG_AUDIT_DAYS', 365),
            'replace_placeholders' => true,
        ],
    ],
];
