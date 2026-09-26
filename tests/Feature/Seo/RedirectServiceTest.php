<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\Exceptions\InvalidRedirectException;
use App\Domain\Seo\Models\Redirect;
use App\Domain\Seo\Services\RedirectService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B13 — Redirect security: open-redirect prevention, loop
 * prevention, automatic slug-change recording (Module 16 §11-13, Non-
 * Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class RedirectServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_destination_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $this->expectException(InvalidRedirectException::class);
        app(RedirectService::class)->create('/old-page', 'https://evil.example/phishing');
    }

    public function test_protocol_relative_destination_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $this->expectException(InvalidRedirectException::class);
        app(RedirectService::class)->create('/old-page', '//evil.example/phishing');
    }

    public function test_self_redirect_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $this->expectException(InvalidRedirectException::class);
        app(RedirectService::class)->create('/same-page', '/same-page');
    }

    public function test_direct_loop_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        app(RedirectService::class)->create('/a', '/b');

        $this->expectException(InvalidRedirectException::class);
        app(RedirectService::class)->create('/b', '/a');
    }

    public function test_valid_internal_redirect_is_created(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $redirect = app(RedirectService::class)->create('/old-shirt', '/products/new-shirt');

        $this->assertSame('/old-shirt', $redirect->source_path);
        $this->assertSame('/products/new-shirt', $redirect->destination_path);
    }

    public function test_product_slug_change_automatically_records_a_redirect(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create(['slug' => 'old-slug']);

        $product->update(['slug' => 'new-slug']);

        $this->assertDatabaseHas('redirects', ['source_path' => '/products/old-slug', 'destination_path' => '/products/new-slug']);
    }

    public function test_unrelated_product_update_does_not_create_a_redirect(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create(['slug' => 'stable-slug']);

        $product->update(['name' => 'Renamed Product']);

        $this->assertSame(0, Redirect::query()->count());
    }
}
