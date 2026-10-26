<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Support;

use App\Domain\Loyalty\Models\LoyaltyProgram;
use Illuminate\Validation\Validator;

/**
 * Phase P2: what a store may set for its loyalty program. The rates are the
 * store's own business decision — the platform proposes none and only
 * refuses settings that cannot be meant, such as giving back more than the
 * customer spent.
 */
final class LoyaltyRules
{
    public const MAX_TIERS = 5;

    /** @return array<string, list<mixed>> */
    public static function validation(): array
    {
        return [
            'earn_points' => ['required', 'integer', 'min:1', 'max:100000'],
            'earn_spend_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'redeem_points' => ['required', 'integer', 'min:1', 'max:10000000'],
            'redeem_value_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'min_redeem_points' => ['required', 'integer', 'min:1', 'max:1000000000', 'gte:redeem_points'],
            'hold_days' => ['required', 'integer', 'min:0', 'max:365'],
            'expiry_days' => ['nullable', 'integer', 'min:30', 'max:3650'],
            'birthday_points' => ['required', 'integer', 'min:0', 'max:10000000'],
            'repeat_every' => ['nullable', 'integer', 'min:2', 'max:100', 'required_unless:repeat_points,0'],
            'repeat_points' => ['required', 'integer', 'min:0', 'max:10000000'],
            'tiers' => ['present', 'array', 'max:'.self::MAX_TIERS],
            'tiers.*.name' => ['required', 'string', 'min:1', 'max:40', 'distinct:ignore_case'],
            'tiers.*.min_points' => ['required', 'integer', 'min:1', 'max:1000000000', 'distinct'],
            'tiers.*.bonus_percent' => ['required', 'integer', 'min:0', 'max:200'],
        ];
    }

    /** Checks that need more than one field. */
    public static function after(Validator $validator): void
    {
        $data = $validator->getData();
        foreach (['earn_points', 'earn_spend_minor', 'redeem_points', 'redeem_value_minor'] as $key) {
            if (! is_numeric($data[$key] ?? null) || (int) $data[$key] < 1) {
                return;
            }
        }
        // Back to the customer for every unit spent, at the highest tier.
        $bonus = max([0, ...array_map(fn ($t) => is_array($t) ? (int) ($t['bonus_percent'] ?? 0) : 0, (array) ($data['tiers'] ?? []))]);
        if (self::returnRate($data, $bonus) > 1.0) {
            $validator->errors()->add('redeem_value_minor', 'With these rates customers would get back more than they spend. Lower the value of points or the points given.');
        }
    }

    /**
     * The share of spending that comes back as store credit (0.01 = 1%).
     *
     * @param array<string, mixed>|LoyaltyProgram $rates
     */
    public static function returnRate(array|LoyaltyProgram $rates, int $bonusPercent = 0): float
    {
        $get = fn (string $key): int => (int) ($rates instanceof LoyaltyProgram ? $rates->{$key} : $rates[$key]);

        return ($get('earn_points') / $get('earn_spend_minor')) * ($get('redeem_value_minor') / $get('redeem_points')) * (1 + $bonusPercent / 100);
    }

    /**
     * @param array<string, mixed> $data validated input
     *
     * @return array<string, mixed> columns of loyalty_programs
     */
    public static function fromInput(array $data): array
    {
        $tiers = array_map(fn (array $t) => ['name' => trim((string) $t['name']), 'min_points' => (int) $t['min_points'], 'bonus_percent' => (int) $t['bonus_percent']], array_values($data['tiers'] ?? []));
        usort($tiers, fn (array $a, array $b) => $a['min_points'] <=> $b['min_points']);
        $repeatPoints = (int) $data['repeat_points'];

        return [
            'earn_points' => (int) $data['earn_points'],
            'earn_spend_minor' => (int) $data['earn_spend_minor'],
            'redeem_points' => (int) $data['redeem_points'],
            'redeem_value_minor' => (int) $data['redeem_value_minor'],
            'min_redeem_points' => (int) $data['min_redeem_points'],
            'hold_days' => (int) $data['hold_days'],
            'expiry_days' => isset($data['expiry_days']) ? (int) $data['expiry_days'] : null,
            'birthday_points' => (int) $data['birthday_points'],
            'repeat_every' => $repeatPoints > 0 && isset($data['repeat_every']) ? (int) $data['repeat_every'] : null,
            'repeat_points' => $repeatPoints,
            'tiers' => $tiers,
        ];
    }
}
