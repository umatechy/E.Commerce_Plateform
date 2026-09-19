<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

/** Module 06 §27 "Product Status" — the module's own suggested list. */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Scheduled = 'scheduled';
    case Hidden = 'hidden';
    case Archived = 'archived';

    /**
     * Whether a product in this status counts against the store's
     * max_products usage limit. Documented implementation decision
     * (Module 06 §52 does not specify this exactly): Draft/Active/
     * Scheduled/Hidden all count (they are live catalog records the
     * store is actively managing); Archived does NOT count, matching
     * §31's "archived products should normally stop appearing" — an
     * archived product has effectively been retired, freeing quota for
     * a new one, while its historical data (§32) is still preserved via
     * soft delete rather than hard deletion.
     */
    public function countsTowardUsageLimit(): bool
    {
        return $this !== self::Archived;
    }

    /** Module 06 §28: "Only appropriate active products should appear publicly." */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Active;
    }
}
