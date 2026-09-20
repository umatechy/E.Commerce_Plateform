<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Marketing\Jobs\ProcessCampaignExecutionJob;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignStatus;
use Illuminate\Support\Str;

/**
 * The ONLY code path that transitions a Campaign's status (mirrors
 * every other core domain service's "service-only writes" pattern).
 *
 * AUDIENCE EVALUATION TIMING (Module 15 §9, this milestone's own
 * explicit "must be explicit" requirement): B10's documented choice is
 * EXECUTION-TIME evaluation — the audience is (re)computed every time
 * ProcessCampaignExecutionJob actually runs (on activation AND on
 * resume-from-pause), never frozen at scheduling time. A newly-
 * matching segment member added after a campaign was scheduled is
 * therefore still included when the job eventually runs.
 */
final class CampaignService
{
    public function __construct(private readonly CampaignStateMachine $stateMachine, private readonly RecordsOutboxEvents $outbox) {}

    /**
     * @throws \App\Domain\Marketing\Exceptions\InvalidCampaignStateTransitionException
     */
    public function activate(Campaign $campaign, ?\DateTimeInterface $scheduledAt): Campaign
    {
        $now = now();

        if ($scheduledAt !== null && $scheduledAt->getTimestamp() > $now->getTimestamp()) {
            $this->transitionTo($campaign, CampaignStatus::Scheduled);
            $campaign->update(['scheduled_at' => $scheduledAt]);

            return $campaign->fresh();
        }

        return $this->dispatchExecution($campaign);
    }

    /**
     * Called both for immediate activation AND by the scheduler once a
     * Scheduled campaign's time arrives. Idempotency key guards against
     * the scheduler dispatching the SAME campaign's execution twice
     * (e.g. two overlapping scheduler runs) — mirrors every other
     * idempotency-key pattern in this codebase (B5 Order, B7 Payment).
     */
    public function dispatchExecution(Campaign $campaign): Campaign
    {
        if ($campaign->idempotency_key !== null) {
            return $campaign; // execution already dispatched — never dispatch twice
        }

        $this->transitionTo($campaign, CampaignStatus::Active);
        $campaign->update(['activated_at' => now(), 'idempotency_key' => (string) Str::uuid()]);

        $this->outbox->recordEvent(
            eventType: 'marketing.campaign_activated',
            payload: ['campaign_id' => $campaign->id],
            idempotencyKey: "campaign:{$campaign->id}:activated",
        );

        ProcessCampaignExecutionJob::dispatch($campaign->id);

        return $campaign->fresh();
    }

    /** Resume re-dispatches execution (see class docblock on execution-time audience evaluation) — safe because CampaignRecipient's unique constraint prevents re-queuing an already-queued customer. */
    public function resume(Campaign $campaign): Campaign
    {
        $this->transitionTo($campaign, CampaignStatus::Active);
        ProcessCampaignExecutionJob::dispatch($campaign->id);

        return $campaign->fresh();
    }

    public function pause(Campaign $campaign): Campaign
    {
        $this->transitionTo($campaign, CampaignStatus::Paused);

        return $campaign->fresh();
    }

    public function cancel(Campaign $campaign): Campaign
    {
        $this->stateMachine->assertCanTransition($campaign->status, CampaignStatus::Cancelled);
        $this->transitionTo($campaign, CampaignStatus::Cancelled);

        return $campaign->fresh();
    }

    /**
     * Called by ProcessCampaignExecutionJob once the audience has been
     * fully processed.
     */
    public function markCompleted(Campaign $campaign): void
    {
        $this->transitionTo($campaign, CampaignStatus::Completed);
        $campaign->update(['completed_at' => now()]);

        $this->outbox->recordEvent(
            eventType: 'marketing.campaign_completed',
            payload: ['campaign_id' => $campaign->id],
            idempotencyKey: "campaign:{$campaign->id}:completed",
        );
    }

    private function transitionTo(Campaign $campaign, CampaignStatus $to): void
    {
        $this->stateMachine->assertCanTransition($campaign->status, $to);
        $campaign->update(['status' => $to]);
    }
}
