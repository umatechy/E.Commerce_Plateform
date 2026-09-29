<?php

declare(strict_types=1);

namespace App\Domain\Events\Support;

use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Events\Models\OutboxEventStatus;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Application-service helper implementing ADR-004 "Publication (async,
 * outside the transaction)" contract: services call recordEvent() INSIDE
 * their existing DB::transaction() callback — never after it — so the
 * business write and the outbox write commit or roll back together.
 *
 * Usage (inside a domain service, e.g. OrderService::markPaid()):
 *
 *   DB::transaction(function () use ($order) {
 *       $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
 *       app(RecordsOutboxEvents::class)->recordEvent(
 *           eventType: 'order.paid',
 *           payload: ['order_id' => $order->id, 'amount_minor' => $order->total_minor],
 *           idempotencyKey: "order:{$order->id}:paid",
 *       );
 *   });
 */
final class RecordsOutboxEvents
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Records the event against the resolved tenant context: the current
     * store, or no store (a platform-scope event) in platform context.
     */
    public function recordEvent(string $eventType, array $payload, string $idempotencyKey): void
    {
        $this->recordEventFor(
            $this->context->isPlatform() ? null : $this->context->storeId(),
            $eventType,
            $payload,
            $idempotencyKey,
        );
    }

    /**
     * Records the event against an explicit owning store (null = platform
     * scope). For code that has no request tenant context — queued jobs,
     * where scoped state is reset per job — or that acts on one store's
     * row from platform context (a Super Admin action): recordEvent()
     * would either throw TenantContextMissingException there or attribute
     * the event to the wrong owner.
     */
    public function recordEventFor(?int $storeId, string $eventType, array $payload, string $idempotencyKey): void
    {
        // Deliberately does NOT wrap in its own DB::transaction() — the
        // caller's existing transaction is what must cover this write.
        // Asserting we are inside one is a defensive guard, not a
        // substitute for callers doing it correctly.
        // `outbox.ambient_transaction_level` is 0 in production. The test
        // suite sets it to the level RefreshDatabase already opened, so a
        // caller that forgot its own transaction fails in tests exactly as
        // it would in production (before this, the test transaction hid
        // the missing transaction in a dozen services).
        if (DB::transactionLevel() <= (int) config('outbox.ambient_transaction_level', 0)) {
            throw new \LogicException(
                'RecordsOutboxEvents::recordEvent() must be called inside an existing '.
                'DB::transaction() alongside the business state change it describes (ADR-004).'
            );
        }

        $attributes = [
            'store_id' => $storeId,
            'event_type' => $eventType,
            'payload' => $payload,
            'idempotency_key' => $idempotencyKey,
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ];

        if ($storeId === null) {
            // BelongsToTenant's creating hook would fill an empty store_id
            // from the (possibly unresolved) context; a platform-scope
            // event deliberately has none.
            OutboxEvent::withoutEvents(fn () => OutboxEvent::query()->create($attributes));

            return;
        }

        OutboxEvent::query()->create($attributes);
    }
}
