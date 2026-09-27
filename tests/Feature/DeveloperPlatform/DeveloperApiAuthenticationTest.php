<?php

declare(strict_types=1);

namespace Tests\Feature\DeveloperPlatform;

use App\Domain\DeveloperPlatform\Models\ApplicationStatus;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B18 — Developer API authentication boundary: never Sanctum,
 * never OAuth/JWT, tenant isolation via the credential alone (Module
 * 31 §13-14/§41, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class DeveloperApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function issuedKey(Store $store, array $scopes = ['products:read']): string
    {
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create(['status' => ApplicationStatus::Active]);
        $issued = app(ApiKeyService::class)->issue($application, $scopes);

        return $issued['plaintext'];
    }

    public function test_missing_authorization_header_is_rejected(): void
    {
        $response = $this->getJson('/api/dev/v1/products');

        $response->assertStatus(401);
    }

    public function test_a_valid_key_is_accepted(): void
    {
        $store = Store::factory()->create();
        $key = $this->issuedKey($store);

        $response = $this->withHeader('Authorization', "Bearer {$key}")->getJson('/api/dev/v1/products');

        $response->assertOk();
    }

    public function test_a_revoked_key_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create();
        $issued = app(ApiKeyService::class)->issue($application, ['products:read']);
        app(ApiKeyService::class)->revoke($issued['key']);

        $response = $this->withHeader('Authorization', "Bearer {$issued['plaintext']}")->getJson('/api/dev/v1/products');

        $response->assertStatus(401);
    }

    public function test_a_key_from_store_a_never_returns_store_bs_products(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $keyForA = $this->issuedKey($storeA);
        app(TenantContext::class)->resolveToStore($storeB->id);
        $productB = \App\Domain\Catalog\Models\Product::factory()->for($storeB)->create();

        $response = $this->withHeader('Authorization', "Bearer {$keyForA}")->getJson("/api/dev/v1/products/{$productB->id}");

        $response->assertStatus(404); // Product uses BelongsToTenant — Store A's context never sees Store B's row
    }

    public function test_using_a_key_updates_its_last_used_at_timestamp(): void
    {
        $store = Store::factory()->create();
        $key = $this->issuedKey($store);

        $this->withHeader('Authorization', "Bearer {$key}")->getJson('/api/dev/v1/products');

        $this->assertDatabaseHas('api_keys', ['key_prefix' => explode('.', $key)[0]]);
        $row = \App\Domain\DeveloperPlatform\Models\ApiKey::query()->withoutTenantScope()->where('key_prefix', explode('.', $key)[0])->firstOrFail();
        $this->assertNotNull($row->last_used_at);
    }

    public function test_a_request_is_recorded_in_the_metadata_only_log(): void
    {
        $store = Store::factory()->create();
        $key = $this->issuedKey($store);

        $this->withHeader('Authorization', "Bearer {$key}")->getJson('/api/dev/v1/products');

        $this->assertDatabaseHas('api_request_logs', ['method' => 'GET', 'endpoint' => 'api/dev/v1/products', 'status_code' => 200]);
    }
}
