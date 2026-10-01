<?php

// Phase B30 (gap G4) — the ENVIRONMENT side of backups: where artifacts
// go, which binaries run, when the jobs fire. The POLICY side (how long
// backups are kept, whether automated backups and rehearsals run, who
// receives alerts) is in the typed settings registry (Module 33):
// backup.retention_days, backup.monthly_retention_months,
// backup.automated_backups_enabled, backup.rehearsal_enabled,
// alerts.critical_email_recipients, alerts.whatsapp_enabled,
// alerts.whatsapp_recipients.
return [
    // A Laravel filesystem disk. It must be private. An S3-compatible
    // disk works through the same abstraction (not exercised here).
    'disk' => env('BACKUP_DISK', 'local'),

    // Full paths, or just the names when the binaries are on PATH.
    'binaries' => [
        'mysqldump' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
        'mysql' => env('BACKUP_MYSQL_BINARY', 'mysql'),
    ],

    // Base64 of 32 random bytes (`php artisan backups:generate-key`). With
    // it, artifacts are encrypted before they are stored. It must NOT be
    // kept with the backups, and it is not the application key: losing it
    // makes every encrypted backup unreadable. Empty = artifacts are
    // stored unencrypted and recorded as such.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    'schedule' => [
        // UTC. The daily backup; on the 1st of a month it is the monthly one.
        'backup_at' => env('BACKUP_SCHEDULE_AT', '02:00'),
        // Recompute the newest backup's checksum from storage.
        'verify_at' => env('BACKUP_VERIFY_AT', '04:00'),
        // Weekly restore rehearsal (day 0 = Sunday).
        'rehearsal_day' => (int) env('BACKUP_REHEARSAL_DAY', 0),
        'rehearsal_at' => env('BACKUP_REHEARSAL_AT', '05:00'),
    ],

    // Owner decision 2026-09-30 §5. TARGETS, not guarantees: a backup
    // older than the RPO raises "backup overdue"; a rehearsal slower than
    // the RTO is reported in its result.
    'rpo_hours' => (int) env('BACKUP_RPO_HOURS', 24),
    'rto_hours' => (int) env('BACKUP_RTO_HOURS', 4),

    // This many scheduled backups failing in a row raises "repeated failure".
    'repeated_failure_threshold' => 2,

    // Seconds a backup or restore process may run before it is stopped.
    'process_timeout' => (int) env('BACKUP_PROCESS_TIMEOUT', 3600),
];
