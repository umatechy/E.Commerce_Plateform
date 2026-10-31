<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreRequest;
use Database\Seeders\PackageSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase S1 (owner decision 2026-10-30): the platform is closed to sign-up.
 * Businesses ask Umar Techy for a store on the home page; the team follows
 * the request up in Super Admin and creates the store from it.
 */
final class StoreRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(PackageSeeder::class);
        \Illuminate\Support\Facades\Queue::fake();
    }

    /** @return array<string, mixed> */
    private function request(array $more = []): array
    {
        return ['business_name' => 'Rose Attars', 'contact_name' => 'Ayesha Khan', 'email' => 'Ayesha@Example.com', 'phone' => '0300 1234567',
            'city' => 'Lahore', 'business_category' => 'fashion', 'package_code' => 'business', 'domain' => 'roseattars.pk', 'hosting' => 'own_server',
            'message' => 'About 200 products.', ...$more];
    }

    public function test_a_business_asks_for_a_store_the_team_hears_of_it_and_bots_and_bad_input_are_refused(): void
    {
        $storesAdmin = User::factory()->create(['platform_role' => 'operations_admin']); // has the stores capability
        $support = User::factory()->create(['platform_role' => 'support_admin']);       // does not
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // the limit has its own test below

        $this->postJson('/api/v1/public/store-requests', $this->request(['website' => 'http://spam.example']))->assertUnprocessable()->assertJsonValidationErrors('website');
        $this->postJson('/api/v1/public/store-requests', $this->request(['domain' => 'https://roseattars.pk/']))->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->postJson('/api/v1/public/store-requests', $this->request(['hosting' => 'somewhere']))->assertUnprocessable()->assertJsonValidationErrors('hosting');
        $this->postJson('/api/v1/public/store-requests', $this->request(['package_code' => 'gold']))->assertUnprocessable()->assertJsonValidationErrors('package_code');

        $this->postJson('/api/v1/public/store-requests', $this->request())->assertStatus(202);
        // A second try while the first is still new updates it.
        $this->postJson('/api/v1/public/store-requests', $this->request(['message' => 'Now 300 products.']))->assertStatus(202);

        $request = StoreRequest::query()->sole();
        $this->assertSame(['ayesha@example.com', 'own_server', 'new', 'Now 300 products.', 'roseattars.pk'], [$request->email, $request->hosting, $request->status, $request->message, $request->domain]);
        $this->assertSame(1, NotificationMessage::query()->withoutTenantScope()->where('destination', 'ayesha@example.com')->count(), 'one confirmation');
        $this->assertSame(1, NotificationMessage::query()->withoutTenantScope()->where('destination', $storesAdmin->email)->count());
        $this->assertSame(0, NotificationMessage::query()->withoutTenantScope()->where('destination', $support->email)->count(), 'only staff who may create stores');
        $this->assertSame(1, AuditLog::query()->where('action', 'platform.store_request_received')->count());
    }

    public function test_the_team_follows_up_and_creates_the_store_from_the_request(): void
    {
        $this->postJson('/api/v1/public/store-requests', $this->request())->assertStatus(202);
        $id = StoreRequest::query()->sole()->public_id;
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $support = User::factory()->create(['platform_role' => 'support_admin']);

        // Looking is "view"; following up needs the stores capability.
        $this->actingAs($support)->getJson('/api/v1/super-admin/store-requests')->assertOk()->assertJsonPath('data.0.business_name', 'Rose Attars');
        $this->actingAs($support)->putJson("/api/v1/super-admin/store-requests/{$id}", ['status' => 'contacted'])->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->actingAs($admin)->putJson("/api/v1/super-admin/store-requests/{$id}", ['status' => 'contacted', 'staff_note' => 'Agreed Business, own server, starts Monday.'])->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/super-admin/store-requests?status=contacted')->assertOk()
            ->assertJsonPath('data.0.staff_note', 'Agreed Business, own server, starts Monday.')->assertJsonPath('data.0.handled_by', $admin->name);

        $storeId = $this->actingAs($admin)->postJson('/api/v1/super-admin/stores', [
            'store_name' => 'Rose Attars', 'business_category' => 'fashion', 'package_code' => 'business', 'owner_email' => 'ayesha@example.com', 'trial_days' => 0, 'store_request_id' => $id,
        ])->assertCreated()->json('data.id');
        $request = StoreRequest::query()->sole();
        $this->assertSame(['store_created', $storeId], [$request->status, $request->store_id]);
        $this->actingAs($admin)->getJson('/api/v1/super-admin/store-requests')->assertJsonPath('data.0.store.id', $storeId);

        // Not twice, and the request can no longer be reopened.
        $this->actingAs($admin)->postJson('/api/v1/super-admin/stores', [
            'store_name' => 'Again', 'business_category' => 'fashion', 'package_code' => 'business', 'owner_email' => 'x@example.com', 'store_request_id' => $id,
        ])->assertUnprocessable()->assertJsonValidationErrors('store_request_id');
        $this->actingAs($admin)->putJson("/api/v1/super-admin/store-requests/{$id}", ['status' => 'new'])->assertUnprocessable();
        $this->assertSame(1, Store::query()->withoutGlobalScopes()->where('name', 'Rose Attars')->count());
    }

    public function test_a_request_can_be_removed_and_the_home_page_asks_instead_of_signing_up_when_sign_up_is_closed(): void
    {
        $this->postJson('/api/v1/public/store-requests', $this->request())->assertStatus(202);
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $this->actingAs($admin)->deleteJson('/api/v1/super-admin/store-requests/'.StoreRequest::query()->sole()->public_id)->assertOk();
        $this->assertSame(0, StoreRequest::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'platform.store_request_removed')->count());
        $this->app['auth']->forgetGuards();
        auth()->guard('web')->logout();

        $config = app(\App\Domain\Settings\Services\ConfigService::class);
        $config->set('platform.self_signup_enabled', false, \App\Domain\Settings\Models\SettingScope::Platform, $admin->id, 'Owner decision 2026-10-30');
        $config->set('platform.contact_whatsapp', '923001234567', \App\Domain\Settings\Models\SettingScope::Platform, $admin->id);
        $this->withoutVite()->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome')
            ->where('signupOpen', false)->where('contactWhatsapp', '923001234567')->has('businessCategories'));
        // Three requests in ten minutes from one place; the fourth waits.
        $this->postJson('/api/v1/public/store-requests', $this->request(['email' => 'b@example.com']))->assertStatus(202);
        $this->postJson('/api/v1/public/store-requests', $this->request(['email' => 'c@example.com']))->assertStatus(202);
        $this->postJson('/api/v1/public/store-requests', $this->request(['email' => 'd@example.com']))->assertStatus(429);
        $this->postJson('/api/v1/auth/register',['name' => 'X', 'email' => 'x@example.com', 'password' => 'correct-horse-battery-99', 'password_confirmation' => 'correct-horse-battery-99', 'store_name' => 'X'])
            ->assertForbidden();
    }
}
