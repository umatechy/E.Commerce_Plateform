<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Domain\Marketing\Jobs\ProcessCampaignExecutionJob;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignRecipient;
use App\Domain\Marketing\Models\CampaignStatus;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Marketing\Services\MarketingSegmentService;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B10 — this milestone's exact "same campaign job is retried /
 * same customer appears in multiple execution batches" scenario (Step
 * 30). Simulated sequentially (no real concurrent process available in
 * this environment — see B4/B5/B7/B8/B9's identical, honestly-labeled
 * precedent). Relies on CampaignRecipient's own
 * unique(campaign_id, customer_id) DATABASE constraint as the actual
 * concurrency guarantee — no new mechanism was built for B10.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 * This test has NOT been run under genuine parallel load; that
 * verification is explicitly deferred to VS Code/CI with a real MySQL
 * instance, per this milestone's Step 30 instruction.
 */
final class CampaignConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_overlapping_executions_of_the_same_campaign_never_double_queue_a_customer(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Customer::factory()->for($store)->create(['marketing_email_opt_in' => true]);
        $campaign = Campaign::factory()->for($store)->create(['status' => CampaignStatus::Active]);

        $runJob = fn () => (new ProcessCampaignExecutionJob($campaign->id))->handle(
            app(TenantContext::class), app(CampaignService::class), app(MarketingSegmentService::class), app(RecordsOutboxEvents::class),
        );

        // First "concurrent" run completes the campaign normally.
        $runJob();

        // A second, overlapping dispatch (e.g. a duplicate queue
        // delivery) arrives — the campaign is now Completed, so the
        // job's own terminal-state guard makes this a safe no-op
        // (see the Second Bug fix in b10-inspection-findings.md) rather
        // than attempting to re-process the audience.
        $runJob();

        $this->assertSame(1, CampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
    }

    public function test_campaign_activation_idempotency_key_prevents_a_second_dispatch(): void
    {
        $store = Store::factory()->create();
        $campaign = Campaign::factory()->for($store)->create();
        $service = app(CampaignService::class);

        app(TenantContext::class)->resolveToStore($store->id);
        $service->dispatchExecution($campaign);
        $keyAfterFirst = $campaign->fresh()->idempotency_key;

        // A second call (e.g. a duplicate activation request racing
        // the first) must not generate a new key or re-transition an
        // already-Active campaign.
        $service->dispatchExecution($campaign->fresh());

        $this->assertSame($keyAfterFirst, $campaign->fresh()->idempotency_key);
    }
}
