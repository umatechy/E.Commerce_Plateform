<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-002 Surface A (Sanctum) — this milestone's "Test Specification /
 * Authentication" section. STATUS: NOT EXECUTED — DEFERRED TO VS CODE
 * RUNTIME VERIFICATION (see checkpoint-b1.md).
 */
final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_user_store_and_owner_membership(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Amina Khan',
            'email' => 'amina@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'store_name' => "Amina's Boutique",
        ]);

        $response->assertCreated();
        $response->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', ['email' => 'amina@example.com']);
        $this->assertDatabaseHas('stores', ['name' => "Amina's Boutique"]);

        $user = User::query()->where('email', 'amina@example.com')->firstOrFail();
        $this->assertSame(1, $user->stores()->count());

        $ownerRole = Role::query()->withoutTenantScope()
            ->where('store_id', $user->stores()->first()->id)
            ->where('slug', 'owner')
            ->first();
        $this->assertNotNull($ownerRole, 'StoreObserver must seed an owner role.');
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'store_name' => 'Some Store',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_succeeds_with_correct_credentials(): void
    {
        User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertOk();
        $this->assertAuthenticated();
    }

    public function test_login_fails_with_incorrect_password(): void
    {
        User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        User::factory()->create(['email' => 'owner@example.com', 'password' => Hash::make('correct')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'wrong']);
        }

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'wrong']);

        $response->assertStatus(422);
        $response->assertSee('seconds', false);
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_me_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_me_endpoint_never_exposes_password_hash(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertJsonMissingPath('data.password');
        $this->assertStringNotContainsString($user->password, $response->getContent());
    }
}
