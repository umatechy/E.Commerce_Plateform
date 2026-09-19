<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Shipping\Exceptions\InvalidShipmentStateTransitionException;
use App\Domain\Shipping\Models\ShipmentStatus;
use App\Domain\Shipping\Services\ShipmentStateMachine;
use Tests\TestCase;

/**
 * Phase B8 — Shipment state machine (Module 13 §49, Step 12). Pure
 * unit tests — no database required.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ShipmentStateMachineTest extends TestCase
{
    public function test_draft_to_ready_is_valid(): void
    {
        (new ShipmentStateMachine())->assertCanTransition(ShipmentStatus::Draft, ShipmentStatus::Ready);
        $this->assertTrue(true);
    }

    public function test_label_created_to_picked_up_is_valid(): void
    {
        (new ShipmentStateMachine())->assertCanTransition(ShipmentStatus::LabelCreated, ShipmentStatus::PickedUp);
        $this->assertTrue(true);
    }

    public function test_in_transit_to_out_for_delivery_is_valid(): void
    {
        (new ShipmentStateMachine())->assertCanTransition(ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery);
        $this->assertTrue(true);
    }

    public function test_delivered_is_terminal(): void
    {
        $this->expectException(InvalidShipmentStateTransitionException::class);
        (new ShipmentStateMachine())->assertCanTransition(ShipmentStatus::Delivered, ShipmentStatus::InTransit);
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->expectException(InvalidShipmentStateTransitionException::class);
        (new ShipmentStateMachine())->assertCanTransition(ShipmentStatus::Cancelled, ShipmentStatus::Ready);
    }

    public function test_cannot_skip_directly_from_draft_to_delivered(): void
    {
        $this->expectException(InvalidShipmentStateTransitionException::class);
        (new ShipmentStateMachine())->assertCanTransition(ShipmentStatus::Draft, ShipmentStatus::Delivered);
    }
}
