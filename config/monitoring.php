<?php

declare(strict_types=1);

/**
 * Module 24 — Store Health, Monitoring & Resource Usage (Phase B21).
 *
 * Every threshold below is a starting default, not a measured value
 * (same "REQUIRES CAPACITY VALIDATION" stance as B20): tune them once
 * real traffic exists.
 */
return [
    'store_health' => [
        // A usage-limited entitlement at or above this share of its limit
        // is reported as a warning; at or above 100% it is critical.
        'usage_warning_percent' => (int) env('STORE_HEALTH_USAGE_WARNING_PERCENT', 80),

        // A trial ending within this many days is reported as a warning.
        'trial_warning_days' => (int) env('STORE_HEALTH_TRIAL_WARNING_DAYS', 3),

        // An outbox event still pending after this many minutes means the
        // dispatcher is not keeping up (ADR-004 §17).
        'outbox_stale_minutes' => (int) env('STORE_HEALTH_OUTBOX_STALE_MINUTES', 15),

        // Look-back window for delivery failures (notifications, payment
        // and developer webhooks).
        'failure_window_hours' => (int) env('STORE_HEALTH_FAILURE_WINDOW_HOURS', 24),

        // The newest verified backup covering the store (its own, or a
        // platform-wide one) must be younger than this.
        'backup_max_age_hours' => (int) env('STORE_HEALTH_BACKUP_MAX_AGE_HOURS', 48),

        // store_health_snapshots older than this are pruned.
        'snapshot_retention_days' => (int) env('STORE_HEALTH_SNAPSHOT_RETENTION_DAYS', 30),
    ],
];
