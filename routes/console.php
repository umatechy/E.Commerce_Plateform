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
