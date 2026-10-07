<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Tax\Services\TaxCalculator;
use App\Support\MoneyAllocation;
use PHPUnit\Framework\TestCase;

/**
 * Phase B46 — the tax arithmetic (Module 29 §95–96): integers only, half-up
 * rounding, exact splits, inclusive and exclusive prices, discounts before
 * or after tax, line or order rounding.
 */
final class TaxCalculatorTest extends TestCase
{
    private const GST = ['id' => 1, 'name' => 'Sales tax', 'rate_bps' => 1700];
    private const PROVINCIAL = ['id' => 2, 'name' => 'Provincial', 'rate_bps' => 100];

    /** @param array<string, mixed> $policy */
    private function calc(array $lines, int $orderDiscount = 0, array $shipping = ['amount_minor' => 0, 'rates' => []], array $policy = []): array
    {
        return (new TaxCalculator())->calculate($lines, $orderDiscount, $shipping, [
            'prices_include_tax' => false, 'discount_basis' => 'before_tax', 'rounding' => 'line', ...$policy,
        ]);
    }

    private function line(string $key, int $gross, array $rates, int $discount = 0): array
    {
        return ['key' => $key, 'gross_minor' => $gross, 'discount_minor' => $discount, 'rates' => $rates];
    }

    public function test_exclusive_tax_adds_every_matching_rate_rounded_half_up(): void
    {
        $r = $this->calc([$this->line('a', 10000, [self::GST, self::PROVINCIAL]), $this->line('b', 333, [['id' => 3, 'name' => 'Reduced', 'rate_bps' => 1500]])]);

        $this->assertSame(['a' => 1800, 'b' => 50], $r['lines']); // 333 × 15 % = 49.95 → 50
        $this->assertSame(1850, $r['total_minor']);
        $this->assertSame([[1, 10000, 1700], [2, 10000, 100], [3, 333, 50]], array_map(fn ($b) => [$b['rate_id'], $b['base_minor'], $b['tax_minor']], $r['breakdown']));
    }

    public function test_inclusive_prices_hold_the_tax_and_net_plus_tax_is_the_price(): void
    {
        $r = $this->calc([$this->line('a', 11800, [self::GST, self::PROVINCIAL]), $this->line('b', 999, [self::GST])], policy: ['prices_include_tax' => true]);

        $this->assertSame(1800, $r['lines']['a']); // 11 800 = 10 000 net + 1 700 + 100
        $this->assertSame(999 - MoneyAllocation::divideHalfUp(999 * 10000, 11700), $r['lines']['b']);
        $this->assertSame([10000 + 999 - $r['lines']['b'], 1700 + $r['lines']['b']], [$r['breakdown'][0]['base_minor'], $r['breakdown'][0]['tax_minor']]);
        $this->assertSame([10000, 100], [$r['breakdown'][1]['base_minor'], $r['breakdown'][1]['tax_minor']]);
    }

    public function test_an_order_discount_is_spread_over_the_lines_before_tax_or_ignored_after_tax(): void
    {
        $lines = [$this->line('a', 6000, [self::GST]), $this->line('b', 4000, [self::GST], discount: 0)];

        $before = $this->calc($lines, orderDiscount: 1000);
        $this->assertSame(['a' => 918, 'b' => 612], $before['lines']); // bases 5 400 and 3 600
        $after = $this->calc($lines, orderDiscount: 1000, policy: ['discount_basis' => 'after_tax']);
        $this->assertSame(['a' => 1020, 'b' => 680], $after['lines']);

        // A line's own discount lowers its base before tax too.
        $this->assertSame(['a' => 850], $this->calc([$this->line('a', 6000, [self::GST], discount: 1000)])['lines']);
    }

    public function test_order_rounding_rounds_each_rate_once_and_still_adds_up(): void
    {
        $tenPercent = ['id' => 4, 'name' => 'Ten', 'rate_bps' => 1000];
        $lines = [$this->line('a', 105, [$tenPercent]), $this->line('b', 105, [$tenPercent]), $this->line('c', 105, [$tenPercent])];

        $this->assertSame(33, $this->calc($lines)['total_minor']); // 10.5 → 11, three times
        $order = $this->calc($lines, policy: ['rounding' => 'order']);
        $this->assertSame(32, $order['total_minor']); // 31.5 → 32, once
        $this->assertSame(32, array_sum($order['lines']));
    }

    public function test_shipping_is_taxed_only_with_rates_and_lines_without_rates_pay_nothing(): void
    {
        $r = $this->calc([$this->line('a', 1000, [])], shipping: ['amount_minor' => 500, 'rates' => [self::GST]]);
        $this->assertSame([['a' => 0], 85, 85], [$r['lines'], $r['shipping_minor'], $r['total_minor']]);

        $none = $this->calc([$this->line('a', 1000, [])], shipping: ['amount_minor' => 500, 'rates' => []]);
        $this->assertSame([0, []], [$none['total_minor'], $none['breakdown']]);
    }

    public function test_money_allocation_splits_exactly(): void
    {
        $this->assertSame(['x' => 33, 'y' => 33, 'z' => 34], MoneyAllocation::spread(100, ['x' => 1, 'y' => 1, 'z' => 1]));
        $this->assertSame(['x' => 0, 'y' => 7], MoneyAllocation::spread(7, ['x' => 0, 'y' => 5]));
        $this->assertSame(['x' => 0], MoneyAllocation::spread(7, ['x' => 0]));
        $this->assertSame(3, MoneyAllocation::divideHalfUp(5, 2));
        $this->assertSame(2, MoneyAllocation::divideHalfUp(7, 4));
    }
}
