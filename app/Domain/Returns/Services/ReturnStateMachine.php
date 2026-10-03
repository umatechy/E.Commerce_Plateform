<?php

declare(strict_types=1);

namespace App\Domain\Returns\Services;

use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\Models\ReturnStatus;

/**
 * Module 09 §46 "Return States": the only place that decides which
 * return status may follow which, in the pattern of OrderStateMachine.
 * ReturnService asks here before it writes a status; nothing else
 * writes one.
 *
 *   requested ─▶ under_review ─▶ approved ─▶ in_transit ─▶ received ─▶ inspected
 *       │             │             │                                     │
 *       └─▶ rejected ◀┘             └─▶ received (handed over directly)   ├─▶ approved_for_refund ─▶ completed
 *       └─▶ cancelled (until the goods are received)                      ├─▶ completed (replacement or exchange order made)
 *                                                                         └─▶ rejected (nothing was accepted)
 */
final class ReturnStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'requested' => ['under_review', 'approved', 'rejected', 'cancelled'],
        'under_review' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['in_transit', 'received', 'cancelled'],
        'in_transit' => ['received', 'cancelled'],
        'received' => ['inspected'],
        'inspected' => ['approved_for_refund', 'completed', 'rejected'],
        'approved_for_refund' => ['completed'],
        // rejected, completed, cancelled: closed.
    ];

    /** @throws ReturnActionRefusedException */
    public function assertCanTransition(ReturnStatus $from, ReturnStatus $to): void
    {
        if (! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw new ReturnActionRefusedException(
                "A return that is {$this->words($from)} cannot become {$this->words($to)}.",
                'invalid_return_transition',
            );
        }
    }

    public function can(ReturnStatus $from, ReturnStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    private function words(ReturnStatus $status): string
    {
        return str_replace('_', ' ', $status->value);
    }
}
