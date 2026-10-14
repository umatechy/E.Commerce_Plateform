<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

/**
 * Store lifecycle states (Bible §8 Store Lifecycle, Module 03).
 * Persisted as VARCHAR per ADR-003 (never native MySQL ENUM).
 *
 * Phase B51 (Module 03 §7–8, §35–37): Cancelled is a closed store kept for
 * the retention period (owner decision 5: 90 days) — the owner can still
 * export and reopen it; Archived is past that period, kept for records only;
 * Deleting is an approved permanent deletion under way; Deleted is its end
 * (the store row stays, soft-deleted, so financial records still resolve).
 * Allowed moves: StoreLifecycleService::TRANSITIONS.
 */
enum StoreStatus: string
{
    case PendingSetup = 'pending_setup';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Archived = 'archived';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
}
