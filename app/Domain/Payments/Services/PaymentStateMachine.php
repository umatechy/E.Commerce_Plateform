<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Exceptions\InvalidPaymentStateTransitionException;
use App\Domain\Payments\Models\PaymentStatus;

/**
 * Module 12 §7, this milestone's Step 3. The ONLY place a Payment's
 * status transition validity is decided — mirrors
 * App\Domain\Orders\Services\OrderStateMachine's exact pattern from
 * Phase B5 (centralize transitions; no controller/webhook-only
 * enforcement).
 */
final class PaymentStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'created' => ['pending', 'requires_action', 'cancelled', 'paid'], // 'paid' only reached directly for Module 12 §14 zero-value orders (PaymentService::createForOrder())
        'pending' => ['authorized', 'paid', 'failed', 'cancelled', 'expired'],
        'requires_action' => ['authorized', 'paid', 'failed', 'cancelled', 'expired'],
        'authorized' => ['paid', 'failed', 'cancelled'],
        'paid' => ['refund_pending', 'partially_refunded', 'refunded', 'disputed', 'reversed'],
        'partially_paid' => ['paid', 'failed', 'refund_pending'],
        'refund_pending' => ['partially_refunded', 'refunded'],
        'partially_refunded' => ['refund_pending', 'refunded'],
        // failed/cancelled/expired/refunded/disputed/reversed are
        // terminal — no outgoing transitions registered.
    ];

    /**
     * @throws InvalidPaymentStateTransitionException
     */
    public function assertCanTransition(PaymentStatus $from, PaymentStatus $to): void
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new InvalidPaymentStateTransitionException($from, $to);
        }
    }
}
