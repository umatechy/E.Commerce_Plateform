<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignStatus;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B10 — Staff campaign administration, tenant isolation,
 * staff/customer boundary regression (Module 15 §79, Step 22 items
 * 1-6/26/30).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CampaignAdminTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_create_a_campaign(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/campaigns', [
            'name' => 'Spring Sale', 'objective' => 'seasonal_sale', 'audience_type' => 'all_customers',
            'subject' => 'Spring is here!', 'body' => 'Enjoy 10% off.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
    }

    public function test_activating_a_campaign_dispatches_execution(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $campaign = Campaign::factory()->for($store)->create();

        $response = $this->actingAs($owner)->postJson("/api/v1/campaigns/{$campaign->id}/activate", []);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'active');
        Bus::assertDispatched(\App\Domain\Marketing\Jobs\ProcessCampaignExecutionJob::class);
    }

    public function test_activating_with_a_future_date_schedules_instead(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $campaign = Campaign::factory()->for($store)->create();

        $response = $this->actingAs($owner)->postJson("/api/v1/campaigns/{$campaign->id}/activate", [
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scheduled');
        Bus::assertNotDispatched(\App\Domain\Marketing\Jobs\ProcessCampaignExecutionJob::class);
    }

    public function test_pausing_an_active_campaign(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $campaign = Campaign::factory()->for($store)->create(['status' => CampaignStatus::Active]);

        $response = $this->actingAs($owner)->postJson("/api/v1/campaigns/{$campaign->id}/pause");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'paused');
    }

    public function test_cancelling_a_completed_campaign_is_rejected(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $campaign = Campaign::factory()->for($store)->create(['status' => CampaignStatus::Completed]);

        $response = $this->actingAs($owner)->postJson("/api/v1/campaigns/{$campaign->id}/cancel");

        $response->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
    }

    public function test_store_a_cannot_view_store_bs_campaign(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $campaignB = Campaign::factory()->for($storeB)->create();

        $this->actingAs($ownerA)->getJson("/api/v1/campaigns/{$campaignB->id}")->assertStatus(404);
    }

    public function test_customer_token_cannot_access_staff_marketing_routes(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/campaigns');

        $response->assertStatus(401);
    }

    public function test_marketing_management_requires_permission(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'no-marketing-access']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->postJson('/api/v1/campaigns', [
            'name' => 'Test', 'objective' => 'awareness', 'audience_type' => 'all_customers',
            'subject' => 'Hi', 'body' => 'Hello',
        ]);

        $response->assertStatus(403);
    }

    public function test_segment_creation_rejects_a_malicious_field_via_the_api(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/marketing/segments', [
            'name' => 'Malicious', 'rules' => [['field' => '1=1; DROP TABLE customers;', 'operator' => '>=', 'value' => 1]],
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'invalid_segment_rule');
    }
}
