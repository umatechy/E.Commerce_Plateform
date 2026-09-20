<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\Exceptions\InvalidCampaignStateTransitionException;
use App\Domain\Marketing\Models\CampaignStatus;

/**
 * Module 15 §7, this milestone's Step 6. Mirrors
 * OrderStateMachine/PaymentStateMachine/ShipmentStateMachine's exact
 * pattern — the ONLY place a Campaign's status transition validity is
 * decided.
 */
final class CampaignStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'draft' => ['scheduled', 'active', 'cancelled'], // 'active' directly from draft = immediate (unscheduled) activation
        'scheduled' => ['active', 'cancelled'],
        'active' => ['paused', 'completed', 'failed', 'cancelled'],
        'paused' => ['active', 'cancelled'],
        // Completed/Cancelled/Failed/Archived are terminal — see
        // CampaignStatus::isTerminal(); no outgoing transitions
        // registered, which IS the enforcement mechanism.
    ];

    /**
     * @throws InvalidCampaignStateTransitionException
     */
    public function assertCanTransition(CampaignStatus $from, CampaignStatus $to): void
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new InvalidCampaignStateTransitionException($from, $to);
        }
    }
}
