<?php

declare(strict_types=1);

namespace App\Domain\Packages\Models;

/**
 * Module 04 §17 "Subscription States" — the module's own suggested list,
 * used verbatim (not invented). B0/B1 only implemented 5 of these 9; the
 * 4 missing were added in B2 without renaming or removing the original 5
 * (see docs/development/b2-inspection-findings.md item B).
 *
 * "The final state machine will be defined in the Billing Blueprint"
 * (Module 29, not yet implemented) — this enum is the state SET;
 * SubscriptionLifecycleService is the current, B2-scope transition
 * logic, expected to be reconciled with Module 29 when that phase
 * begins, not a claim that this IS the final billing state machine.
 */
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case GracePeriod = 'grace_period';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Archived = 'archived';

    /**
     * States in which entitled features/usage limits are evaluated
     * normally (Module 04 §29 "Package Access Evaluation" step 4:
     * "Subscription valid?"). Documented implementation decision (Module
     * 04 does not give the exact allow-list): Trial, Active, and
     * GracePeriod all grant normal access — PastDue also grants access
     * (common SaaS practice: warn, do not immediately cut off, matching
     * §36's "avoid immediate destructive behavior") but is surfaced to
     * the frontend as a warning state. Pending (setup incomplete),
     * Suspended, Cancelled, Expired, and Archived deny feature access.
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::GracePeriod, self::PastDue => true,
            self::Pending, self::Suspended, self::Cancelled, self::Expired, self::Archived => false,
        };
    }
}
