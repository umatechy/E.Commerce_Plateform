<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Jobs;

use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignAudienceType;
use App\Domain\Marketing\Models\CampaignRecipient;
use App\Domain\Marketing\Models\CampaignRecipientStatus;
use App\Domain\Marketing\Models\CampaignStatus;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Marketing\Services\MarketingSegmentService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Module 15 Step 14/Final Rule #17-18 "Campaign Execution must be
 * asynchronous and retry-safe... jobs must be idempotent." This job
 * NEVER sends a real email/SMS/WhatsApp/push message — see
 * docs/development/b10-inspection-findings.md "Campaign Execution Is
 * Boundary-Only." Its entire job is: resolve audience, apply consent +
 * frequency + suppression, create an idempotent CampaignRecipient row
 * per eligible customer, emit an outbox event Module 21 will one day
 * consume.
 *
 * TENANT CONTEXT: resolved from the Campaign row's OWN store_id
 * (loaded fresh from the database by this job's own id lookup, never
 * trusted from an arbitrary job payload field) — Non-Negotiable Rule
 * #17 / this milestone's Step 25 "Do not derive tenant authority
 * solely from an untrusted job payload." Only `$campaignId` (an
 * internal integer this job itself looked up right after dispatch) is
 * serialized into the job payload — not a store_id.
 */
final class ProcessCampaignExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const FREQUENCY_COOLDOWN_HOURS = 24; // Module 15 §17 — documented default, see inspection findings

    public function __construct(public readonly int $campaignId) {}

    public function handle(
        TenantContext $context,
        CampaignService $campaigns,
        MarketingSegmentService $segments,
        RecordsOutboxEvents $outbox,
    ): void {
        $campaign = Campaign::query()->withoutTenantScope()->findOrFail($this->campaignId);
        $context->resolveToStore($campaign->store_id);

        $campaign = Campaign::query()->findOrFail($this->campaignId); // re-fetch WITH tenant scope now that context is resolved, for consistency with every other read below

        // Idempotent re-dispatch guard (Non-Negotiable Rule #8: "No
        // duplicate campaign execution") — if a retried/duplicate job
        // arrives after the campaign already reached a terminal state,
        // this is a safe no-op, never a crash (markCompleted() would
        // otherwise throw, since Completed has no outgoing transition).
        //
        // Only an Active campaign executes: dispatchExecution() activates
        // before dispatching, so a Draft/Scheduled/Paused campaign reaching
        // here (a stale or duplicate job) must not send anything either —
        // markCompleted() would otherwise throw on the invalid transition.
        if ($campaign->status !== CampaignStatus::Active) {
            return;
        }

        $audience = $campaign->audience_type === CampaignAudienceType::AllCustomers
            ? Customer::query()->get()
            : $segments->resolveAudience($campaign->segment);

        foreach ($audience as $customer) {
            $this->processRecipient($campaign, $customer);
        }

        $campaigns->markCompleted($campaign);
    }

    private function processRecipient(Campaign $campaign, Customer $customer): void
    {
        // Idempotency: a retried/re-dispatched job (or a resume-from-
        // pause re-run) never double-processes an already-considered
        // customer — the unique constraint is the actual guarantee;
        // this check just avoids redundant work.
        if (CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('customer_id', $customer->id)->exists()) {
            return;
        }

        if (! $customer->marketing_email_opt_in) {
            $this->recordRecipient($campaign, $customer, CampaignRecipientStatus::SkippedNoConsent);

            return;
        }

        if ($this->isWithinFrequencyCooldown($customer)) {
            $this->recordRecipient($campaign, $customer, CampaignRecipientStatus::SkippedFrequency);

            return;
        }

        DB::transaction(function () use ($campaign, $customer) {
            $recipient = $this->recordRecipient($campaign, $customer, CampaignRecipientStatus::Queued, now());

            app(RecordsOutboxEvents::class)->recordEvent(
                eventType: 'marketing.recipient_queued',
                payload: ['campaign_id' => $campaign->id, 'customer_id' => $customer->id],
                idempotencyKey: "campaign:{$campaign->id}:recipient:{$customer->id}",
            );
        });
    }

    private function isWithinFrequencyCooldown(Customer $customer): bool
    {
        return CampaignRecipient::query()
            ->where('customer_id', $customer->id)
            ->where('status', CampaignRecipientStatus::Queued)
            ->where('created_at', '>=', now()->subHours(self::FREQUENCY_COOLDOWN_HOURS))
            ->exists();
    }

    private function recordRecipient(Campaign $campaign, Customer $customer, CampaignRecipientStatus $status, ?\Illuminate\Support\Carbon $queuedAt = null): CampaignRecipient
    {
        return CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'queued_at' => $queuedAt,
        ]);
    }
}
