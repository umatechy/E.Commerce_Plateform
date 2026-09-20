<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Domain\Marketing\Jobs\ProcessCampaignExecutionJob;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignRecipient;
use App\Domain\Marketing\Models\CampaignStatus;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B10 — Campaign execution: consent enforcement, frequency
 * cooldown, idempotent recipient creation, tenant context resolved
 * from the Campaign row itself (Module 15 Steps 14-16/25).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CampaignExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_opted_in_customer_is_queued(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create(['marketing_email_opt_in' => true]);
        $campaign = Campaign::factory()->for($store)->create();

        (new ProcessCampaignExecutionJob($campaign->id))->handle(
            app(TenantContext::class), app(CampaignService::class),
            app(\App\Domain\Marketing\Services\MarketingSegmentService::class), app(\App\Domain\Events\Support\RecordsOutboxEvents::class),
        );

        $this->assertDatabaseHas('campaign_recipients', ['campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'status' => 'queued']);
    }

    public function test_customer_without_consent_is_skipped_not_queued(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create(['marketing_email_opt_in' => false]);
        $campaign = Campaign::factory()->for($store)->create();

        (new ProcessCampaignExecutionJob($campaign->id))->handle(
            app(TenantContext::class), app(CampaignService::class),
            app(\App\Domain\Marketing\Services\MarketingSegmentService::class), app(\App\Domain\Events\Support\RecordsOutboxEvents::class),
        );

        $this->assertDatabaseHas('campaign_recipients', ['campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'status' => 'skipped_no_consent']);
    }

    public function test_customer_within_frequency_cooldown_is_skipped(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create(['marketing_email_opt_in' => true]);
        $earlierCampaign = Campaign::factory()->for($store)->create();
        CampaignRecipient::query()->create([
            'campaign_id' => $earlierCampaign->id, 'customer_id' => $customer->id, 'status' => 'queued', 'queued_at' => now()->subHours(2),
        ]);
        $campaign = Campaign::factory()->for($store)->create();

        (new ProcessCampaignExecutionJob($campaign->id))->handle(
            app(TenantContext::class), app(CampaignService::class),
            app(\App\Domain\Marketing\Services\MarketingSegmentService::class), app(\App\Domain\Events\Support\RecordsOutboxEvents::class),
        );

        $this->assertDatabaseHas('campaign_recipients', ['campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'status' => 'skipped_frequency']);
    }

    public function test_campaign_completes_after_processing_the_whole_audience(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Customer::factory()->for($store)->create(['marketing_email_opt_in' => true]);
        $campaign = Campaign::factory()->for($store)->create(['status' => CampaignStatus::Active]);

        (new ProcessCampaignExecutionJob($campaign->id))->handle(
            app(TenantContext::class), app(CampaignService::class),
            app(\App\Domain\Marketing\Services\MarketingSegmentService::class), app(\App\Domain\Events\Support\RecordsOutboxEvents::class),
        );

        $this->assertSame('completed', $campaign->fresh()->status->value);
    }

    public function test_rerunning_the_job_does_not_create_a_duplicate_recipient_row(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Customer::factory()->for($store)->create(['marketing_email_opt_in' => true]);
        $campaign = Campaign::factory()->for($store)->create(['status' => CampaignStatus::Active]);

        $job = new ProcessCampaignExecutionJob($campaign->id);
        $job->handle(app(TenantContext::class), app(CampaignService::class), app(\App\Domain\Marketing\Services\MarketingSegmentService::class), app(\App\Domain\Events\Support\RecordsOutboxEvents::class));

        // Re-run against the (now Completed) campaign directly — simulates a retried/duplicate job dispatch.
        $job->handle(app(TenantContext::class), app(CampaignService::class), app(\App\Domain\Marketing\Services\MarketingSegmentService::class), app(\App\Domain\Events\Support\RecordsOutboxEvents::class));

        $this->assertSame(1, CampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
    }

    public function test_segment_audience_only_includes_matching_customers(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $matching = Customer::factory()->for($store)->create(['marketing_email_opt_in' => true]);
        \App\Domain\Orders\Models\Order::factory()->for($store)->create(['customer_id' => $matching->id]);
        $nonMatching = Customer::factory()->for($store)->create(['marketing_email_opt_in' => true]); // no orders

        $segment = \App\Domain\Marketing\Models\MarketingSegment::factory()->for($store)->create([
            'rules' => [['field' => 'total_orders_count', 'operator' => '>=', 'value' => 1]],
        ]);
        $campaign = Campaign::factory()->for($store)->create(['audience_type' => 'segment', 'marketing_segment_id' => $segment->id]);

        (new ProcessCampaignExecutionJob($campaign->id))->handle(
            app(TenantContext::class), app(CampaignService::class),
            app(\App\Domain\Marketing\Services\MarketingSegmentService::class), app(\App\Domain\Events\Support\RecordsOutboxEvents::class),
        );

        $this->assertDatabaseHas('campaign_recipients', ['campaign_id' => $campaign->id, 'customer_id' => $matching->id]);
        $this->assertDatabaseMissing('campaign_recipients', ['campaign_id' => $campaign->id, 'customer_id' => $nonMatching->id]);
    }
}
