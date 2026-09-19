<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Payments\Exceptions\InvalidPaymentStateTransitionException;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Payments\Services\PaymentStateMachine;
use Tests\TestCase;

/**
 * Phase B7 — Payment state machine (Module 12 §7, Step 3). Pure unit
 * test — no database required.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class PaymentStateMachineTest extends TestCase
{
    public function test_pending_to_paid_is_a_valid_transition(): void
    {
        (new PaymentStateMachine())->assertCanTransition(PaymentStatus::Pending, PaymentStatus::Paid);
        $this->assertTrue(true);
    }

    public function test_created_to_paid_is_valid_for_zero_value_orders(): void
    {
        (new PaymentStateMachine())->assertCanTransition(PaymentStatus::Created, PaymentStatus::Paid);
        $this->assertTrue(true);
    }

    public function test_paid_to_refunded_is_a_valid_transition(): void
    {
        (new PaymentStateMachine())->assertCanTransition(PaymentStatus::Paid, PaymentStatus::Refunded);
        $this->assertTrue(true);
    }

    public function test_failed_is_terminal_and_has_no_outgoing_transitions(): void
    {
        $this->expectException(InvalidPaymentStateTransitionException::class);
        (new PaymentStateMachine())->assertCanTransition(PaymentStatus::Failed, PaymentStatus::Paid);
    }

    public function test_refunded_is_terminal(): void
    {
        $this->expectException(InvalidPaymentStateTransitionException::class);
        (new PaymentStateMachine())->assertCanTransition(PaymentStatus::Refunded, PaymentStatus::Paid);
    }

    public function test_cannot_go_directly_from_created_to_refunded(): void
    {
        $this->expectException(InvalidPaymentStateTransitionException::class);
        (new PaymentStateMachine())->assertCanTransition(PaymentStatus::Created, PaymentStatus::Refunded);
    }
}
