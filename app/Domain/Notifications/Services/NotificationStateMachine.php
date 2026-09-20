<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Exceptions\InvalidNotificationStateTransitionException;
use App\Domain\Notifications\Models\NotificationStatus;

/**
 * Module 21 §27, this milestone's Step 22. Mirrors every other core
 * state machine in this codebase (Order/Payment/Shipment/Campaign) —
 * the ONLY place a NotificationMessage's status transition validity
 * is decided.
 */
final class NotificationStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'created' => ['queued', 'suppressed', 'cancelled', 'rejected'],
        'queued' => ['processing', 'cancelled'],
        'processing' => ['sent', 'failed', 'rejected', 'retry_pending'],
        'sent' => ['delivered', 'bounced', 'failed'],
        'failed' => ['retry_pending', 'expired'],
        'retry_pending' => ['processing', 'expired'],
        // Delivered/Bounced/Rejected/Suppressed/Cancelled/Expired are
        // terminal — see NotificationStatus::isTerminal(); no outgoing
        // transitions registered, which IS the enforcement mechanism.
    ];

    /**
     * @throws InvalidNotificationStateTransitionException
     */
    public function assertCanTransition(NotificationStatus $from, NotificationStatus $to): void
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new InvalidNotificationStateTransitionException($from, $to);
        }
    }
}
