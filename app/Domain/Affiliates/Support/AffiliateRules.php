<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Support;

/**
 * Phase B57 (Module 28 §13, §18–24, §41, §67) — the rules of a store's
 * affiliate program, as one version stores them. Nothing here is a
 * platform-chosen rate: the store sets its own commission; these are only
 * the starting values of a new program and the limits of what can be set.
 */
final class AffiliateRules
{
    /** Platform policy (§13): the longest attribution window a store may choose. */
    public const MAX_WINDOW_DAYS = 90;

    /** The highest percentage a store may give (protects against typing 100 for 10). */
    public const MAX_RATE_BP = 5000;

    public const DEFAULTS = [
        'commission_type' => 'percentage', // percentage | fixed (§18.1–18.2)
        'rate_bp' => 1000,                 // 10.00 % in basis points
        'fixed_minor' => 0,
        'base' => 'items_after_discounts', // §19: products after discounts; shipping and tax never count
        'window_days' => 30,               // §13
        'hold_days' => 14,                 // §22: after delivery, for returns
        'require_delivery' => true,        // §24: COD orders qualify when delivered…
        'require_payment' => true,         // …and the money collected
        'new_customers_only' => false,     // §18.7
        'min_order_minor' => 0,
        'cap_per_order_minor' => null,     // §67
        'payout_threshold_minor' => 0,     // §35
        'self_referral' => 'review',       // §41: review | reject
    ];

    /** Words that cannot be a referral code (§9). */
    public const RESERVED_CODES = ['ADMIN', 'STORE', 'SHOP', 'UMARTECHY', 'SUPPORT', 'HELP', 'TEST', 'NULL', 'CHECKOUT', 'ORDER', 'FREE', 'DISCOUNT', 'COUPON'];

    /** @return array<string, list<mixed>> validation rules for the rules part of a request */
    public static function validation(): array
    {
        return [
            'commission_type' => ['required', 'in:percentage,fixed'],
            'rate_percent' => ['required_if:commission_type,percentage', 'nullable', 'numeric', 'min:0.01', 'max:'.(self::MAX_RATE_BP / 100)],
            'fixed_minor' => ['required_if:commission_type,fixed', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'window_days' => ['required', 'integer', 'min:1', 'max:'.self::MAX_WINDOW_DAYS],
            'hold_days' => ['required', 'integer', 'min:0', 'max:90'],
            'require_delivery' => ['required', 'boolean'],
            'require_payment' => ['required', 'boolean'],
            'new_customers_only' => ['required', 'boolean'],
            'min_order_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'cap_per_order_minor' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'payout_threshold_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'self_referral' => ['required', 'in:review,reject'],
        ];
    }

    /**
     * Validated input as stored rules: the percentage becomes basis points
     * (decimal-safe, §65), the unused amount is cleared.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function fromInput(array $input): array
    {
        $percentage = $input['commission_type'] === 'percentage';

        return [
            ...self::DEFAULTS,
            'commission_type' => $input['commission_type'],
            'rate_bp' => $percentage ? (int) round(((float) $input['rate_percent']) * 100) : 0,
            'fixed_minor' => $percentage ? 0 : (int) $input['fixed_minor'],
            'window_days' => (int) $input['window_days'],
            'hold_days' => (int) $input['hold_days'],
            'require_delivery' => (bool) $input['require_delivery'],
            'require_payment' => (bool) $input['require_payment'],
            'new_customers_only' => (bool) $input['new_customers_only'],
            'min_order_minor' => (int) $input['min_order_minor'],
            'cap_per_order_minor' => isset($input['cap_per_order_minor']) ? (int) $input['cap_per_order_minor'] : null,
            'payout_threshold_minor' => (int) $input['payout_threshold_minor'],
            'self_referral' => $input['self_referral'],
        ];
    }

    /**
     * The commission on a base, in minor units, never a float (§65–67).
     *
     * @param array<string, mixed> $rules
     */
    public static function commission(array $rules, int $base): int
    {
        $amount = $rules['commission_type'] === 'fixed'
            ? (int) $rules['fixed_minor']
            : intdiv(max(0, $base) * (int) $rules['rate_bp'], 10000);
        if ($rules['cap_per_order_minor'] !== null) {
            $amount = min($amount, (int) $rules['cap_per_order_minor']);
        }

        // Never more than the commissionable amount itself (a fixed commission on a tiny order).
        return max(0, min($amount, max(0, $base)));
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function validCode(string $code): bool
    {
        return preg_match('/^[A-Z0-9]{4,20}$/', $code) === 1 && ! in_array($code, self::RESERVED_CODES, true);
    }
}
