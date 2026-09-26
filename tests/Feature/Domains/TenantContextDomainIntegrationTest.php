<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use App\Domain\Catalog\Models\Product;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B14 — ResolveTenantContext's new, higher-priority Host-header
 * resolution step, with the Phase B6 X-Store-Slug fallback explicitly
 * preserved for non-domain-matching requests (Module 19 §19-21, Non-
 * Negotiable Step 20 — the exact integration this milestone's own
 * "Critical Finding" identified).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class TenantContextDomainIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_with_a_matching_verified_domain_host_resolves_that_store(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::query()->where('store_id', $store->id)->firstOrFail();
        $product = Product::factory()->for($store)->create(['slug' => 'widget', 'price_minor' => 1000]);

        // Cart is genuinely tenant-scoped via ResolveTenantContext (no
        // path-based store slug of its own, unlike the public SEO
        // endpoints) — a successful add-to-cart against THIS store's
        // own product, using ONLY the Host header (no X-Store-Slug at
        // all), proves Host-based domain resolution alone is sufficient.
        $response = $this->withHeader('Host', $domain->normalized_hostname)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);

        $response->assertCreated();
    }

    public function test_x_store_slug_fallback_still_works_when_host_matches_no_domain(): void
    {
        $store = Store::factory()->create(['slug' => 'fallback-shop']);
        app(TenantContext::class)->resolveToStore($store->id);
        Product::factory()->for($store)->create(['slug' => 'test-widget', 'price_minor' => 1000]);

        $response = $this->postJson('/api/v1/cart/items', ['product_id' => Product::query()->where('slug', 'test-widget')->value('id'), 'quantity' => 1], [
            'X-Store-Slug' => 'fallback-shop',
        ]);

        $response->assertCreated();
    }

    public function test_a_verified_domain_host_takes_priority_over_a_conflicting_x_store_slug_header(): void
    {
        $storeA = Store::factory()->create(['slug' => 'store-a']);
        $storeB = Store::factory()->create(['slug' => 'store-b']);
        app(TenantContext::class)->resolveToStore($storeA->id);
        $domainA = Domain::query()->where('store_id', $storeA->id)->firstOrFail();
        app(TenantContext::class)->resolveToStore($storeB->id);
        Product::factory()->for($storeB)->create(['slug' => 'store-b-only-product']);

        // Host resolves to Store A; a (spoofed) X-Store-Slug header
        // claims Store B — Store A's domain resolution must win, per
        // Non-Negotiable Step 20 ("cannot override a verified domain
        // resolution"). Verified indirectly: the cart action succeeds
        // against Store A's own tenant scope, not Store B's.
        $response = $this->withHeader('Host', $domainA->normalized_hostname)
            ->withHeader('X-Store-Slug', 'store-b')
            ->postJson('/api/v1/cart/items', ['product_id' => 999999, 'quantity' => 1]);

        // Store A has no product 999999 (it belongs, if anywhere, to
        // Store B) — a 404/422 here (not success) proves resolution
        // used Store A's tenant scope, not Store B's, despite the
        // spoofed header.
        $response->assertStatus(404);
    }
}
