<?php

declare(strict_types=1);

namespace App\Domain\Returns\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderReturnStatus;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\Models\ReturnItem;
use App\Domain\Returns\Models\ReturnMethod;
use App\Domain\Returns\Models\ReturnReason;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnResolution;
use App\Domain\Returns\Models\ReturnStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 09 §45–54, Module 13 §70 (Phase B33, gap G8): the return of
 * delivered goods, from the request to the refund or the replacement
 * order.
 *
 * Reused, never duplicated: OrderService (order summary, timeline,
 * replacement order), InventoryService (stock back in, damaged written
 * off), PaymentService (refund with the refundable balance under a row
 * lock, zero-value payment), AuditLogger, the outbox.
 *
 * Rules that hold everywhere here:
 * - Tenant: every row is read under the store scope; the order and the
 *   return rows are locked for the change.
 * - Quantities: a unit can be returned once (ReturnEligibility), checked
 *   again under the order lock, so two requests cannot both take it.
 * - Money: computed on the server from what was paid (RefundCalculator);
 *   the screen sends no amount except the two staff choices (shipping
 *   refund, restocking fee), each bounded here; the payment can never
 *   return more than it holds (Module 12 §48).
 * - Idempotency (§51): the request, the stock movements, the refund and
 *   the replacement order each have their own key; a repeat changes
 *   nothing.
 * - The order itself is never edited: a return is a record beside it.
 */
final class ReturnService
{
    public function __construct(
        private readonly ReturnStateMachine $states,
        private readonly ReturnEligibility $eligibility,
        private readonly RefundCalculator $calculator,
        private readonly OrderService $orders,
        private readonly InventoryService $inventory,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /**
     * §45: opens a return for some lines of a delivered order.
     *
     * @param list<array{order_item_id: int, quantity: int}> $items
     * @param array{resolution: string, reason: string, description?: ?string, return_method?: ?string} $data
     * @param Customer|User|null $actor the customer asking, the staff member recording it for them, or
     *                                  null for a guest who proved the order is theirs by the emailed link (Phase B34)
     */
    public function request(Order $order, array $items, array $data, Customer|User|null $actor, string $idempotencyKey): ReturnRequest
    {
        if ($existing = ReturnRequest::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $byCustomer = ! $actor instanceof User; // a customer or a guest: bound by the store's setting and return period
        $requestedBy = match (true) {
            $actor instanceof User => 'staff',
            $actor instanceof Customer => 'customer',
            default => 'guest',
        };
        if ($block = $this->eligibility->orderBlock($order)) {
            throw new ReturnActionRefusedException($block, 'order_not_returnable', 422);
        }
        if ($byCustomer && ! $this->eligibility->customersMayRequest()) {
            throw new ReturnActionRefusedException('This store takes return requests by contact only. Please get in touch with the store.', 'returns_by_contact_only', 403);
        }

        $return = DB::transaction(function () use ($order, $items, $data, $actor, $byCustomer, $requestedBy, $idempotencyKey) {
            // The order row is the lock for its returns: quantities are checked and taken under it.
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lines = $this->eligibility->lines($locked);

            $wanted = [];
            foreach ($items as $index => $item) {
                $id = (int) $item['order_item_id'];
                $quantity = (int) $item['quantity'];
                $line = $lines[$id] ?? throw ValidationException::withMessages(["items.{$index}.order_item_id" => 'This is not an item of the order.']);
                if (isset($wanted[$id])) {
                    throw ValidationException::withMessages(["items.{$index}.order_item_id" => 'Each item can be listed once.']);
                }
                if ($quantity < 1 || $quantity > $line['returnable']) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => $line['returnable'] === 0
                        ? "\"{$line['name']}\" cannot be returned: it was not delivered yet, or it is already in a return."
                        : "At most {$line['returnable']} of \"{$line['name']}\" can be returned."]);
                }
                if ($byCustomer && ! $line['window_open']) {
                    throw ValidationException::withMessages(["items.{$index}.order_item_id" => "The return period of {$this->eligibility->windowDays()} days for \"{$line['name']}\" has ended. Please contact the store."]);
                }
                $wanted[$id] = $quantity;
            }
            if ($wanted === []) {
                throw ValidationException::withMessages(['items' => 'Choose at least one item to return.']);
            }

            $number = 'R-'.$locked->order_number.'-'.(ReturnRequest::query()->where('order_id', $locked->id)->count() + 1);
            $return = ReturnRequest::query()->create([
                'order_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'return_number' => $number,
                'status' => ReturnStatus::Requested,
                'resolution' => ReturnResolution::from($data['resolution']),
                'reason' => ReturnReason::from($data['reason']),
                'description' => $data['description'] ?? null,
                'return_method' => isset($data['return_method']) ? ReturnMethod::from($data['return_method']) : null,
                'requested_by' => $requestedBy,
                'requested_by_user_id' => $actor instanceof User ? $actor->id : null,
                'currency' => $locked->currency,
                'idempotency_key' => $idempotencyKey,
            ]);
            foreach ($wanted as $id => $quantity) {
                $return->items()->create(['order_item_id' => $id, 'quantity' => $quantity]);
            }

            $this->orders->noteReturnEvent($locked, 'return_requested', $actor instanceof User ? $actor->id : null, "return:{$return->return_number}");
            $this->syncOrder($locked);
            $this->outbox->recordEvent('return.requested', ['return_id' => $return->id, 'order_id' => $locked->id], "return:{$return->id}:requested");

            return $return;
        });

        $this->audit->record('return.requested', ['order' => $order->order_number, 'items' => count($items), 'resolution' => $data['resolution'], 'by' => $requestedBy], $return, actor: $actor);

        return $return->load('items.orderItem');
    }

    public function startReview(ReturnRequest $return, User $actor): ReturnRequest
    {
        return $this->change($return, ReturnStatus::UnderReview, $actor, 'return.under_review');
    }

    /**
     * §46 approved: the customer may send the goods back.
     *
     * @param array{note?: ?string, return_method?: ?string, return_shipping_paid_by?: ?string} $data
     */
    public function approve(ReturnRequest $return, array $data, User $actor): ReturnRequest
    {
        return $this->change($return, ReturnStatus::Approved, $actor, 'return.approved', [
            'decided_by_user_id' => $actor->id,
            'decided_at' => now(),
            'decision_note' => $data['note'] ?? null,
            'return_method' => isset($data['return_method']) ? ReturnMethod::from($data['return_method']) : $return->return_method,
            'return_shipping_paid_by' => $data['return_shipping_paid_by'] ?? $return->return_shipping_paid_by,
        ]);
    }

    /** §46 rejected: with the reason the customer is told. */
    public function reject(ReturnRequest $return, string $note, User $actor): ReturnRequest
    {
        return $this->change($return, ReturnStatus::Rejected, $actor, 'return.rejected', [
            'decided_by_user_id' => $actor->id, 'decided_at' => now(), 'decision_note' => $note,
        ]);
    }

    /** Withdrawn by the customer or by staff, as long as the goods were not received. */
    public function cancel(ReturnRequest $return, Customer|User|null $actor, ?string $note = null): ReturnRequest
    {
        return $this->change($return, ReturnStatus::Cancelled, $actor, 'return.cancelled', [
            'cancelled_at' => now(),
            ...($actor instanceof User && $note !== null ? ['decision_note' => $note] : []),
        ]);
    }

    /**
     * Module 13 §70: the parcel is on its way back.
     *
     * @param array{carrier?: ?string, tracking_number?: ?string} $data
     */
    public function markInTransit(ReturnRequest $return, array $data, Customer|User|null $actor): ReturnRequest
    {
        return $this->change($return, ReturnStatus::InTransit, $actor, 'return.in_transit', [
            'return_carrier' => $data['carrier'] ?? null,
            'return_tracking_number' => $data['tracking_number'] ?? null,
            'shipped_back_at' => now(),
        ]);
    }

    /** The goods arrived at a warehouse of the store; nothing is counted into stock before the inspection. */
    public function receive(ReturnRequest $return, ?Warehouse $warehouse, User $actor): ReturnRequest
    {
        $warehouse ??= Warehouse::query()->where('is_default', true)->firstOrFail();

        return $this->change($return, ReturnStatus::Received, $actor, 'return.received', [
            'received_at' => now(), 'warehouse_id' => $warehouse->id,
        ]);
    }

    /**
     * §48 "Return Inspection": per line, how many units are resalable
     * (back into stock), damaged (received, written off) or rejected
     * (not accepted; they go back to the customer). The three add up to
     * the quantity of the line. Stock moves here, each movement with its
     * own idempotency key.
     *
     * @param list<array{order_item_id: int, resalable: int, damaged: int, rejected: int, note?: ?string}> $results
     */
    public function inspect(ReturnRequest $return, array $results, User $actor, ?string $note = null): ReturnRequest
    {
        $inspected = DB::transaction(function () use ($return, $results, $actor, $note) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            $this->states->assertCanTransition($locked->status, ReturnStatus::Inspected);

            $byItem = collect($results)->keyBy(fn (array $row) => (int) $row['order_item_id']);
            $items = $locked->items()->with('orderItem')->get();
            foreach ($items as $index => $item) {
                /** @var ReturnItem $item */
                $row = $byItem->get($item->order_item_id) ?? throw ValidationException::withMessages(['items' => "Say what became of \"{$item->orderItem->product_name_snapshot}\"."]);
                $resalable = (int) $row['resalable'];
                $damaged = (int) $row['damaged'];
                $rejected = (int) $row['rejected'];
                if (min($resalable, $damaged, $rejected) < 0 || $resalable + $damaged + $rejected !== $item->quantity) {
                    throw ValidationException::withMessages(["items.{$index}" => "The three quantities of \"{$item->orderItem->product_name_snapshot}\" must add up to {$item->quantity}."]);
                }

                $item->update([
                    'resalable_quantity' => $resalable, 'damaged_quantity' => $damaged, 'rejected_quantity' => $rejected,
                    'inspection_note' => $row['note'] ?? null,
                ]);

                if ($resalable + $damaged > 0) {
                    $inventory = $this->inventoryFor($item->orderItem, (int) $locked->warehouse_id);
                    if ($inventory !== null) {
                        $this->inventory->receiveReturn($inventory, $resalable, $damaged, $locked->id, $actor->id, "return:{$locked->id}:item:{$item->id}");
                    }
                }
            }
            if ($byItem->keys()->diff($items->pluck('order_item_id'))->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => 'An item is listed that is not in this return.']);
            }

            $accepted = $items->sum(fn (ReturnItem $item) => $item->acceptedQuantity());
            $refunds = $this->calculator->forItems($locked->order, $items->mapWithKeys(fn (ReturnItem $item) => [$item->order_item_id => $item->acceptedQuantity()])->all());
            foreach ($items as $item) {
                $item->update(['refund_minor' => $refunds[$item->order_item_id] ?? 0]);
            }

            $locked->forceFill([
                'inspected_at' => now(), 'inspected_by_user_id' => $actor->id,
                'items_refund_minor' => array_sum($refunds),
            ])->save();
            $this->transition($locked, ReturnStatus::Inspected, $actor);

            // Nothing was accepted: the return ends as rejected, with the reason for the customer.
            if ($accepted === 0) {
                $locked->forceFill(['decision_note' => $note ?? 'None of the returned items could be accepted.', 'decided_by_user_id' => $actor->id, 'decided_at' => now()])->save();
                $this->transition($locked, ReturnStatus::Rejected, $actor);
                $this->outbox->recordEvent('return.rejected', ['return_id' => $locked->id, 'order_id' => $locked->order_id], "return:{$locked->id}:rejected");
            }

            $this->syncOrder($locked->order);

            return $locked;
        });

        $this->audit->record('return.inspected', [
            'resalable' => (int) $inspected->items()->sum('resalable_quantity'),
            'damaged' => (int) $inspected->items()->sum('damaged_quantity'),
            'rejected' => (int) $inspected->items()->sum('rejected_quantity'),
        ], $inspected, actor: $actor);

        return $inspected->load('items.orderItem');
    }

    /**
     * §49–50: the refund is decided. The items amount is the server's
     * (RefundCalculator, set at inspection); staff add a shipping refund
     * (never more than the order's shipping) and may deduct a restocking
     * fee (never more than the items amount).
     */
    public function approveRefund(ReturnRequest $return, int $shippingRefundMinor, int $restockingFeeMinor, User $actor, bool $asStoreCredit = false): ReturnRequest
    {
        $approved = DB::transaction(function () use ($return, $shippingRefundMinor, $restockingFeeMinor, $actor, $asStoreCredit) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            $this->states->assertCanTransition($locked->status, ReturnStatus::ApprovedForRefund);
            if ($locked->replacement_order_id !== null && $locked->items_refund_minor === 0) {
                throw new ReturnActionRefusedException('This return was settled with a replacement order. There is nothing left to refund.', 'nothing_to_refund');
            }

            // Module 09 §52 (Phase B34): the refund may be given as store credit, to a customer who can spend it.
            if ($asStoreCredit && ! $this->storeCreditPossible($locked)) {
                throw ValidationException::withMessages(['as_store_credit' => 'Store credit needs a customer with an account. Refund this return to the payment instead.']);
            }

            $order = $locked->order;
            $shippingAlreadyRefunded = (int) ReturnRequest::query()->where('order_id', $order->id)->whereKeyNot($locked->id)
                ->whereIn('status', [ReturnStatus::ApprovedForRefund->value, ReturnStatus::Completed->value])->sum('shipping_refund_minor');
            $shippingLeft = max(0, (int) $order->shipping_total_minor - $shippingAlreadyRefunded);
            if ($shippingRefundMinor < 0 || $shippingRefundMinor > $shippingLeft) {
                throw ValidationException::withMessages(['shipping_refund_minor' => "The shipping refund can be at most what is left of the order's shipping ({$shippingLeft} in minor units)."]);
            }
            if ($restockingFeeMinor < 0 || $restockingFeeMinor > $locked->items_refund_minor) {
                throw ValidationException::withMessages(['restocking_fee_minor' => 'The restocking fee cannot be more than the value of the returned items.']);
            }

            $locked->forceFill([
                'resolution' => $locked->replacement_order_id === null ? ReturnResolution::Refund : $locked->resolution,
                'shipping_refund_minor' => $shippingRefundMinor,
                'restocking_fee_minor' => $restockingFeeMinor,
                'refund_total_minor' => $locked->items_refund_minor + $shippingRefundMinor - $restockingFeeMinor,
                'refund_method' => $asStoreCredit ? 'store_credit' : 'payment',
            ])->save();
            $this->transition($locked, ReturnStatus::ApprovedForRefund, $actor);

            return $locked;
        });

        $this->audit->record('return.refund_approved', [
            'items_minor' => $approved->items_refund_minor, 'shipping_minor' => $approved->shipping_refund_minor,
            'restocking_fee_minor' => $approved->restocking_fee_minor, 'total_minor' => $approved->refund_total_minor, 'method' => $approved->refund_method, 'currency' => $approved->currency,
        ], $approved, actor: $actor);

        return $approved->load('items.orderItem');
    }

    /**
     * §49, §51: pays the approved refund back and completes the return.
     *
     * Where the money goes (Module 12 §13, Phase B34):
     * - first to the order's payment, as far as it can return (its
     *   refundable balance, under its own row lock);
     * - what the customer had paid with store credit goes back to their
     *   store credit, never as cash, and never more than was used;
     * - if staff chose "as store credit", the payment's part is credited
     *   too: it is recorded on the payment (so it cannot also be refunded
     *   in cash) and added to the customer's balance.
     * An order that was never paid has nothing to return; the return is
     * completed with what was actually refunded on record.
     */
    public function refund(ReturnRequest $return, User $actor): ReturnRequest
    {
        $done = DB::transaction(function () use ($return, $actor) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            $this->states->assertCanTransition($locked->status, ReturnStatus::Completed);
            if ($locked->status !== ReturnStatus::ApprovedForRefund) {
                throw new ReturnActionRefusedException('Approve the refund of this return first.', 'refund_not_approved');
            }

            $order = $locked->order;
            $asCredit = $locked->refund_method === 'store_credit';
            $payment = Payment::query()->where('order_id', $locked->order_id)->lockForUpdate()->first();
            $fromPayment = min($locked->refund_total_minor, $payment?->refundableAmountMinor() ?? 0);
            $transaction = null;
            if ($payment !== null && $fromPayment > 0) {
                $transaction = $this->payments->refund($payment, $fromPayment, "Return {$locked->return_number}".($asCredit ? ' (given as store credit)' : ''), $actor->id, "return:{$locked->id}:refund");
            }

            // The part of the order that was paid with store credit, not yet given back by another return.
            $creditLeft = max(0, (int) $order->store_credit_minor - $this->creditPartReturned($order, $locked->id));
            $backToCredit = min($locked->refund_total_minor - $fromPayment, $creditLeft);
            $credited = $backToCredit + ($asCredit ? $fromPayment : 0);
            if ($credited > 0 && $order->customer !== null) {
                app(\App\Domain\StoreCredit\Services\StoreCreditService::class)->credit(
                    $order->customer, $locked->currency, $credited, \App\Domain\StoreCredit\Models\StoreCreditEntryType::ReturnRefund,
                    "return:{$locked->id}:store-credit", ['type' => 'return', 'id' => $locked->id], "Return {$locked->return_number}", $actor->id,
                );
            } else {
                $credited = 0;
            }

            $locked->forceFill([
                'refunded_minor' => $fromPayment, 'refunded_credit_minor' => $credited, 'refund_transaction_id' => $transaction?->id,
                'refunded_at' => now(), 'completed_at' => now(),
            ])->save();
            $this->transition($locked, ReturnStatus::Completed, $actor);
            $this->orders->noteReturnEvent($order, 'return_refunded', $actor->id, "return:{$locked->return_number}", "refunded_minor:{$fromPayment};store_credit_minor:{$credited}");
            $this->syncOrder($order);
            $this->outbox->recordEvent('return.refunded', ['return_id' => $locked->id, 'order_id' => $locked->order_id, 'amount_minor' => $fromPayment, 'store_credit_minor' => $credited], "return:{$locked->id}:refunded");

            return $locked;
        });

        $this->audit->record('return.refunded', [
            'approved_minor' => $done->refund_total_minor, 'from_payment_minor' => $done->refunded_minor,
            'to_store_credit_minor' => $done->refunded_credit_minor, 'method' => $done->refund_method, 'currency' => $done->currency,
        ], $done, actor: $actor);

        return $done->load('items.orderItem');
    }

    /**
     * How much of the store credit used on this order other returns have
     * already given back. For a return paid "as store credit" the
     * payment's part is in `refunded_credit_minor` too and is not counted.
     */
    private function creditPartReturned(Order $order, int $exceptReturnId): int
    {
        return (int) ReturnRequest::query()->where('order_id', $order->id)->whereKeyNot($exceptReturnId)->get()
            ->sum(fn (ReturnRequest $other) => (int) $other->refunded_credit_minor - ($other->refund_method === 'store_credit' ? (int) $other->refunded_minor : 0));
    }

    /**
     * Whether this return's refund may be given as store credit: the order
     * belongs to a customer with an account who can use it (credit is
     * spent at a signed-in checkout).
     */
    public function storeCreditPossible(ReturnRequest $return): bool
    {
        $customer = $return->order->customer;

        return $customer !== null && $customer->isRegistered() && $customer->erased_at === null;
    }

    /**
     * §53–54: the replacement (same items, no charge) or exchange (other
     * items; the value of the returned ones counts towards them) order.
     *
     * - The new order is a normal order (OrderService): priced by the
     *   server from the catalog, stock reserved, linked to the original.
     * - Replacement: the accepted units again; the order is free.
     * - Exchange: the items staff choose; the returned value is taken off.
     *   If they cost more, the customer pays the difference by the chosen
     *   method; if less, the rest is left on the return to be refunded.
     * - It is sent without a shipping charge.
     *
     * @param list<array{product_id?: ?int, product_variant_id?: ?int, quantity: int}>|null $items for an exchange
     */
    public function createReplacement(ReturnRequest $return, ReturnResolution $resolution, ?array $items, ?PaymentMethod $paymentMethod, User $actor): ReturnRequest
    {
        if (! $resolution->needsNewOrder()) {
            throw new ReturnActionRefusedException('Choose replacement or exchange.', 'bad_resolution', 422);
        }

        $result = DB::transaction(function () use ($return, $resolution, $items, $paymentMethod, $actor) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->status !== ReturnStatus::Inspected) {
                throw new ReturnActionRefusedException('A replacement order is made after the returned goods were inspected.', 'not_inspected');
            }
            if ($locked->replacement_order_id !== null) {
                return $locked; // idempotent
            }

            $order = $locked->order;
            $accepted = $locked->items()->with('orderItem')->get()->filter(fn (ReturnItem $item) => $item->acceptedQuantity() > 0);
            $credit = $locked->items_refund_minor;

            if ($resolution === ReturnResolution::Replacement) {
                $items = $accepted->map(function (ReturnItem $item) {
                    $line = $item->orderItem;
                    if ($line->product_id === null && $line->product_variant_id === null) {
                        throw new ReturnActionRefusedException("\"{$line->product_name_snapshot}\" is no longer in the catalog. Refund it, or exchange it for another product.", 'product_gone', 422);
                    }

                    return $line->product_variant_id !== null
                        ? ['product_variant_id' => $line->product_variant_id, 'quantity' => $item->acceptedQuantity()]
                        : ['product_id' => $line->product_id, 'quantity' => $item->acceptedQuantity()];
                })->values()->all();
            }
            if ($items === null || $items === []) {
                throw ValidationException::withMessages(['items' => 'Choose the products the customer gets instead.']);
            }

            $subtotal = $this->catalogSubtotal($items);
            $applied = $resolution === ReturnResolution::Replacement ? $subtotal : min($credit, $subtotal);
            $due = $subtotal - $applied;
            if ($due > 0 && $paymentMethod === null) {
                throw ValidationException::withMessages(['payment_method' => 'The chosen products cost more than what was returned. Choose how the customer pays the difference.']);
            }

            $original = Payment::query()->where('order_id', $order->id)->first();
            $new = $this->orders->createOrder($items, [
                'customer_id' => $order->customer_id,
                'guest_name' => $order->guest_name, 'guest_email' => $order->guest_email, 'guest_phone' => $order->guest_phone,
                'billing_address' => $order->billing_address_snapshot, 'shipping_address' => $order->shipping_address_snapshot,
                'source' => 'replacement',
                'notes' => ucfirst($resolution->value)." for order {$order->order_number} (return {$locked->return_number}).",
                'discount_total_minor' => $applied,
            ], "return:{$locked->id}:replacement");
            $this->orders->linkReplacement($new, $order);

            // Every order needs its payment record to be shipped; a free one is settled at once (Module 12 §14).
            $method = $due > 0 ? $paymentMethod : ($original->method ?? PaymentMethod::CashOnDelivery);
            $this->payments->createForOrder($new, $method, "return:{$locked->id}:replacement:payment");

            $leftover = $resolution === ReturnResolution::Exchange ? $credit - $applied : 0;
            $locked->forceFill([
                'resolution' => $resolution,
                'replacement_order_id' => $new->id,
                // What is still owed in money after the new order took its part.
                'items_refund_minor' => $leftover,
            ])->save();
            $this->orders->noteReturnEvent($order, 'return_replacement_created', $actor->id, "return:{$locked->return_number}", "order:{$new->order_number}");

            if ($leftover === 0) {
                $locked->forceFill(['completed_at' => now()])->save();
                $this->transition($locked, ReturnStatus::Completed, $actor);
            }
            $this->syncOrder($order);

            return $locked;
        });

        $this->audit->record('return.replacement_created', [
            'resolution' => $resolution->value, 'order' => $result->replacementOrder?->order_number, 'left_to_refund_minor' => $result->items_refund_minor,
        ], $result, actor: $actor);

        return $result->load(['items.orderItem', 'replacementOrder']);
    }

    /**
     * The order's summary of its returns (OrderReturnStatus), from what is
     * true now: nothing, something open, part or all of the order accepted back.
     */
    public function syncOrder(Order $order): void
    {
        $returns = ReturnRequest::query()->where('order_id', $order->id)->get();
        $accepted = (int) ReturnItem::query()
            ->whereIn('return_request_id', $returns->filter(fn (ReturnRequest $r) => in_array($r->status, [ReturnStatus::Inspected, ReturnStatus::ApprovedForRefund, ReturnStatus::Completed], true))->pluck('id'))
            ->selectRaw('COALESCE(SUM(resalable_quantity + damaged_quantity), 0) as total')->value('total');
        $ordered = (int) $order->items()->sum('quantity');
        $open = $returns->contains(fn (ReturnRequest $r) => ! $r->status->isClosed());

        $this->orders->syncReturnStatus($order, match (true) {
            $accepted > 0 && $accepted >= $ordered => OrderReturnStatus::Returned,
            $accepted > 0 => OrderReturnStatus::PartiallyReturned,
            $open => OrderReturnStatus::Requested,
            default => OrderReturnStatus::None,
        });
    }

    /**
     * One guarded status change with its fields, timeline entry, order
     * summary, outbox event and audit entry.
     *
     * @param array<string, mixed> $fields
     */
    private function change(ReturnRequest $return, ReturnStatus $to, Customer|User|null $actor, string $event, array $fields = []): ReturnRequest
    {
        $changed = DB::transaction(function () use ($return, $to, $actor, $event, $fields) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            $this->states->assertCanTransition($locked->status, $to);
            $locked->forceFill($fields)->save();
            $this->transition($locked, $to, $actor);
            $this->syncOrder($locked->order);
            $this->outbox->recordEvent($event, ['return_id' => $locked->id, 'order_id' => $locked->order_id], "return:{$locked->id}:{$to->value}");

            return $locked;
        });

        $this->audit->record($event, ['status' => $to->value, 'by' => match (true) { $actor instanceof User => 'staff', $actor instanceof Customer => 'customer', default => 'guest' }], $changed, actor: $actor);

        return $changed->load('items.orderItem');
    }

    /** Writes the status (after the state machine agreed) and notes it on the order's timeline. */
    private function transition(ReturnRequest $return, ReturnStatus $to, Customer|User|null $actor): void
    {
        $this->states->assertCanTransition($return->status, $to);
        $return->forceFill(['status' => $to])->save();
        $this->orders->noteReturnEvent($return->order, 'return_'.$to->value, $actor instanceof User ? $actor->id : null, "return:{$return->return_number}");
    }

    /** The stock row of the returned product in the warehouse that received it; created empty if the product was never stocked there. */
    private function inventoryFor(OrderItem $line, int $warehouseId): ?Inventory
    {
        if ($line->product_id === null && $line->product_variant_id === null) {
            return null; // the product was deleted since: nothing to put back
        }

        return Inventory::query()->firstOrCreate(
            $line->product_variant_id !== null
                ? ['warehouse_id' => $warehouseId, 'product_variant_id' => $line->product_variant_id]
                : ['warehouse_id' => $warehouseId, 'product_id' => $line->product_id, 'product_variant_id' => null],
        );
    }

    /**
     * What these items cost in the catalog now, as OrderService will price them.
     *
     * @param list<array{product_id?: ?int, product_variant_id?: ?int, quantity: int}> $items
     */
    private function catalogSubtotal(array $items): int
    {
        $subtotal = 0;
        foreach ($items as $index => $item) {
            $price = ! empty($item['product_variant_id'])
                ? \App\Domain\Catalog\Models\ProductVariant::query()->find($item['product_variant_id'])?->effectivePriceMinor()
                : \App\Domain\Catalog\Models\Product::query()->find($item['product_id'] ?? 0)?->effectivePriceMinor();
            if ($price === null) {
                throw ValidationException::withMessages(["items.{$index}" => 'This product is not in the catalog or has no price.']);
            }
            $subtotal += $price * (int) $item['quantity'];
        }

        return $subtotal;
    }
}
