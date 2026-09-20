<?php

declare(strict_types=1);

namespace App\Domain\Events\Jobs;

use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Events\Models\OutboxEventStatus;
use App\Domain\Notifications\Services\NotificationEventRouter;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-004 generic outbox consumer entry point. Re-applies tenant context
 * from the row's OWN stored store_id (never ambient state — jobs may run
 * in a different worker process than the one that dispatched them,
 * ADR-001 Layer 6). Routes to concrete per-event-type handlers.
 *
 * Retry policy per ADR-004 §8: exponential backoff, capped attempts from
 * OUTBOX_MAX_ATTEMPTS. Exhausted retries flip the row to 'failed' AND let
 * Laravel's standard failed_jobs table record the failure — both are
 * kept in sync so the event is visible from either view (ADR-004 §16).
 */
final class ConsumeOutboxEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(public readonly int $outboxEventId)
    {
        $this->tries = (int) config('outbox.max_attempts', 5);
    }

    public function backoff(): array
    {
        return [10, 30, 60, 300, 900]; // seconds, exponential-ish ceiling
    }

    public function handle(TenantContext $context, NotificationEventRouter $notifications): void
    {
        $row = OutboxEvent::query()->withoutTenantScope()->findOrFail($this->outboxEventId);

        // Re-resolve tenant context from the ROW's own store_id, not from
        // any ambient request/worker state (ADR-001 Layer 6).
        $context->resolveToStore($row->store_id);

        // Idempotency guard (ADR-004 §8): each concrete handler is
        // responsible for checking/recording $row->idempotency_key in its
        // own processed-events tracking table before doing real work.
        //
        // Phase B11: NotificationEventRouter is this platform's first
        // real per-event-type consumer (see
        // docs/development/b11-inspection-findings.md "Critical
        // Finding") — every domain-specific idempotency guarantee is
        // its OWN (NotificationService checks notification_messages'
        // unique idempotency_key before creating anything), so a
        // retried/duplicate dispatch of this same job is always safe.
        $notifications->route($row->event_type, $row->payload);

        $row->update(['status' => OutboxEventStatus::Published]);
    }

    public function failed(\Throwable $exception): void
    {
        OutboxEvent::query()->withoutTenantScope()
            ->whereKey($this->outboxEventId)
            ->update([
                'status' => OutboxEventStatus::Failed,
                'last_error' => $exception->getMessage(),
            ]);
    }
}
