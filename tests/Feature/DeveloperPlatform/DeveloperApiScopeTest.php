<?php

declare(strict_types=1);

namespace Tests\Feature\DeveloperPlatform;

use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B18 — API scopes are server-enforced, per-endpoint (Module 31
 * §15-16, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class DeveloperApiScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_key_with_only_products_read_cannot_access_orders(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create();
        $issued = app(ApiKeyService::class)->issue($application, ['products:read']);

        $response = $this->withHeader('Authorization', "Bearer {$issued['plaintext']}")->getJson('/api/dev/v1/orders');

        $response->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
    }

    public function test_a_key_with_the_correct_scope_succeeds(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create();
        $issued = app(ApiKeyService::class)->issue($application, ['orders:read']);

        $response = $this->withHeader('Authorization', "Bearer {$issued['plaintext']}")->getJson('/api/dev/v1/orders');

        $response->assertOk();
    }

    public function test_a_key_with_multiple_scopes_can_access_each(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create();
        $issued = app(ApiKeyService::class)->issue($application, ['products:read', 'inventory:read']);

        $this->withHeader('Authorization', "Bearer {$issued['plaintext']}")->getJson('/api/dev/v1/products')->assertOk();
        $this->withHeader('Authorization', "Bearer {$issued['plaintext']}")->getJson('/api/dev/v1/inventory')->assertOk();
    }
}
