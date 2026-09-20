<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Domain\Marketing\Exceptions\InvalidSegmentRuleException;
use App\Domain\Marketing\Services\MarketingSegmentService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B10 — Segment rule engine: whitelisted fields/operators only,
 * no raw SQL, deterministic evaluation (Module 15 §10-11, Non-
 * Negotiable Rules #3-4).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class MarketingSegmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_field_is_rejected(): void
    {
        $this->expectException(InvalidSegmentRuleException::class);
        app(MarketingSegmentService::class)->validateRules([
            ['field' => 'DROP TABLE customers; --', 'operator' => '>=', 'value' => 1],
        ]);
    }

    public function test_unknown_operator_is_rejected(): void
    {
        $this->expectException(InvalidSegmentRuleException::class);
        app(MarketingSegmentService::class)->validateRules([
            ['field' => 'total_orders_count', 'operator' => '; DROP TABLE customers; --', 'value' => 1],
        ]);
    }

    public function test_empty_rules_are_rejected(): void
    {
        $this->expectException(InvalidSegmentRuleException::class);
        app(MarketingSegmentService::class)->validateRules([]);
    }

    public function test_valid_rules_pass_validation(): void
    {
        app(MarketingSegmentService::class)->validateRules([
            ['field' => 'total_orders_count', 'operator' => '>=', 'value' => 2],
        ]);
        $this->assertTrue(true);
    }

    public function test_customer_matching_order_count_rule_is_included(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();
        Order::factory()->for($store)->create(['customer_id' => $customer->id]);
        Order::factory()->for($store)->create(['customer_id' => $customer->id]);

        $matches = app(MarketingSegmentService::class)->matches($customer, [
            ['field' => 'total_orders_count', 'operator' => '>=', 'value' => 2],
        ]);

        $this->assertTrue($matches);
    }

    public function test_customer_not_matching_order_count_rule_is_excluded(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();
        Order::factory()->for($store)->create(['customer_id' => $customer->id]);

        $matches = app(MarketingSegmentService::class)->matches($customer, [
            ['field' => 'total_orders_count', 'operator' => '>=', 'value' => 5],
        ]);

        $this->assertFalse($matches);
    }

    public function test_cancelled_orders_do_not_count_toward_spend(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();
        Order::factory()->for($store)->create(['customer_id' => $customer->id, 'status' => 'cancelled', 'grand_total_minor' => 100000]);

        $matches = app(MarketingSegmentService::class)->matches($customer, [
            ['field' => 'total_spent_minor', 'operator' => '>=', 'value' => 1],
        ]);

        $this->assertFalse($matches);
    }

    public function test_multiple_rules_are_combined_with_and(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();
        Order::factory()->for($store)->create(['customer_id' => $customer->id, 'grand_total_minor' => 500]);

        $matches = app(MarketingSegmentService::class)->matches($customer, [
            ['field' => 'total_orders_count', 'operator' => '>=', 'value' => 1],
            ['field' => 'total_spent_minor', 'operator' => '>=', 'value' => 100000], // fails
        ]);

        $this->assertFalse($matches);
    }

    public function test_customer_with_no_orders_never_matches_a_last_order_date_rule(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();

        $matches = app(MarketingSegmentService::class)->matches($customer, [
            ['field' => 'last_order_at', 'operator' => '>=', 'value' => now()->subDays(30)->toIso8601String()],
        ]);

        $this->assertFalse($matches);
    }
}
