<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Events\Jobs\ConsumeOutboxEventJob;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Events\Models\OutboxEventStatus;
use Illuminate\Console\Command;

/**
 * ADR-004 outbox dispatcher. Scheduled to run every
 * OUTBOX_DISPATCH_INTERVAL_SECONDS (default 5s, see .env.example) via
 * Laravel's scheduler (routes/console.php — Phase B0 wiring below).
 *
 * Reads 'pending' outbox rows and dispatches ONE queued
 * ConsumeOutboxEventJob per row per registered consumer, then marks the
 * row 'processing'. This command intentionally does NOT do the consumer
 * work itself — it only hands rows off to real, retryable, Redis-backed
 * queue jobs, so a slow/crashed dispatcher run never blocks delivery.
 */
final class PublishOutboxEvents extends Command
{
    protected $signature = 'outbox:publish {--limit=200}';

    protected $description = 'Dispatch pending outbox_events rows onto queued consumer jobs (ADR-004).';

    public function handle(): int
    {
        $rows = OutboxEvent::query()
            ->withoutTenantScope() // platform-level sweep across all tenants, by design
            ->where('status', OutboxEventStatus::Pending)
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($rows as $row) {
            $row->update(['status' => OutboxEventStatus::Processing]);

            // NOTE: ConsumeOutboxEventJob is a placeholder contract for
            // Phase B0 — concrete consumers (webhook delivery,
            // notifications, etc.) are registered starting Phase B5+
            // as their owning modules are implemented (ADR-004 §8).
            ConsumeOutboxEventJob::dispatch($row->id)->onQueue('outbox');
        }

        $this->info(sprintf('Dispatched %d outbox event(s).', $rows->count()));

        return self::SUCCESS;
    }
}
