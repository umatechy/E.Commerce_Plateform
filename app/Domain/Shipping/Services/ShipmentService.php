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
     * Phase B49 `$options`: courier_account_id (a connected courier — booked
     * by CourierShippingService right after), carrier_name and tracking_number
     * ("another courier", booked outside), weight_grams, pieces.
     *
     * @param list<array{order_item_id: int, quantity: int}> $items
     * @param array{courier_account_id?: ?int, carrier_name?: ?string, tracking_number?: ?string, weight_grams?: ?int, pieces?: ?int} $options
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
        array $options = [],
    ): Shipment {
        if ($existing = Shipment::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $this->assertPayableStateAllowsFulfillment($order);

        return DB::transaction(function () use ($order, $warehouseId, $carrier, $items, $shippingMethodId, $pickupLocationId, $idempotencyKey, $options) {
            $courier = app(\App\Domain\Shipping\Couriers\CourierRegistry::class)->find($carrier);
            $codAmount = $this->codToCollect($order);
            $shipment = Shipment::query()->create([
                // Phase B49
                'direction' => Shipment::OUTBOUND,
                'courier_account_id' => $courier !== null ? ($options['courier_account_id'] ?? null) : null,
                'carrier_name' => $carrier === \App\Domain\Shipping\Couriers\CourierRegistry::MANUAL ? ($options['carrier_name'] ?? null) : null,
                // The weight sent to the courier and printed on the label: the store's usual weight when none is typed.
                'weight_grams' => $options['weight_grams'] ?? (int) app(\App\Domain\Settings\Services\ConfigService::class)->get('shipping.default_weight_grams'),
                'pieces' => max(1, (int) ($options['pieces'] ?? 1)),
                'cod_amount_minor' => $codAmount,
                'cod_status' => $codAmount > 0 ? 'pending' : 'none',
                'booking_status' => $courier !== null ? 'booking' : null,
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

            // Phase B49: a connected courier is asked after this transaction commits
            // (CourierShippingService::book); "another courier" is booked outside.
            $result = match (true) {
                $courier !== null => new \App\Domain\Shipping\Carriers\CarrierShipmentResult(ShipmentStatus::Ready),
                $carrier === \App\Domain\Shipping\Couriers\CourierRegistry::MANUAL => new \App\Domain\Shipping\Carriers\CarrierShipmentResult(
                    ($options['tracking_number'] ?? null) ? ShipmentStatus::LabelCreated : ShipmentStatus::Ready,
                    trackingNumber: ($options['tracking_number'] ?? null) ?: null,
                ),
                default => $this->carriers->resolve($carrier)->createShipment($shipment),
            };

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
        // Phase B49: a cancellation puts the units back (cancel()), never just a status.
        if ($to === ShipmentStatus::Cancelled) {
            $this->cancel($shipment, $actorId, $description);

            return;
        }
        // The status, its tracking event and its outbox event commit together (ADR-004).
        DB::transaction(fn () => $this->transitionTo(Shipment::query()->lockForUpdate()->findOrFail($shipment->id), $to, 'manual', $actorId, $description));
    }

    /**
     * Phase B49 (Module 13 §72): cancel before the courier took the parcel.
     * Outbound units never left: they go back on hand and stay held for the
     * order (it can be shipped again, or cancelled). A courier booking must
     * have been cancelled with the courier first (CourierShippingService).
     */
    public function cancel(Shipment $shipment, ?int $actorId, ?string $note): Shipment
    {
        return DB::transaction(function () use ($shipment, $actorId, $note) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $this->stateMachine->assertCanTransition($shipment->status, ShipmentStatus::Cancelled);
            $shipment->update(['cancelled_at' => now(), 'cod_status' => 'none']);
            if (! $shipment->isReturn()) {
                foreach ($shipment->items()->with('orderItem')->get() as $index => $item) {
                    $inventoryId = $this->resolveInventoryIdForOrderItem($item->orderItem);
                    if ($inventoryId > 0) {
                        $this->inventory->reinstateForOrder(\App\Domain\Inventory\Models\Inventory::query()->findOrFail($inventoryId), $item->quantity, $shipment->order_id, $shipment->id, $actorId, "shipment:{$shipment->id}:cancel:{$index}");
                    }
                }
                $shipment->update(['restocked_at' => now()]);
            }
            $this->transitionTo($shipment, ShipmentStatus::Cancelled, 'manual', $actorId, $note ?? 'Shipment cancelled');
            $this->outbox->recordEvent('shipment.cancelled', ['shipment_id' => $shipment->id, 'order_id' => $shipment->order_id], "shipment:{$shipment->id}:cancelled");

            return $shipment->fresh();
        });
    }

    /**
     * Phase B49 (Module 13 §68–70): the courier brought the parcel back
     * (returned to sender) and it is on the shelf again — its units become
     * sellable stock. Once per shipment.
     */
    public function restockReturned(Shipment $shipment, int $actorId): Shipment
    {
        return DB::transaction(function () use ($shipment, $actorId) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if ($shipment->isReturn() || $shipment->status !== ShipmentStatus::Returned || $shipment->restocked_at !== null) {
                throw new \App\Domain\Shipping\Exceptions\FulfillmentNotAllowedException('Only a parcel returned to the sender, not yet put back, can go back in stock.');
            }
            foreach ($shipment->items()->with('orderItem')->get() as $index => $item) {
                $inventoryId = $this->resolveInventoryIdForOrderItem($item->orderItem);
                if ($inventoryId > 0) {
                    $this->inventory->receiveShipmentBack(\App\Domain\Inventory\Models\Inventory::query()->findOrFail($inventoryId), $item->quantity, $shipment->id, $actorId, "shipment:{$shipment->id}:restock:{$index}");
                }
            }
            $shipment->update(['restocked_at' => now()]);
            $this->recordTrackingEvent($shipment, ShipmentStatus::Returned, null, 'Put back in stock', null, 'manual', $actorId);

            return $shipment->fresh();
        });
    }

    /**
     * Phase B49: "another courier" — the store booked it outside and types
     * the courier and its tracking number (now or later).
     */
    public function setManualTracking(Shipment $shipment, string $carrierName, string $trackingNumber, ?int $actorId): Shipment
    {
        return DB::transaction(function () use ($shipment, $carrierName, $trackingNumber, $actorId) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $shipment->update(['carrier_name' => $carrierName, 'tracking_number' => $trackingNumber]);
            if ($shipment->status === ShipmentStatus::Ready) {
                $this->transitionTo($shipment, ShipmentStatus::LabelCreated, 'manual', $actorId, "Booked with {$carrierName}");
            }
            app(ShippingLabelService::class)->forget($shipment);

            return $shipment->fresh();
        });
    }

    /**
     * Phase B49: what a courier's booking request established
     * (CourierShippingService calls this; the call itself is outside any
     * transaction).
     */
    public function applyBooking(Shipment $shipment, \App\Domain\Shipping\Couriers\BookingResult $result): Shipment
    {
        return DB::transaction(function () use ($shipment, $result) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if ($shipment->booking_status === 'booked') {
                return $shipment; // applied once
            }
            match ($result->outcome) {
                \App\Domain\Shipping\Couriers\CourierOutcome::Succeeded => $shipment->update([
                    'booking_status' => 'booked', 'booking_error' => null,
                    'tracking_number' => $result->trackingNumber, 'carrier_reference' => $result->carrierReference,
                    'next_poll_at' => now()->addMinutes((int) config('couriers.poll_minutes', 15)),
                ]),
                \App\Domain\Shipping\Couriers\CourierOutcome::Failed => $shipment->update(['booking_status' => 'failed', 'booking_error' => mb_substr((string) ($result->message ?? 'The courier refused the booking.'), 0, 255)]),
                \App\Domain\Shipping\Couriers\CourierOutcome::Unknown => $shipment->update(['booking_status' => 'unknown', 'booking_error' => mb_substr((string) ($result->message ?? 'No answer from the courier.'), 0, 255)]),
            };
            if ($result->outcome === \App\Domain\Shipping\Couriers\CourierOutcome::Succeeded) {
                if ($result->labelPdf !== null) {
                    app(ShippingLabelService::class)->store($shipment, $result->labelPdf, 'courier');
                }
                $this->transitionTo($shipment, ShipmentStatus::LabelCreated, 'carrier', null, 'Booked with the courier');
                $this->outbox->recordEvent('shipment.booked', ['shipment_id' => $shipment->id, 'carrier' => $shipment->carrier], "shipment:{$shipment->id}:booked");
            }

            return $shipment->fresh();
        });
    }

    /**
     * Phase B49 (Module 13 §51–54): courier events applied once each (by
     * their key), oldest first; the shipment moves along allowed steps only.
     * An event that cannot follow (e.g. reported after delivery) is kept in
     * the history without changing the status.
     *
     * @param list<\App\Domain\Shipping\Couriers\TrackingUpdate> $updates
     * @return int events recorded now
     */
    public function applyTracking(Shipment $shipment, array $updates, string $source): int
    {
        return DB::transaction(function () use ($shipment, $updates, $source) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $known = \App\Domain\Shipping\Models\ShipmentTrackingEvent::query()->where('shipment_id', $shipment->id)->whereNotNull('event_key')->pluck('event_key')->all();
            $recorded = 0;
            foreach ($updates as $update) {
                if (in_array($update->key, $known, true)) {
                    continue;
                }
                $known[] = $update->key;
                $recorded++;
                $path = $this->stateMachine->path($shipment->status, $update->status);
                if ($path === null || $path === []) {
                    $this->recordTrackingEvent($shipment, $update->status, $update->code, $update->description, $update->location, $source, null, $update->occurredAt, $update->key);

                    continue;
                }
                $last = array_pop($path);
                foreach ($path as $between) {
                    $this->transitionTo($shipment, $between, $source, null, null, occurredAt: $update->occurredAt);
                }
                $this->transitionTo($shipment, $last, $source, null, $update->description, carrierEventCode: $update->code, location: $update->location, occurredAt: $update->occurredAt, eventKey: $update->key);
            }

            return $recorded;
        });
    }

    /**
     * Phase B49 (Module 12 §64, Module 13 §41): cash the courier or rider
     * collected reached the store. Each shipment's cash is recorded on its
     * order's payment once, through PaymentService (the one payment path).
     *
     * @param iterable<Shipment> $shipments
     * @return int shipments recorded
     */
    public function recordCodRemittance(iterable $shipments, string $reference, int $actorId): int
    {
        $count = 0;
        foreach ($shipments as $shipment) {
            DB::transaction(function () use ($shipment, $reference, $actorId, &$count) {
                $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
                if ($locked->cod_status !== 'to_remit') {
                    return;
                }
                $payment = \App\Domain\Payments\Models\Payment::query()->where('order_id', $locked->order_id)->first();
                if ($payment !== null && in_array($payment->status, [\App\Domain\Payments\Models\PaymentStatus::Pending, \App\Domain\Payments\Models\PaymentStatus::Created], true)) {
                    app(\App\Domain\Payments\Services\PaymentService::class)->recordManualConfirmation($payment, $locked->cod_amount_minor, $reference, "Cash on delivery remitted for shipment {$locked->tracking_number}", $actorId);
                }
                $locked->update(['cod_status' => 'remitted', 'cod_remitted_at' => now(), 'cod_remittance_reference' => $reference]);
                $this->outbox->recordEvent('shipment.cod_remitted', ['shipment_id' => $locked->id, 'amount_minor' => $locked->cod_amount_minor], "shipment:{$locked->id}:cod-remitted");
                $count++;
            });
        }

        return $count;
    }

    /**
     * Phase B49 (Module 13 §70; Module 09 owns the return itself): the
     * parcel a customer sends back — a pickup by a connected courier, or a
     * label for "another courier". It moves no stock (the inspection does),
     * and never counts as fulfilment.
     *
     * @param array{courier_account_id?: ?int, carrier_name?: ?string, tracking_number?: ?string, weight_grams?: ?int, pieces?: ?int} $options
     */
    public function createReturnShipment(\App\Domain\Returns\Models\ReturnRequest $return, int $warehouseId, string $carrier, array $options, int $actorId): Shipment
    {
        return DB::transaction(function () use ($return, $warehouseId, $carrier, $options, $actorId) {
            $courier = app(\App\Domain\Shipping\Couriers\CourierRegistry::class)->find($carrier);
            $order = Order::query()->findOrFail($return->order_id);
            $shipment = Shipment::query()->create([
                'order_id' => $order->id, 'return_request_id' => $return->id, 'direction' => Shipment::RETURN,
                'warehouse_id' => $warehouseId, 'carrier' => $carrier, 'status' => ShipmentStatus::Draft,
                'courier_account_id' => $courier !== null ? ($options['courier_account_id'] ?? null) : null,
                'carrier_name' => $carrier === \App\Domain\Shipping\Couriers\CourierRegistry::MANUAL ? ($options['carrier_name'] ?? null) : null,
                'tracking_number' => $courier === null ? (($options['tracking_number'] ?? null) ?: null) : null,
                'weight_grams' => $options['weight_grams'] ?? null, 'pieces' => max(1, (int) ($options['pieces'] ?? 1)),
                'shipping_cost_minor' => 0, 'currency' => $order->currency,
                'booking_status' => $courier !== null ? 'booking' : null,
                'idempotency_key' => "return:{$return->id}:shipment:".\Illuminate\Support\Str::ulid(),
            ]);
            $to = $courier === null && $shipment->tracking_number !== null ? ShipmentStatus::LabelCreated : ShipmentStatus::Ready;
            $this->transitionTo($shipment, $to, 'manual', $actorId, 'Return shipment created');
            $this->outbox->recordEvent('shipment.return_created', ['shipment_id' => $shipment->id, 'return_id' => $return->id], "shipment:{$shipment->id}:return-created");

            return $shipment->fresh();
        });
    }

    /** Module 13 §53 "Carrier Webhooks" — mirrors PaymentService::handleWebhook()'s exact trust flow. */
    public function handleWebhook(string $provider, string $rawPayload, ?string $signatureHeader, string $externalEventId, ?int $sentAt = null): ShipmentWebhookEvent
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

        $signed = $sentAt !== null ? "{$sentAt}.{$rawPayload}" : $rawPayload; // Phase B49: the time is signed too
        if (! $gateway->verifyWebhookSignature($signed, $signatureHeader, $storeSecret)) {
            $event->update(['status' => WebhookEventStatus::Failed, 'failure_reason' => 'Signature verification failed.', 'processed_at' => now()]);

            return $event;
        }
        if ($sentAt !== null && abs(now()->getTimestamp() - $sentAt) > (int) app(\App\Domain\Settings\Services\ConfigService::class)->get('payments.webhook_tolerance_seconds')) {
            $event->update(['status' => WebhookEventStatus::Failed, 'failure_reason' => 'Outside the allowed time window.', 'processed_at' => now()]);

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
        ?string $eventKey = null,
    ): void {
        $this->stateMachine->assertCanTransition($shipment->status, $to);

        $updates = ['status' => $to];

        if ($to === ShipmentStatus::PickedUp && $shipment->shipped_at === null) {
            $updates['shipped_at'] = now();
        }
        if ($to === ShipmentStatus::Delivered) {
            $updates['delivered_at'] = now();
            // Phase B49: the courier or rider now holds the customer's cash, owed to the store.
            if ($shipment->cod_status === 'pending') {
                $updates['cod_status'] = 'to_remit';
            }
        }
        if (in_array($to, [ShipmentStatus::Returned, ShipmentStatus::Cancelled, ShipmentStatus::Lost], true) && $shipment->cod_status === 'pending') {
            $updates['cod_status'] = 'none'; // no cash was collected
        }
        if ($to->isTerminal() || $to === ShipmentStatus::Damaged) {
            $updates['next_poll_at'] = null;
        }

        $shipment->update($updates);

        $this->recordTrackingEvent($shipment, $to, $carrierEventCode, $description, $location, $source, $actorId, $occurredAt, $eventKey);
        $this->syncOrderFulfillmentStatus($shipment->order);

        // Phase B49: a return shipment the courier collected means the goods are on their way back (Module 09 owns the return).
        if ($shipment->isReturn() && $to === ShipmentStatus::PickedUp && $shipment->return_request_id !== null) {
            $return = \App\Domain\Returns\Models\ReturnRequest::query()->find($shipment->return_request_id);
            if ($return !== null && $return->status === \App\Domain\Returns\Models\ReturnStatus::Approved) {
                app(\App\Domain\Returns\Services\ReturnService::class)->markInTransit($return, ['carrier' => app(ShippingLabelService::class)->carrierName($shipment), 'tracking_number' => $shipment->tracking_number], null);
            }
        }
        $this->outbox->recordEvent('shipment.status_changed', ['shipment_id' => $shipment->id, 'status' => $to->value, 'direction' => $shipment->direction], "shipment:{$shipment->id}:status:{$to->value}:".$shipment->trackingEvents()->count());
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
        ?string $eventKey = null,
    ): void {
        \App\Domain\Shipping\Models\ShipmentTrackingEvent::query()->create([
            'shipment_id' => $shipment->id,
            'status' => $status,
            'carrier_event_code' => $carrierEventCode,
            'event_key' => $eventKey,
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
            ->whereHas('shipment', fn ($q) => $q->where('status', '!=', ShipmentStatus::Cancelled->value)) // Phase B49: a cancelled shipment ships nothing
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
            // Phase B49: no held units (an older order whose hold had expired before
            // B49 stopped that, or a reservation used up): the units still leave the
            // shelf. Before, nothing was taken off stock here.
            if ($inventoryId > 0) {
                $this->inventory->shipUnreserved(\App\Domain\Inventory\Models\Inventory::query()->findOrFail($inventoryId), $quantity, $idempotencyKey);
            }

            return;
        }

        if ($quantity > $reservation->quantity) {
            // Part from the hold, the rest from available stock.
            $held = $reservation->quantity;
            $this->inventory->fulfillReservation($reservation, $held, $idempotencyKey);
            $this->inventory->shipUnreserved(\App\Domain\Inventory\Models\Inventory::query()->findOrFail($inventoryId), $quantity - $held, "{$idempotencyKey}:unreserved");

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

    /**
     * Phase B49: the cash a courier or rider must collect on this order's next
     * parcel — what the cash-on-delivery payment still lacks, less what other
     * open parcels already collect. Never typed by staff.
     */
    private function codToCollect(Order $order): int
    {
        $payment = \App\Domain\Payments\Models\Payment::query()->where('order_id', $order->id)->first();
        if ($payment === null || $payment->method !== PaymentMethod::CashOnDelivery || ! in_array($payment->status, [\App\Domain\Payments\Models\PaymentStatus::Pending, \App\Domain\Payments\Models\PaymentStatus::Created], true)) {
            return 0;
        }
        $open = (int) Shipment::query()->where('order_id', $order->id)->where('direction', Shipment::OUTBOUND)
            ->whereIn('cod_status', ['pending', 'to_remit', 'remitted'])->sum('cod_amount_minor');

        return max(0, $payment->amount_minor - $payment->paidAmountMinor() - $open);
    }

    private function syncOrderFulfillmentStatus(Order $order): void
    {
        $totalOrdered = $order->items()->sum('quantity');
        $totalShipped = (int) \App\Domain\Shipping\Models\ShipmentItem::query()
            ->whereIn('order_item_id', $order->items()->pluck('id'))
            ->whereHas('shipment', fn ($q) => $q->where('status', '!=', ShipmentStatus::Cancelled->value))
            ->sum('quantity');

        $status = match (true) {
            $totalShipped <= 0 => FulfillmentStatus::Unfulfilled,
            $totalShipped < $totalOrdered => FulfillmentStatus::PartiallyFulfilled,
            default => FulfillmentStatus::Fulfilled,
        };

        $this->orders->syncFulfillmentStatus($order, $status);
    }
}
