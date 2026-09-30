<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B6 — Customer registration/login (Module 10 §10-11) AND the
 * CRITICAL staff/customer principal separation (Module 10 §3, this
 * milestone's Step 10 and Final Inspection Question #14).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register(): void
    {
        $store = Store::factory()->create();

        $response = $this->postJson('/api/v1/customer/register', [
            'name' => 'Jane Shopper',
            'email' => 'jane@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ], ['X-Store-Slug' => $store->slug]);

        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['id', 'name', 'email'], 'token']);
        $this->assertDatabaseHas('customers', ['store_id' => $store->id, 'email' => 'jane@example.com']);
    }

    public function test_registration_rejects_duplicate_registered_email(): void
    {
        $store = Store::factory()->create();
        Customer::factory()->for($store)->create(['email' => 'taken@example.com', 'password' => Hash::make('x')]);

        $response = $this->postJson('/api/v1/customer/register', [
            'name' => 'Someone', 'email' => 'taken@example.com',
            'password' => 'correct-horse-battery-staple', 'password_confirmation' => 'correct-horse-battery-staple',
        ], ['X-Store-Slug' => $store->slug]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_guest_order_email_does_not_block_new_registration(): void
    {
        $store = Store::factory()->create();
        // Guest customer row (password = null) from a past order — Phase B5 behavior.
        Customer::factory()->for($store)->create(['email' => 'guest@example.com', 'password' => null]);

        $response = $this->postJson('/api/v1/customer/register', [
            'name' => 'Now Registering', 'email' => 'guest@example.com',
            'password' => 'correct-horse-battery-staple', 'password_confirmation' => 'correct-horse-battery-staple',
        ], ['X-Store-Slug' => $store->slug]);

        $response->assertCreated();
    }

    public function test_customer_can_login_and_receive_a_token(): void
    {
        $store = Store::factory()->create();
        Customer::factory()->for($store)->create(['email' => 'jane@example.com', 'password' => Hash::make('correct-horse-battery-staple')]);

        $response = $this->postJson('/api/v1/customer/login', [
            'email' => 'jane@example.com', 'password' => 'correct-horse-battery-staple',
        ], ['X-Store-Slug' => $store->slug]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $store = Store::factory()->create();
        Customer::factory()->for($store)->create(['email' => 'jane@example.com', 'password' => Hash::make('correct')]);

        $response = $this->postJson('/api/v1/customer/login', [
            'email' => 'jane@example.com', 'password' => 'wrong',
        ], ['X-Store-Slug' => $store->slug]);

        $response->assertStatus(422);
    }

    public function test_a_guest_customer_row_with_no_password_cannot_login(): void
    {
        $store = Store::factory()->create();
        Customer::factory()->for($store)->create(['email' => 'guest@example.com', 'password' => null]);

        $response = $this->postJson('/api/v1/customer/login', [
            'email' => 'guest@example.com', 'password' => 'anything',
        ], ['X-Store-Slug' => $store->slug]);

        $response->assertStatus(422);
    }

    /**
     * CRITICAL — Module 10 §3: "Customers must not automatically
     * receive administrative permissions." A Customer-issued Sanctum
     * token must be rejected by every staff-only route.
     */
    public function test_customer_token_cannot_access_staff_routes(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    /**
     * CRITICAL — the symmetric direction: a staff User's token must
     * never be accepted as a customer principal either.
     */
    public function test_staff_token_cannot_access_customer_only_routes(): void
    {
        $store = Store::factory()->create();
        $role = $this->systemRole($store, 'owner');
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/customer/me');

        $response->assertStatus(401);
    }

    public function test_customer_logout_revokes_the_token(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/customer/logout')->assertNoContent();

        // The test app instance persists across requests, and so does the
        // guard's cached user; a real second request starts fresh.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/customer/me')->assertStatus(401);
    }
}
