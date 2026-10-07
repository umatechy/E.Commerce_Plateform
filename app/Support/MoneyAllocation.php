<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Splits an amount of minor units over weights so the parts add up exactly
 * (ADR-003: integers only, no floating point). Each part is the amount ×
 * weight ÷ total weight, rounded down; the last key with a weight takes the
 * remainder. Used for an order-level discount spread over lines (refunds,
 * Phase B33; tax, Phase B46) and a tax amount spread over rates.
 */
final class MoneyAllocation
{
    /**
     * @template K of array-key
     * @param array<K, int> $weights non-negative
     * @return array<K, int> the same keys, summing to $amount (all 0 when every weight is 0)
     */
    public static function spread(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $parts = array_map(fn () => 0, $weights);
        if ($total <= 0 || $amount === 0) {
            return $parts;
        }
        $last = null;
        foreach ($weights as $key => $weight) {
            if ($weight > 0) {
                $last = $key;
            }
        }
        $given = 0;
        foreach ($weights as $key => $weight) {
            if ($key === $last) {
                continue;
            }
            $parts[$key] = intdiv($amount * $weight, $total);
            $given += $parts[$key];
        }
        $parts[$last] = $amount - $given;

        return $parts;
    }

    /** a ÷ b rounded half up, for non-negative a and positive b. */
    public static function divideHalfUp(int $a, int $b): int
    {
        return intdiv(2 * $a + $b, 2 * $b);
    }
}
