<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\Exceptions\InvalidSegmentRuleException;
use App\Domain\Marketing\Models\MarketingSegment;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Module 15 §10-11/Step 4 "Customer Segments / Segment Rules / Segment
 * Rule Engine". Non-Negotiable Rules #3-4: "No raw SQL supplied by
 * tenant administrators. No arbitrary expression evaluation." Every
 * rule is a {field, operator, value} triple checked against an
 * explicit whitelist below — there is no code path that interpolates
 * admin-supplied text into a query or evaluates an admin-supplied
 * expression.
 *
 * Fields are computed directly from Order/Customer (Phase B5/B6) —
 * this does NOT require Module 10's (non-existent) customer-group/tag
 * system; see docs/development/b10-inspection-findings.md.
 */
final class MarketingSegmentService
{
    private const ALLOWED_FIELDS = ['total_orders_count', 'total_spent_minor', 'last_order_at', 'registered_at'];
    private const ALLOWED_OPERATORS = ['>=', '<=', '=', '>', '<'];

    /**
     * @throws InvalidSegmentRuleException
     */
    public function validateRules(array $rules): void
    {
        if ($rules === []) {
            throw new InvalidSegmentRuleException('A segment must define at least one rule.');
        }

        foreach ($rules as $rule) {
            if (! isset($rule['field'], $rule['operator'], $rule['value'])) {
                throw new InvalidSegmentRuleException('Each rule requires field, operator, and value.');
            }
            if (! in_array($rule['field'], self::ALLOWED_FIELDS, true)) {
                throw new InvalidSegmentRuleException("Unsupported segment field: {$rule['field']}");
            }
            if (! in_array($rule['operator'], self::ALLOWED_OPERATORS, true)) {
                throw new InvalidSegmentRuleException("Unsupported segment operator: {$rule['operator']}");
            }
        }
    }

    /**
     * Module 15 §12 "Campaign Targeting": ALL rules must match (AND) —
     * deterministic, no partial/OR ambiguity.
     */
    public function matches(Customer $customer, array $rules): bool
    {
        $metrics = $this->customerMetrics($customer);

        foreach ($rules as $rule) {
            if (! $this->evaluate($metrics[$rule['field']] ?? null, $rule['operator'], $rule['value'])) {
                return false;
            }
        }

        return true;
    }

    /** @return Collection<int, Customer> */
    public function resolveAudience(MarketingSegment $segment): Collection
    {
        return Customer::query()->get()->filter(fn (Customer $customer) => $this->matches($customer, $segment->rules))->values();
    }

    /** @return array{total_orders_count: int, total_spent_minor: int, last_order_at: ?\Carbon\CarbonInterface, registered_at: ?\Carbon\CarbonInterface} */
    private function customerMetrics(Customer $customer): array
    {
        $orders = Order::query()->where('customer_id', $customer->id)->where('status', '!=', OrderStatus::Cancelled->value)->get();

        return [
            'total_orders_count' => $orders->count(),
            'total_spent_minor' => (int) $orders->sum('grand_total_minor'),
            'last_order_at' => $orders->sortByDesc('created_at')->first()?->created_at,
            'registered_at' => $customer->created_at,
        ];
    }

    private function evaluate(mixed $actual, string $operator, mixed $expected): bool
    {
        if ($actual instanceof \DateTimeInterface) {
            $actual = Carbon::instance($actual)->timestamp;
            $expected = Carbon::parse($expected)->timestamp;
        }

        if ($actual === null) {
            return false; // e.g. last_order_at rule on a customer with zero orders — never matches a comparison
        }

        return match ($operator) {
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            '=' => $actual == $expected,
            '>' => $actual > $expected,
            '<' => $actual < $expected,
            default => false, // unreachable — validateRules() already rejects unknown operators before storage
        };
    }
}
