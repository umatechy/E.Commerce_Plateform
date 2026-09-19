<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Exceptions\InvalidOrderStateTransitionException;
use App\Domain\Orders\Exceptions\OrderCancellationNotAllowedException;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;

/**
 * Module 09 §15 "Order State Machine" + this milestone's "Step 5 —
 * Order State Machine" (centralize transition rules; no controller-only
 * enforcement; terminal states cannot be silently changed).
 *
 * This is the ONLY place order.status transition validity is decided.
 * OrderService calls this before ever writing a new status — no
 * controller, Resource, or test bypasses it to set status directly.
 *
 * The exact full state graph for every one of Module 09 §15's 17
 * states is NOT wired here — only the transitions B5's actual scope
 * (creation → confirmation → cancellation) exercises. The remaining
 * states (Processing, Shipped, Delivered, Return*, Refund*, ...) are
 * schema-ready but their OWNING modules (Fulfillment/Shipping/Payments/
 * Returns) are responsible for registering their own valid transitions
 * here when built — this class is designed to be extended with more
 * entries in TRANSITIONS, never bypassed.
 */
final class OrderStateMachine
{
    /** @var array<string, list<string>> from-status => allowed to-statuses */
    private const TRANSITIONS = [
        'draft' => ['pending_confirmation', 'cancelled'],
        'pending_confirmation' => ['confirmed', 'cancelled', 'failed'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['ready_to_fulfill', 'cancelled'],
        'ready_to_fulfill' => ['fulfilling', 'cancelled'],
        'fulfilling' => ['shipped'],
        'shipped' => ['delivered'],
        'delivered' => ['completed', 'return_requested'],
        // Cancelled/Completed/Refunded/Failed are terminal — see
        // OrderStatus::isTerminal(); no outgoing transitions are
        // registered for them here, which IS the enforcement mechanism
        // (an unregistered from-status has no allowed destinations).
    ];

    /** Module 09 §42 "Cancellation Rules" — the module's own worked example, used verbatim. */
    private const CANCELLABLE_STATUSES = [
        OrderStatus::Draft,
        OrderStatus::PendingConfirmation,
        OrderStatus::Confirmed,
        OrderStatus::Processing,
        OrderStatus::ReadyToFulfill,
    ];

    /**
     * @throws InvalidOrderStateTransitionException
     */
    public function assertCanTransition(OrderStatus $from, OrderStatus $to): void
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new InvalidOrderStateTransitionException($from, $to);
        }
    }

    /**
     * @throws OrderCancellationNotAllowedException
     */
    public function assertCancellable(Order $order): void
    {
        if (! in_array($order->status, self::CANCELLABLE_STATUSES, true)) {
            throw new OrderCancellationNotAllowedException($order->status);
        }
    }

    public function isCancellable(Order $order): bool
    {
        return in_array($order->status, self::CANCELLABLE_STATUSES, true);
    }
}
