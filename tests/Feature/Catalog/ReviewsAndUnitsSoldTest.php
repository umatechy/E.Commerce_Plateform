<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductReview;
use App\Domain\Compliance\Services\CustomerDataService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Database\Seeders\PackageSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner decision 15 (2026-10-07) — Module 05 §14, §27, §55; Module 10 §31:
 * ratings from verified buyers' reviews (moderated by the store) and units
 * sold, on Business and Premium storefronts only.
 */
final class ReviewsAndUnitsSoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(PackageSeeder::class);
    }

    /** @return array{0: Store, 1: User, 2: Product} */
    private function store(string $package): array
    {
        $store = Store::factory()->create(['status' => 'active']);
        Subscription::factory()->for($store)->for(Package::query()->where('code', $package)->firstOrFail())->create(['status' => SubscriptionStatus::Active]);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);
        Cache::flush();
        $product = Product::factory()->create(['store_id' => $store->id, 'name' => 'Rose Attar', 'slug' => 'rose-attar', 'status' => 'active', 'visibility' => 'public', 'price_minor' => 250000, 'currency' => 'PKR']);

        return [$store, $owner, $product];
    }

    private function bought(Store $store, ?Customer $customer, Product $product, int $quantity, OrderStatus $status = OrderStatus::Confirmed): void
    {
        $order = Order::factory()->create(['store_id' => $store->id, 'customer_id' => $customer?->id, 'status' => $status]);
        OrderItem::factory()->create(['store_id' => $store->id, 'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity]);
    }

    private function customer(Store $store, string $name = 'Ayesha Khan Malik'): Customer
    {
        return Customer::query()->create(['store_id' => $store->id, 'name' => $name, 'email' => uniqid('c').'@example.com', 'password' => 'secret-password-1']);
    }

    /** @return array<string, string> */
    private function shopper(Store $store, ?Customer $customer = null): array
    {
        $this->staffSession();

        return array_filter(['X-Store-Slug' => $store->slug, 'Authorization' => $customer ? 'Bearer '.$customer->createToken('t')->plainTextToken : null]);
    }

    /** Back to the staff side after a shopper's request (which switched the default guard to customers). */
    private function staffSession(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    private function setting(Store $store, string $key, mixed $value): void
    {
        DB::table('store_settings')->updateOrInsert(['store_id' => $store->id, 'key' => $key], ['value' => json_encode([$value]), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }

    public function test_basic_stores_show_neither_ratings_nor_units_sold(): void
    {
        [$store, $owner, $product] = $this->store('basic');
        $this->bought($store, null, $product, 5);

        $page = $this->getJson('/api/v1/storefront/products/rose-attar', $this->shopper($store))->assertOk()->json('data');
        $this->assertSame([null, null, null], [$page['units_sold'], $page['reviews'], $page['product']['rating']]);
        $this->getJson('/api/v1/storefront/products/rose-attar/reviews', $this->shopper($store))->assertNotFound();
        $this->actingAs($owner)->getJson('/api/v1/reviews')->assertForbidden();
    }

    public function test_units_sold_count_real_orders_only_and_follow_the_stores_choice(): void
    {
        [$store, , $product] = $this->store('business');
        $this->bought($store, null, $product, 3);
        $this->bought($store, null, $product, 4);
        $this->bought($store, null, $product, 9, OrderStatus::Cancelled);

        $this->assertSame(7, $this->getJson('/api/v1/storefront/products/rose-attar', $this->shopper($store))->json('data.units_sold'));
        // A new order shows at once (counted outside the page cache).
        $this->bought($store, null, $product, 1);
        $this->assertSame(8, $this->getJson('/api/v1/storefront/products/rose-attar', $this->shopper($store))->json('data.units_sold'));

        $this->setting($store, 'storefront.units_sold_minimum', 10);
        $this->assertNull($this->getJson('/api/v1/storefront/products/rose-attar', $this->shopper($store))->json('data.units_sold'));
        $this->setting($store, 'storefront.units_sold_minimum', 1);
        $this->setting($store, 'storefront.show_units_sold', false);
        $this->assertNull($this->getJson('/api/v1/storefront/products/rose-attar', $this->shopper($store))->json('data.units_sold'));
    }

    public function test_only_buyers_review_and_the_store_moderates_before_shoppers_see_it(): void
    {
        [$store, $owner, $product] = $this->store('premium');
        $buyer = $this->customer($store);
        $stranger = $this->customer($store, 'Bilal');
        $this->bought($store, $buyer, $product, 1);
        $review = ['rating' => 4, 'title' => 'Lovely', 'body' => 'Lasts the whole day and smells like real roses.'];

        $this->postJson('/api/v1/storefront/products/rose-attar/reviews', $review, $this->shopper($store))->assertUnauthorized();
        $this->postJson('/api/v1/storefront/products/rose-attar/reviews', $review, $this->shopper($store, $stranger))->assertStatus(422)->assertJsonValidationErrors('review');
        $this->postJson('/api/v1/storefront/products/rose-attar/reviews', [...$review, 'rating' => 6], $this->shopper($store, $buyer))->assertStatus(422);
        $own = $this->postJson('/api/v1/storefront/products/rose-attar/reviews', $review, $this->shopper($store, $buyer))->assertCreated()->json('data');
        $this->assertSame(['pending', 'Ayesha M.', true], [$own['status'], $own['author'], $own['verified_purchase']]);

        // Not shown before the store approves it; the buyer sees their own.
        $list = $this->getJson('/api/v1/storefront/products/rose-attar/reviews', $this->shopper($store, $buyer))->json();
        $this->assertSame([[], 'pending', null], [$list['data'], $list['own']['status'], $list['summary']]);
        $this->assertSame(['not_bought', false], [$this->getJson('/api/v1/storefront/products/rose-attar/reviews', $this->shopper($store, $stranger))->json('reason'), $this->getJson('/api/v1/storefront/products/rose-attar/reviews', $this->shopper($store))->json('can_review')]);

        $this->staffSession();
        $this->actingAs($owner)->putJson("/api/v1/reviews/{$own['id']}/status", ['status' => 'approved'])->assertOk();
        $this->actingAs($owner)->putJson("/api/v1/reviews/{$own['id']}/reply", ['reply' => 'Thank you, Ayesha!'])->assertOk();
        $other = $this->customer($store, 'Sana');
        $this->bought($store, $other, $product, 1);
        ProductReview::query()->create(['product_id' => $product->id, 'customer_id' => $other->id, 'rating' => 5, 'body' => 'Excellent attar, will buy again.', 'author_name' => 'Sana', 'status' => 'approved', 'verified_purchase' => true]);
        app(\App\Domain\Catalog\Services\ReviewService::class)->refresh($product);

        $list = $this->getJson('/api/v1/storefront/products/rose-attar/reviews', $this->shopper($store))->json();
        $this->assertCount(2, $list['data']);
        $this->assertSame([4.5, 2, [5 => 1, 4 => 1, 3 => 0, 2 => 0, 1 => 0]], [$list['summary']['average'], $list['summary']['count'], $list['summary']['distribution']]);
        $this->assertSame('Thank you, Ayesha!', collect($list['data'])->firstWhere('id', $own['id'])['reply']);
        $this->assertArrayNotHasKey('email', $list['data'][0]);

        $page = $this->getJson('/api/v1/storefront/products/rose-attar', $this->shopper($store))->json('data');
        $this->assertSame(['average' => 4.5, 'count' => 2], $page['product']['rating']);
        $this->assertSame(4.5, $page['seo']['structured_data'][0]['aggregateRating']['ratingValue'] ?? collect($page['seo']['structured_data'] ?? [])->pluck('aggregateRating.ratingValue')->filter()->first());
        $this->assertSame(['average' => 4.5, 'count' => 2], collect($this->getJson('/api/v1/storefront/products', $this->shopper($store))->json('data.products'))->firstWhere('slug', 'rose-attar')['rating']);

        // Changing the review sends it back to moderation; the rating counts approved reviews only.
        $this->postJson('/api/v1/storefront/products/rose-attar/reviews', [...$review, 'rating' => 2], $this->shopper($store, $buyer))->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame([1, 5], [(int) $product->fresh()->review_count, (int) $product->fresh()->rating_total]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'review.moderated', 'store_id' => $store->id]);
    }

    public function test_moderation_needs_the_permission_and_stays_in_the_store_and_erasure_removes_reviews(): void
    {
        [$store, $owner, $product] = $this->store('business');
        $buyer = $this->customer($store);
        $this->bought($store, $buyer, $product, 1);
        $this->setting($store, 'reviews.auto_approve', true);
        $id = $this->postJson('/api/v1/storefront/products/rose-attar/reviews', ['rating' => 5, 'body' => 'Beautiful scent, fast delivery.'], $this->shopper($store, $buyer))
            ->assertCreated()->assertJsonPath('data.status', 'approved')->json('data.id');
        $this->assertSame(1, (int) $product->fresh()->review_count);

        // Staff without reviews.manage; a blocked buyer; another store's owner.
        $this->staffSession();
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $this->systemRole($store, 'staff')->id, 'status' => 'active']);
        $this->actingAs($staff)->getJson('/api/v1/reviews')->assertForbidden();
        $this->staffSession();
        $this->actingAs($owner)->getJson('/api/v1/reviews?status=approved')->assertOk()->assertJsonPath('data.0.product.name', 'Rose Attar')->assertJsonPath('counts.approved', 1);
        [$other, $otherOwner] = $this->store('business');
        $this->staffSession();
        $this->actingAs($otherOwner)->putJson("/api/v1/reviews/{$id}/status", ['status' => 'rejected'])->assertNotFound();
        app(TenantContext::class)->resolveToStore($store->id);

        $buyer->forceFill(['status' => 'blocked'])->save();
        $this->postJson('/api/v1/storefront/products/rose-attar/reviews', ['rating' => 1, 'body' => 'Changing my review now.'], $this->shopper($store, $buyer))->assertForbidden(); // a blocked customer is refused before the review rules (B32)
        $buyer->forceFill(['status' => 'active'])->save();

        Order::query()->where('customer_id', $buyer->id)->update(['status' => OrderStatus::Completed->value]); // erasure waits for open orders
        app(CustomerDataService::class)->erase($buyer);
        $this->assertSame(0, ProductReview::query()->count());
        $this->assertSame([0, 0], [(int) $product->fresh()->review_count, (int) $product->fresh()->rating_total]);

        $this->staffSession();
        $this->actingAs($owner)->deleteJson('/api/v1/reviews/'.$id)->assertNotFound();
    }
}
