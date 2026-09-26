<?php

declare(strict_types=1);

namespace App\Domain\Domains\Services;

use App\Domain\Domains\Exceptions\InvalidDomainStateTransitionException;
use App\Domain\Domains\Models\DomainStatus;

/** Module 19 §19 "Domain Lifecycle" — mirrors every other core state machine's exact pattern. */
final class DomainStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'pending' => ['verification_required', 'active', 'removed'], // 'active' directly from pending = the auto-verified platform subdomain path
        'verification_required' => ['verified', 'removed'],
        'verified' => ['active', 'removed'],
        'active' => ['suspended', 'disabled', 'removed'],
        'suspended' => ['active', 'disabled', 'removed'],
        'disabled' => ['active', 'removed'],
        // Removed is terminal — no outgoing transitions registered.
    ];

    /**
     * @throws InvalidDomainStateTransitionException
     */
    public function assertCanTransition(DomainStatus $from, DomainStatus $to): void
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new InvalidDomainStateTransitionException($from, $to);
        }
    }
}
