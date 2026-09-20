<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Exceptions\InvalidNotificationStateTransitionException;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Notifications\Services\NotificationStateMachine;
use Tests\TestCase;

/**
 * Phase B11 — Notification delivery state machine (Module 21 §27,
 * Step 22). Pure unit tests — no database required.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class NotificationStateMachineTest extends TestCase
{
    public function test_created_to_queued_is_valid(): void
    {
        (new NotificationStateMachine())->assertCanTransition(NotificationStatus::Created, NotificationStatus::Queued);
        $this->assertTrue(true);
    }

    public function test_processing_to_retry_pending_is_valid(): void
    {
        // Regression test for the exact bug found and fixed in B11
        // (see b11-inspection-findings.md "Bug Found and Fixed") — a
        // retryable delivery failure must be able to transition
        // directly from Processing.
        (new NotificationStateMachine())->assertCanTransition(NotificationStatus::Processing, NotificationStatus::RetryPending);
        $this->assertTrue(true);
    }

    public function test_retry_pending_to_processing_is_valid(): void
    {
        (new NotificationStateMachine())->assertCanTransition(NotificationStatus::RetryPending, NotificationStatus::Processing);
        $this->assertTrue(true);
    }

    public function test_sent_to_delivered_is_valid(): void
    {
        (new NotificationStateMachine())->assertCanTransition(NotificationStatus::Sent, NotificationStatus::Delivered);
        $this->assertTrue(true);
    }

    public function test_delivered_is_terminal(): void
    {
        $this->expectException(InvalidNotificationStateTransitionException::class);
        (new NotificationStateMachine())->assertCanTransition(NotificationStatus::Delivered, NotificationStatus::Sent);
    }

    public function test_suppressed_is_terminal(): void
    {
        $this->expectException(InvalidNotificationStateTransitionException::class);
        (new NotificationStateMachine())->assertCanTransition(NotificationStatus::Suppressed, NotificationStatus::Queued);
    }

    public function test_cannot_skip_directly_from_created_to_delivered(): void
    {
        $this->expectException(InvalidNotificationStateTransitionException::class);
        (new NotificationStateMachine())->assertCanTransition(NotificationStatus::Created, NotificationStatus::Delivered);
    }
}
