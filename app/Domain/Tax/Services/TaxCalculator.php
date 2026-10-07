<?php

declare(strict_types=1);

namespace App\Domain\Tax\Services;

use App\Support\MoneyAllocation;

/**
 * Phase B46 — the tax arithmetic, with no database and no settings: the
 * same inputs always give the same result (Module 29 §96 "deterministic and
 * versioned"; Bible §116 "explicit and auditable"). Integers only, rounding
 * half up (Module 29 §95: never binary floating point).
 *
 * Inputs per line: the gross amount (unit price × quantity), the line's own
 * discount and the rates that apply to it; an order-level discount; the
 * shipping amount and its rates (none when shipping is not taxable).
 *
 * Policy:
 *   prices_include_tax  false: tax is added on top of the amounts;
 *                       true:  the amounts already hold the tax, which is
 *                              taken out of them (net = gross × 10 000 ÷
 *                              (10 000 + all rates));
 *   discount_basis      before_tax: tax on the discounted amount (the
 *                       order-level discount is spread over the lines by
 *                       their amounts — the same split refunds use);
 *                       after_tax: tax on the amount before any discount;
 *   rounding            line:  each line rounded on its own;
 *                       order: each rate (and rate group) rounded once over
 *                       the order, then spread back over the lines so the
 *                       line taxes still add up to the total.
 *
 * Several rates on one line add up (they are not compounded).
 *
 * @phpstan-type Rate array{id: int, name: string, rate_bps: int}
 * @phpstan-type Line array{key: string, gross_minor: int, discount_minor: int, rates: list<Rate>}
 * @phpstan-type Policy array{prices_include_tax: bool, discount_basis: string, rounding: string}
 * @phpstan-type Result array{lines: array<string, int>, shipping_minor: int, total_minor: int, breakdown: list<array{rate_id: int, name: string, rate_bps: int, base_minor: int, tax_minor: int}>}
 */
final class TaxCalculator
{
    /** Raised when the arithmetic or its meaning changes; kept on every order (Module 29 §96). */
    public const POLICY_VERSION = 1;

    private const SHIPPING = '__shipping';

    /**
     * @param list<Line> $lines
     * @param array{amount_minor: int, rates: list<Rate>} $shipping
     * @param Policy $policy
     * @return Result
     */
    public function calculate(array $lines, int $orderDiscountMinor, array $shipping, array $policy): array
    {
        $inclusive = $policy['prices_include_tax'];
        $beforeTax = $policy['discount_basis'] !== 'after_tax';

        $net = [];
        foreach ($lines as $line) {
            $net[$line['key']] = max(0, $line['gross_minor'] - $line['discount_minor']);
        }
        $shares = MoneyAllocation::spread(max(0, $orderDiscountMinor), $net);

        /** @var array<string, array{base: int, rates: list<Rate>}> $units */
        $units = [];
        foreach ($lines as $line) {
            $base = $beforeTax ? max(0, $net[$line['key']] - $shares[$line['key']]) : max(0, $line['gross_minor']);
            $units[$line['key']] = ['base' => $base, 'rates' => $line['rates']];
        }
        $units[self::SHIPPING] = ['base' => max(0, $shipping['amount_minor']), 'rates' => $shipping['rates']];

        /** @var array<string, array<int, int>> $taxes unit => rate id => tax */
        $taxes = $policy['rounding'] === 'order' ? $this->roundedPerOrder($units, $inclusive) : $this->roundedPerLine($units, $inclusive);

        $breakdown = [];
        $byUnit = [];
        foreach ($units as $key => $unit) {
            $unitTax = array_sum($taxes[$key] ?? []);
            $byUnit[$key] = $unitTax;
            foreach ($unit['rates'] as $rate) {
                $id = $rate['id'];
                $breakdown[$id] ??= ['rate_id' => $id, 'name' => $rate['name'], 'rate_bps' => $rate['rate_bps'], 'base_minor' => 0, 'tax_minor' => 0];
                // The amount the rate was charged on, without tax.
                $breakdown[$id]['base_minor'] += $inclusive ? $unit['base'] - $unitTax : $unit['base'];
                $breakdown[$id]['tax_minor'] += $taxes[$key][$id] ?? 0;
            }
        }
        ksort($breakdown);
        $shippingTax = $byUnit[self::SHIPPING];
        unset($byUnit[self::SHIPPING]);

        return [
            'lines' => $byUnit,
            'shipping_minor' => $shippingTax,
            'total_minor' => array_sum($byUnit) + $shippingTax,
            'breakdown' => array_values(array_filter($breakdown, fn (array $b) => $b['base_minor'] > 0 || $b['tax_minor'] > 0)),
        ];
    }

    /**
     * @param array<string, array{base: int, rates: list<Rate>}> $units
     * @return array<string, array<int, int>>
     */
    private function roundedPerLine(array $units, bool $inclusive): array
    {
        $taxes = [];
        foreach ($units as $key => $unit) {
            $total = array_sum(array_column($unit['rates'], 'rate_bps'));
            if ($total <= 0 || $unit['base'] === 0) {
                continue;
            }
            if ($inclusive) {
                // Net + tax = the amount exactly; the tax is shared by the rates by their size.
                $tax = $unit['base'] - MoneyAllocation::divideHalfUp($unit['base'] * 10000, 10000 + $total);
                $taxes[$key] = MoneyAllocation::spread($tax, array_column($unit['rates'], 'rate_bps', 'id'));
            } else {
                foreach ($unit['rates'] as $rate) {
                    $taxes[$key][$rate['id']] = MoneyAllocation::divideHalfUp($unit['base'] * $rate['rate_bps'], 10000);
                }
            }
        }

        return $taxes;
    }

    /**
     * Rates are rounded once per rate and denominator (lines with the same
     * rates share one), then spread back over the lines by their exact share.
     *
     * @param array<string, array{base: int, rates: list<Rate>}> $units
     * @return array<string, array<int, int>>
     */
    private function roundedPerOrder(array $units, bool $inclusive): array
    {
        /** @var array<string, array{rate: int, den: int, nums: array<string, int>}> $groups */
        $groups = [];
        foreach ($units as $key => $unit) {
            $total = array_sum(array_column($unit['rates'], 'rate_bps'));
            if ($total <= 0 || $unit['base'] === 0) {
                continue;
            }
            $den = $inclusive ? 10000 + $total : 10000;
            foreach ($unit['rates'] as $rate) {
                $group = $rate['id'].'|'.$den;
                $groups[$group] ??= ['rate' => $rate['id'], 'den' => $den, 'nums' => []];
                $groups[$group]['nums'][$key] = $unit['base'] * $rate['rate_bps'];
            }
        }
        $taxes = [];
        foreach ($groups as $group) {
            $tax = MoneyAllocation::divideHalfUp(array_sum($group['nums']), $group['den']);
            foreach (MoneyAllocation::spread($tax, $group['nums']) as $key => $part) {
                $taxes[$key][$group['rate']] = ($taxes[$key][$group['rate']] ?? 0) + $part;
            }
        }

        return $taxes;
    }
}
