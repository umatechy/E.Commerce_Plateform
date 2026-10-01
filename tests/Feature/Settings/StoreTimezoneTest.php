<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Analytics\Services\DateRangeResolver;
use App\Domain\Analytics\Services\ReportService;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\StoreClock;
use App\Domain\Storefront\Services\StorefrontExperience;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Phase B28 (gap G5) — the store's timezone decides what "today" is and
 * what a typed time means (Module 33 §50; SRS DATA-010, LOC-004).
 * Storage stays UTC throughout.
 */
final class StoreTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function storeIn(string $timezone): Store
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        app(ConfigService::class)->set('store.timezone', $timezone, SettingScope::Store, null);

        return $store;
    }

    private function ownerOf(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    public function test_a_time_without_an_offset_is_store_time_and_one_with_an_offset_keeps_it(): void
    {
        $this->storeIn('Asia/Karachi'); // UTC+5
        $clock = app(StoreClock::class);

        $this->assertSame('2026-10-01 04:00:00', $clock->parse('2026-10-01 09:00')->toDateTimeString());
        $this->assertSame('2026-09-30 19:00:00', $clock->parse('2026-10-01')->toDateTimeString());
        $this->assertSame('2026-10-01 09:00:00', $clock->parse('2026-10-01T09:00:00Z')->toDateTimeString());
        $this->assertSame('2026-10-01 07:00:00', $clock->parse('2026-10-01T09:00:00+02:00')->toDateTimeString());
        $this->assertSame('UTC', $clock->parse('2026-10-01 09:00')->getTimezone()->getName());
    }

    public function test_without_a_store_the_clock_is_utc(): void
    {
        $clock = app(StoreClock::class);

        $this->assertSame('UTC', $clock->timezoneName());
        $this->assertSame('2026-10-01 09:00:00', $clock->parse('2026-10-01 09:00')->toDateTimeString());
    }

    public function test_today_and_a_custom_range_follow_the_stores_midnight(): void
    {
        Carbon::setTestNow('2026-09-30 20:30:00'); // already 1 October, 01:30, in Karachi
        $this->storeIn('Asia/Karachi');

        [$start, $end] = app(DateRangeResolver::class)->resolve('today');
        $this->assertSame('2026-09-30 19:00:00', $start->toDateTimeString());
        $this->assertSame('2026-10-01 18:59:59', $end->toDateTimeString());

        [$start, $end] = app(DateRangeResolver::class)->resolve('custom', '2026-09-01', '2026-09-30');
        $this->assertSame('2026-08-31 19:00:00', $start->toDateTimeString());
        $this->assertSame('2026-09-30 18:59:59', $end->toDateTimeString());

        [$start] = app(DateRangeResolver::class)->resolve('this_month');
        $this->assertSame('2026-09-30 19:00:00', $start->toDateTimeString()); // October has begun for this store
    }

    public function test_the_sales_report_groups_orders_by_the_stores_day(): void
    {
        $store = $this->storeIn('Asia/Karachi');
        // 23:30 on 30 September and 00:30 on 1 October in Karachi: one UTC day, two store days.
        Order::factory()->for($store)->create(['grand_total_minor' => 1000, 'status' => OrderStatus::Confirmed, 'created_at' => '2026-09-30 18:30:00']);
        Order::factory()->for($store)->create(['grand_total_minor' => 2000, 'status' => OrderStatus::Confirmed, 'created_at' => '2026-09-30 19:30:00']);

        [$start, $end] = app(DateRangeResolver::class)->resolve('custom', '2026-09-30', '2026-10-01');
        $rows = app(ReportService::class)->salesReport($start, $end);

        $this->assertSame(['2026-09-30', '2026-10-01'], array_column($rows, 'date'));
        $this->assertSame([1000, 2000], array_column($rows, 'revenue_minor'));

        // "30 September" alone holds only the first order.
        [$start, $end] = app(DateRangeResolver::class)->resolve('custom', '2026-09-30', '2026-09-30');
        $this->assertSame([1000], array_column(app(ReportService::class)->salesReport($start, $end), 'revenue_minor'));
    }

    public function test_a_day_split_by_a_daylight_saving_change_is_one_row(): void
    {
        $store = $this->storeIn('America/New_York'); // clocks go back at 06:00 UTC on 1 November 2026
        // 01:30 EDT and, an hour later, 01:30 EST: the same store day, different UTC offsets.
        Order::factory()->for($store)->create(['grand_total_minor' => 1000, 'status' => OrderStatus::Confirmed, 'created_at' => '2026-11-01 05:30:00']);
        Order::factory()->for($store)->create(['grand_total_minor' => 2000, 'status' => OrderStatus::Confirmed, 'created_at' => '2026-11-01 06:30:00']);

        [$start, $end] = app(DateRangeResolver::class)->resolve('custom', '2026-10-31', '2026-11-02');

        $segments = app(StoreClock::class)->constantOffsetSegments($start, $end);
        $this->assertSame([-14400, -18000], array_column($segments, 'offset_seconds'));

        $rows = app(ReportService::class)->salesReport($start, $end);
        $this->assertCount(1, $rows);
        $this->assertSame('2026-11-01', $rows[0]['date']);
        $this->assertSame(2, $rows[0]['order_count']);
        $this->assertSame(3000, $rows[0]['revenue_minor']);
    }

    public function test_a_campaign_scheduled_without_an_offset_runs_at_store_time(): void
    {
        Bus::fake();
        Carbon::setTestNow('2026-09-30 12:00:00');
        $store = $this->storeIn('Asia/Karachi');
        $campaign = Campaign::factory()->for($store)->create();

        $this->actingAs($this->ownerOf($store))
            ->postJson("/api/v1/campaigns/{$campaign->id}/activate", ['scheduled_at' => '2026-10-02 09:00'])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');

        $this->assertSame('2026-10-02 04:00:00', $campaign->fresh()->scheduled_at->toDateTimeString());
    }

    public function test_a_promotion_window_typed_without_an_offset_is_store_time(): void
    {
        $store = $this->storeIn('Asia/Karachi');

        $this->actingAs($this->ownerOf($store))->postJson('/api/v1/promotions', [
            'name' => 'October Sale', 'type' => 'percentage', 'target_scope' => 'order', 'percentage_value' => 10, 'status' => 'active',
            'starts_at' => '2026-10-01 00:00', 'ends_at' => '2026-10-31T23:59:59+05:00',
        ])->assertCreated();

        $promotion = Promotion::query()->where('name', 'October Sale')->firstOrFail();
        $this->assertSame('2026-09-30 19:00:00', $promotion->starts_at->toDateTimeString());
        $this->assertSame('2026-10-31 18:59:59', $promotion->ends_at->toDateTimeString());
    }

    public function test_pages_are_told_the_timezone_to_show_dates_in(): void
    {
        $store = $this->storeIn('Asia/Karachi');

        $this->withoutVite()->actingAs($this->ownerOf($store))->get('/')
            ->assertOk()->assertInertia(fn ($page) => $page->where('auth.timezone', 'Asia/Karachi'));

        $this->assertSame('Asia/Karachi', app(StorefrontExperience::class)->shell($store)['store']['timezone']);
    }
}
