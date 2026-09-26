<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Seo\Models\ContentPage;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B13 — Staff SEO/content administration, tenant isolation,
 * staff/customer boundary regression.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SeoAdminTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_create_a_content_page(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/content-pages', [
            'title' => 'About Us', 'slug' => 'about-us', 'body' => '<p>We sell things.</p>',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
    }

    public function test_content_page_management_requires_permission(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'no-seo-access']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->postJson('/api/v1/content-pages', [
            'title' => 'X', 'slug' => 'x', 'body' => '<p>x</p>',
        ]);

        $response->assertStatus(403);
    }

    public function test_store_a_cannot_see_store_bs_content_pages(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        ContentPage::factory()->for($storeB)->create(['slug' => 'store-b-page']);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/content-pages');

        $response->assertOk();
        $response->assertJsonMissing(['slug' => 'store-b-page']);
    }

    public function test_customer_token_cannot_access_staff_seo_routes(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/content-pages');

        $response->assertStatus(401);
    }

    public function test_creating_a_seo_setting_for_the_store_home_page(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/seo-settings', [
            'seoable_type' => 'store', 'title' => 'Welcome to My Store',
        ]);

        $response->assertCreated();
    }

    public function test_creating_an_invalid_redirect_returns_a_clear_error(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/redirects', [
            'source_path' => '/old', 'destination_path' => 'https://evil.example',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'invalid_redirect');
    }
}
