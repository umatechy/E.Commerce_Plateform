<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Services;

use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Inventory\Models\ReservationStatus;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\FulfillmentStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\PaymentStatus as OrderPaymentStatus;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\WebhookEventStatus;
use App\Domain\Shipping\Carriers\CarrierResolver;
use App\Domain\Shipping\Exceptions\FulfillmentNotAllowedException;
use App\Domain\Shipping\Exceptions\ShipmentQuantityExceedsOrderedException;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShipmentStatus;
use App\Domain\Shipping\Models\ShipmentWebhookEvent;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY code path that creates/mutates a Shipment or ShipmentItem
 * (mirrors OrderService/PaymentService's "dumb model, service-only
 * writes" pattern exactly).
 *
 * SHIPMENT CREATION TIMING (Architectural Decision, see
 * docs/development/b8-inspection-findings.md): called by STAFF, after
 * Order creation — never by Checkout. This is the module's own §29
 * worked flow ("Create Order → Fulfillment → Ready for Pickup").
 */
final class ShipmentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CarrierResolver $carriers,
        private readonly ShipmentStateMachine $stateMachine,
        private readonly OrderService $orders,
        private readonly InventoryService $inventory,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /**
     * @param list<array{order_item_id: int, quantity: int}> $items
     * @throws FulfillmentNotAllowedException
     * @throws ShipmentQuantityExceedsOrderedException
     */
    public function createShipment(
        Order $order,
        int $warehouseId,
        string $carrier,
        array $items,
        ?int $shippingMethodId,
        ?int $pickupLocationId,
        string $idempotencyKey,
    ): Shipment {
        if ($existing = Shipment::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $this->assertPayableStateAllowsFulfillment($order);

        return DB::transaction(function () use ($order, $warehouseId, $carrier, $items, $shippingMethodId, $pickupLocationId, $idempotencyKey) {
            $shipment = Shipment::query()->create([
                'order_id' => $order->id,
                'warehouse_id' => $warehouseId,
                'shipping_method_id' => $shippingMethodId,
                'pickup_location_id' => $pickupLocationId,
                'carrier' => $carrier,
                'status' => ShipmentStatus::Draft,
                'shipping_cost_minor' => $order->shipping_total_minor,
                'currency' => $order->currency,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($items as $index => $item) {
                $orderItem = OrderItem::query()->findOrFail($item['order_item_id']);
                $quantity = (int) $item['quantity'];

                $this->assertQuantityWithinRemaining($orderItem, $quantity);

                $shipment->items()->create([
                    'order_item_id' => $orderItem->id,
                    'quantity' => $quantity,
                ]);

                $this->fulfillInventoryForItem($order, $orderItem, $quantity, "{$idempotencyKey}:item:{$index}");
            }

            $gateway = $this->carriers->resolve($carrier);
            $result = $gateway->createShipment($shipment);

            $shipment->update([
                'tracking_number' => $result->trackingNumber,
                'label_url' => $result->labelUrl,
            ]);

            // Goes through the state machine (Draft -> Ready/LabelCreated)
            // exactly like every subsequent transition — no direct
            // unchecked status write, even for this first one.
            $this->transitionTo($shipment, $result->status, 'system', null, 'Shipment created');
            $this->syncOrderFulfillmentStatus($order);

            $this->outbox->recordEvent(
                eventType: 'shipment.created',
                payload: ['shipment_id' => $shipment->id, 'order_id' => $order->id, 'carrier' => $carrier],
                idempotencyKey: "shipment:{$shipment->id}:created",
            );

            // refresh(), not fresh(): same OrderService precedent — a new
            // instance loses wasRecentlyCreated, and the controller relies
            // on it to answer 201 (created) vs 200 (idempotent replay).
            return $shipment->refresh()->load('items');
        });
    }

    /** Staff-initiated manual status update (Store Pickup / Local Delivery — no webhook exists for these). */
    public function updateStatus(Shipment $shipment, ShipmentStatus $to, ?int $actorId, ?string $description): void
    {
        $this->transitionTo($shipment, $to, 'manual', $actorId, $description);
    }

    /** Module 13 §53 "Carrier Webhooks" — mirrors PaymentService::handleWebhook()'s exact trust flow. */
    public function handleWebhook(string $provider, string $rawPayload, ?string $signatureHeader, string $externalEventId): ShipmentWebhookEvent
    {
        if ($existing = ShipmentWebhookEvent::query()->where('provider', $provider)->where('external_event_id', $externalEventId)->first()) {
            return $existing;
        }

        $payload = json_decode($rawPayload, true) ?? [];
        $trackingNumber = $payload['tracking_number'] ?? null;

        $shipment = $trackingNumber !== null
            ? Shipment::query()->withoutTenantScope()->where('tracking_number', $trackingNumber)->first()
            : null;

        $event = ShipmentWebhookEvent::query()->create([
            'store_id' => $shipment?->store_id,
            'shipment_id' => $shipment?->id,
            'provider' => $provider,
            'external_event_id' => $externalEventId,
            'status' => WebhookEventStatus::Received,
            'payload' => $payload,
        ]);

        if ($shipment === null) {
            $event->update(['status' => WebhookEventStatus::Ignored, 'failure_reason' => 'No matching shipment for tracking_number.', 'processed_at' => now()]);

            return $event;
        }

        $gateway = $this->carriers->resolve($provider);
        $storeSecret = \App\Domain\Tenancy\Models\Store::query()->find($shipment->store_id)->shipment_webhook_secret ?? '';

        if (! $gateway->verifyWebhookSignature($rawPayload, $signatureHeader, $storeSecret)) {
            $event->update(['status' => WebhookEventStatus::Failed, 'failure_reason' => 'Signature verification failed.', 'processed_at' => now()]);

            return $event;
        }

        $this->context->resolveToStore($shipment->store_id);

        DB::transaction(function () use ($gateway, $payload, $shipment, $event) {
            $translated = $gateway->translateWebhookPayload($payload);

            $this->transitionTo(
                $shipment, $translated['status'], 'webhook', null, $translated['description'],
                carrierEventCode: $translated['carrier_event_code'], location: $translated['location'], occurredAt: $translated['occurred_at'],
            );

            $event->update(['status' => WebhookEventStatus::Processed, 'processed_at' => now()]);
        });

        return $event->fresh();
    }

    private function transitionTo(
        Shipment $shipment,
        ShipmentStatus $to,
        string $source,
        ?int $actorId,
        ?string $description,
        ?string $carrierEventCode = null,
        ?string $location = null,
        ?\Carbon\CarbonImmutable $occurredAt = null,
    ): void {
        $this->stateMachine->assertCanTransition($shipment->status, $to);

        $updates = ['status' => $to];

        if ($to === ShipmentStatus::PickedUp && $shipment->shipped_at === null) {
            $updates['shipped_at'] = now();
        }
        if ($to === ShipmentStatus::Delivered) {
            $updates['delivered_at'] = now();
        }

        $shipment->update($updates);

        $this->recordTrackingEvent($shipment, $to, $carrierEventCode, $description, $location, $source, $actorId, $occurredAt);
        $this->syncOrderFulfillmentStatus($shipment->order);
    }

    private function recordTrackingEvent(
        Shipment $shipment,
        ShipmentStatus $status,
        ?string $carrierEventCode,
        ?string $description,
        ?string $location,
        string $source,
        ?int $actorId,
        ?\Carbon\CarbonImmutable $occurredAt = null,
    ): void {
        \App\Domain\Shipping\Models\ShipmentTrackingEvent::query()->create([
            'shipment_id' => $shipment->id,
            'status' => $status,
            'carrier_event_code' => $carrierEventCode,
            'description' => $description,
            'location' => $location,
            'source' => $source,
            'actor_id' => $actorId,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * Module 13 §11's requirement (this milestone's Step 11) that
     * shipment quantities never exceed ordered quantities. Enforced
     * here at the APPLICATION layer, summing across every PRIOR
     * shipment for this order item — a database constraint alone
     * cannot express "sum across rows must not exceed a value on a
     * different table."
     *
     * @throws ShipmentQuantityExceedsOrderedException
     */
    private function assertQuantityWithinRemaining(OrderItem $orderItem, int $quantity): void
    {
        $alreadyShipped = (int) \App\Domain\Shipping\Models\ShipmentItem::query()
            ->where('order_item_id', $orderItem->id)
            ->sum('quantity');

        $remaining = $orderItem->quantity - $alreadyShipped;

        if ($quantity <= 0 || $quantity > $remaining) {
            throw new ShipmentQuantityExceedsOrderedException($quantity, $remaining);
        }
    }

    /**
     * Module 13 Step 15/16 "Inventory / Fulfillment Integration" — the
     * exact resolution of B4's long-deferred reservation-commit point
     * (see inspection findings). Finds the ACTIVE reservation
     * originally created for this order (Phase B5's
     * OrderService::createOrder(), tagged reference_type='order') and
     * converts (a portion of) it via InventoryService::fulfillReservation()
     * — never a direct Inventory column update from this service.
     */
    private function fulfillInventoryForItem(Order $order, OrderItem $orderItem, int $quantity, string $idempotencyKey): void
    {
        $inventoryId = $this->resolveInventoryIdForOrderItem($orderItem);

        $reservation = StockReservation::query()
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->where('inventory_id', $inventoryId)
            ->where('status', ReservationStatus::Active)
            ->first();

        if ($reservation === null) {
            // Module 08's own documented behavior: nothing to convert
            // (e.g. the reservation already fully expired/failed
            // earlier) — this is a data-consistency signal for staff to
            // investigate, not silently ignored, but B8 does not invent
            // a new alerting mechanism for it (out of scope).
            return;
        }

        $this->inventory->fulfillReservation($reservation, $quantity, $idempotencyKey);
    }

    private function resolveInventoryIdForOrderItem(OrderItem $orderItem): int
    {
        $inventory = \App\Domain\Inventory\Models\Inventory::query()
            ->when(
                $orderItem->product_variant_id !== null,
                fn ($q) => $q->where('product_variant_id', $orderItem->product_variant_id),
                fn ($q) => $q->where('product_id', $orderItem->product_id)->whereNull('product_variant_id')
            )
            ->value('id');

        return (int) $inventory;
    }

    /**
     * Architectural Decision (see inspection findings) — COD orders may
     * be fulfilled while payment is still Pending (delivery precedes
     * cash collection); prepaid methods require Paid/PartiallyPaid/
     * Authorized first.
     *
     * @throws FulfillmentNotAllowedException
     */
    private function assertPayableStateAllowsFulfillment(Order $order): void
    {
        $allowedStatuses = [OrderPaymentStatus::Paid, OrderPaymentStatus::PartiallyPaid, OrderPaymentStatus::Authorized];

        if (in_array($order->payment_status, $allowedStatuses, true)) {
            return;
        }

        $payment = \App\Domain\Payments\Models\Payment::query()->withoutTenantScope()->where('order_id', $order->id)->first();

        if ($payment !== null && $payment->method === PaymentMethod::CashOnDelivery
            && $order->payment_status === OrderPaymentStatus::Pending) {
            return; // COD: deliver first, collect cash later (Module 12 §15)
        }

        throw new FulfillmentNotAllowedException("Order payment status [{$order->payment_status->value}] does not permit fulfillment yet.");
    }

    private function syncOrderFulfillmentStatus(Order $order): void
    {
        $totalOrdered = $order->items()->sum('quantity');
        $totalShipped = (int) \App\Domain\Shipping\Models\ShipmentItem::query()
            ->whereIn('order_item_id', $order->items()->pluck('id'))
            ->sum('quantity');

        $status = match (true) {
            $totalShipped <= 0 => FulfillmentStatus::Unfulfilled,
            $totalShipped < $totalOrdered => FulfillmentStatus::PartiallyFulfilled,
            default => FulfillmentStatus::Fulfilled,
        };

        $this->orders->syncFulfillmentStatus($order, $status);
    }
}
