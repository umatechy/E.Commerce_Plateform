<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Domain\Marketing\Exceptions\InvalidCampaignStateTransitionException;
use App\Domain\Marketing\Models\CampaignStatus;
use App\Domain\Marketing\Services\CampaignStateMachine;
use Tests\TestCase;

/**
 * Phase B10 — Campaign state machine (Module 15 §7, Step 6). Pure unit
 * tests — no database required.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CampaignStateMachineTest extends TestCase
{
    public function test_draft_to_active_is_valid(): void
    {
        (new CampaignStateMachine())->assertCanTransition(CampaignStatus::Draft, CampaignStatus::Active);
        $this->assertTrue(true);
    }

    public function test_draft_to_scheduled_is_valid(): void
    {
        (new CampaignStateMachine())->assertCanTransition(CampaignStatus::Draft, CampaignStatus::Scheduled);
        $this->assertTrue(true);
    }

    public function test_active_to_paused_is_valid(): void
    {
        (new CampaignStateMachine())->assertCanTransition(CampaignStatus::Active, CampaignStatus::Paused);
        $this->assertTrue(true);
    }

    public function test_completed_is_terminal(): void
    {
        $this->expectException(InvalidCampaignStateTransitionException::class);
        (new CampaignStateMachine())->assertCanTransition(CampaignStatus::Completed, CampaignStatus::Active);
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->expectException(InvalidCampaignStateTransitionException::class);
        (new CampaignStateMachine())->assertCanTransition(CampaignStatus::Cancelled, CampaignStatus::Draft);
    }

    public function test_cannot_reactivate_a_failed_campaign(): void
    {
        $this->expectException(InvalidCampaignStateTransitionException::class);
        (new CampaignStateMachine())->assertCanTransition(CampaignStatus::Failed, CampaignStatus::Active);
    }
}
