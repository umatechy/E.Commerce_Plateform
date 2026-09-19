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

    public function recordEvent(string $eventType, array $payload, string $idempotencyKey): void
    {
        // Deliberately does NOT wrap in its own DB::transaction() — the
        // caller's existing transaction is what must cover this write.
        // Asserting we are inside one is a defensive guard, not a
        // substitute for callers doing it correctly.
        if (! DB::transactionLevel()) {
            throw new \LogicException(
                'RecordsOutboxEvents::recordEvent() must be called inside an existing '.
                'DB::transaction() alongside the business state change it describes (ADR-004).'
            );
        }

        OutboxEvent::query()->create([
            'store_id' => $this->context->storeId(),
            'event_type' => $eventType,
            'payload' => $payload,
            'idempotency_key' => $idempotencyKey,
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }
}
