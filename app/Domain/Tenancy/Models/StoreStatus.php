<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

/**
 * Store lifecycle states (Bible §8 Store Lifecycle, Module 03).
 * Persisted as VARCHAR per ADR-003 (never native MySQL ENUM).
 */
enum StoreStatus: string
{
    case PendingSetup = 'pending_setup';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Archived = 'archived';
}
