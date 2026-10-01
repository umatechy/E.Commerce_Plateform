<?php

use Illuminate\Support\Facades\Schedule;

// ADR-004 outbox dispatcher — runs continuously at the interval defined
// in config/outbox.php. everyFiveSeconds() is illustrative; Laravel's
// scheduler has a 1-minute floor, so production deployment must run
// `artisan outbox:publish` via a dedicated supervised worker loop rather
// than cron-based scheduling for the sub-minute interval this platform
// needs — see docs/development/outbox-worker.md.
Schedule::command('outbox:publish')->everyMinute();

// Module 08 §22 "Reservation Expiry".
Schedule::command('inventory:expire-reservations')->everyMinute();

// Module 15 §33-35 "Abandoned Cart Recovery" (Phase B10).
Schedule::command('marketing:detect-abandoned-carts')->everyFifteenMinutes();

// Module 23 (Phase B19 retention; Phase B30 / gap G4 the rest) — all on
// the existing scheduler. Times are UTC and come from config/backup.php.
// withoutOverlapping() stops one server overlapping itself; the commands
// are also safe against a second server (a unique schedule key, locks,
// conditional updates).
// - The daily platform backup (monthly on the 1st). The dump runs in
//   RunBackupJob on the queue.
Schedule::command('backups:run-scheduled')->dailyAt((string) config('backup.schedule.backup_at', '02:00'))->timezone('UTC')->withoutOverlapping();
// - Re-read the newest backup from storage and compare its checksum.
Schedule::command('backups:verify --deep')->dailyAt((string) config('backup.schedule.verify_at', '04:00'))->timezone('UTC')->withoutOverlapping();
// - Overdue backups and repeated failures: one alert a day per condition.
Schedule::command('backups:monitor')->hourly()->withoutOverlapping();
// - The weekly restore rehearsal, into a throw-away database.
Schedule::command('backups:rehearse')->weeklyOn((int) config('backup.schedule.rehearsal_day', 0), (string) config('backup.schedule.rehearsal_at', '05:00'))->timezone('UTC')->withoutOverlapping();
// - Retention.
Schedule::command('backups:expire')->daily()->withoutOverlapping();
// Module 24 (Phase B21): hourly store-health history for the Super Admin
// overview; also prunes snapshots past their retention.
Schedule::command('store-health:snapshot')->hourly()->withoutOverlapping();

// Module 32 (Phase B22): audit retention; chains stay verifiable.
Schedule::command('audit:prune')->dailyAt('03:10')->withoutOverlapping();

// Module 29 (Phase B23): renewal invoices, period roll-over and dunning.
// Hourly so a payment recorded late still renews within the hour; each
// run is idempotent.
Schedule::command('billing:run')->hourly()->withoutOverlapping();

// Module 34 (Phase B26): flag support tickets that missed their service
// level; close resolved tickets once the reopen window has passed.
Schedule::command('support:maintain')->hourly()->withoutOverlapping();
