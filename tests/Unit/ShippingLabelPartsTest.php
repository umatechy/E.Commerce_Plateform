<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Shipping\Models\ShipmentStatus;
use App\Domain\Shipping\Services\ShipmentStateMachine;
use App\Support\Barcode\Code128;
use PHPUnit\Framework\TestCase;

/**
 * Phase B49: the pure parts behind labels and tracking — the Code 128
 * encoder and the shortest allowed path between shipment statuses.
 */
final class ShippingLabelPartsTest extends TestCase
{
    public function test_the_code_128_table_is_complete_and_every_symbol_has_its_width(): void
    {
        $patterns = Code128::patterns();
        $this->assertCount(107, $patterns);
        $this->assertCount(107, array_unique($patterns));
        foreach ($patterns as $value => $pattern) {
            $this->assertSame($value === 106 ? 13 : 11, array_sum(str_split($pattern)), "symbol {$value}");
        }
    }

    public function test_code_128_b_adds_start_check_and_stop(): void
    {
        // "AB": start B 104; A = 33, B = 34; check = (104 + 33×1 + 34×2) mod 103 = 205 mod 103 = 102.
        $this->assertSame([104, 33, 34, 102, 106], Code128::values('AB'));
        $widths = Code128::widths('AB');
        $this->assertSame(11 * 4 + 13, array_sum($widths));
        $this->assertStringStartsWith('<svg', Code128::svg('TCD123ABCD'));
        $this->expectException(\InvalidArgumentException::class);
        Code128::values("Ä");
    }

    public function test_the_state_machine_finds_the_steps_a_courier_skipped(): void
    {
        $machine = new ShipmentStateMachine();
        $this->assertSame([ShipmentStatus::PickedUp, ShipmentStatus::Delivered], $machine->path(ShipmentStatus::LabelCreated, ShipmentStatus::Delivered));
        $this->assertSame([ShipmentStatus::DeliveryFailed, ShipmentStatus::Returning, ShipmentStatus::Returned], $machine->path(ShipmentStatus::OutForDelivery, ShipmentStatus::Returned));
        $this->assertSame([], $machine->path(ShipmentStatus::InTransit, ShipmentStatus::InTransit));
        $this->assertNull($machine->path(ShipmentStatus::Delivered, ShipmentStatus::InTransit), 'nothing moves a delivered parcel back');
    }
}
