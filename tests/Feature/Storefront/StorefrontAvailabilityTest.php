<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B24 — which store a storefront request is for, whether it is
 * open, and the owner's launch of a store that was stuck in
 * pending_setup.
 */
final class StorefrontAvailabilityTest extends TestCase
{
    use InteractsWithStorefront, RefreshDatabase;

    private function owner(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    public function test_an_unknown_or_missing_store_is_not_found(): void
    {
        $this->getJson('/api/v1/storefront/products', ['X-Store-Slug' => 'no-such-store'])
            ->assertNotFound()->assertJsonPath('code', 'store_not_found');
        $this->getJson('/api/v1/storefront/products')->assertNotFound();
    }

    public function test_a_store_that_has_not_launched_is_closed(): void
    {
        $store = $this->openStore(['status' => 'pending_setup']);

        $this->getJson('/api/v1/storefront', $this->storefront($store))
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'storefront_not_launched');
    }

    public function test_a_suspended_store_or_lapsed_subscription_closes_the_storefront_at_once(): void
    {
        $lapsed = $this->openStore([], 'suspended');
        $suspended = $this->openStore(['status' => 'suspended']);
        $noSubscription = Store::factory()->create(['status' => 'active']);

        foreach ([$lapsed, $suspended, $noSubscription] as $store) {
            $this->getJson('/api/v1/storefront/products', $this->storefront($store))
                ->assertStatus(503)->assertJsonPath('code', 'storefront_unavailable');
        }

        $pastDue = $this->openStore([], 'past_due'); // still grants access (Module 04 §36)
        $this->getJson('/api/v1/storefront/products', $this->storefront($pastDue))->assertOk();
    }

    public function test_platform_maintenance_closes_every_storefront(): void
    {
        $store = $this->openStore();
        app(\App\Domain\Settings\Services\ConfigService::class)->set('platform.maintenance_mode', true, \App\Domain\Settings\Models\SettingScope::Platform, null);

        $this->getJson('/api/v1/storefront', $this->storefront($store))->assertStatus(503)->assertJsonPath('code', 'storefront_maintenance');
    }

    public function test_a_customer_token_only_works_on_its_own_store(): void
    {
        $store = $this->openStore();
        $other = $this->openStore();
        $token = Customer::factory()->for($store)->create(['password' => Hash::make('x')])->createToken('t')->plainTextToken;

        $this->getJson('/api/v1/storefront', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('data.shell.store.slug', $store->slug);
        $this->getJson('/api/v1/storefront', ['Authorization' => "Bearer {$token}", ...$this->storefront($other)])
            ->assertForbidden()->assertJsonPath('code', 'store_mismatch');
    }

    public function test_the_owner_launches_the_store_once_the_required_steps_are_done(): void
    {
        $store = $this->openStore(['status' => 'pending_setup']);
        $owner = $this->owner($store);

        $checks = collect($this->actingAs($owner)->getJson('/api/v1/storefront/setup')->assertOk()
            ->assertJsonPath('data.launched', false)
            ->assertJsonPath('data.availability', 'not_launched')
            ->json('data.checks'))->keyBy('key');
        $this->assertFalse($checks['products']['done']);
        $this->assertTrue($checks['subscription']['done']);
        $this->assertTrue($checks['warehouse']['done']);

        $this->actingAs($owner)->postJson('/api/v1/storefront/launch')
            ->assertStatus(422)->assertJsonPath('code', 'setup_incomplete');

        $this->product($store, ['price_minor' => 2500]);
        $this->actingAs($owner)->postJson('/api/v1/storefront/launch')
            ->assertOk()->assertJsonPath('data.launched', true)->assertJsonPath('data.availability', 'open');

        $this->assertSame('active', $store->refresh()->status->value);
        $this->assertTrue(AuditLog::query()->where('action', 'storefront.launched')->where('store_id', $store->id)->exists());
        $this->getJson('/api/v1/storefront/products', $this->storefront($store))->assertOk();

        $this->actingAs($owner)->postJson('/api/v1/storefront/launch')->assertStatus(409)->assertJsonPath('code', 'already_launched');
    }

    public function test_launching_needs_the_storefront_permission(): void
    {
        $store = $this->openStore(['status' => 'pending_setup']);
        $manager = User::factory()->create();
        $store->users()->attach($manager, ['role_id' => $this->systemRole($store, 'manager')->id, 'status' => 'active']);

        $this->actingAs($manager)->getJson('/api/v1/storefront/setup')->assertForbidden();
        $this->actingAs($manager)->postJson('/api/v1/storefront/launch')->assertForbidden();
        $this->assertSame('pending_setup', $store->refresh()->status->value);
    }

    public function test_staff_can_preview_their_store_before_launch_but_shoppers_cannot(): void
    {
        $store = $this->openStore(['status' => 'pending_setup']);
        $owner = $this->owner($store);

        $this->withoutVite()->get("/shop/{$store->slug}")->assertStatus(503)
            ->assertInertia(fn ($page) => $page->component('Storefront/Unavailable')->where('code', 'storefront_not_launched'));

        $this->actingAs($owner)->get("/shop/{$store->slug}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Storefront/Home')->where('storefront.preview', true));
    }
}
