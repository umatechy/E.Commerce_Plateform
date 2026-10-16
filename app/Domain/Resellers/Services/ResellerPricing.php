<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Services;

use App\Domain\Resellers\Models\ResellerStore;

/**
 * Phase B58 (Module 28 §26–27) — the partner price: what a store a reseller
 * brought pays for its package. Always worked out here, from the discount
 * in the store's attribution terms; nobody can send a price (§26 "clients
 * must not submit an arbitrary reseller price").
 *
 * The discount stays with the store for as long as it is attributed, also
 * if the reseller is paused or terminated: the client keeps the price it
 * signed up for. It ends only when Umar Techy ends the attribution.
 */
final class ResellerPricing
{
    /** Basis points off the public price for this store (0 without a reseller). */
    public function discountBp(int $storeId): int
    {
        $terms = ResellerStore::query()->where('store_id', $storeId)->whereNull('ended_at')->value('terms');
        $terms = is_string($terms) ? json_decode($terms, true) : $terms;

        return is_array($terms) ? max(0, min(10000, (int) ($terms['client_discount_bp'] ?? 0))) : 0;
    }

    /** The public price after the partner discount, rounded down to the minor unit in the client's favour. */
    public function price(int $storeId, int $publicMinor): int
    {
        return self::discounted($publicMinor, $this->discountBp($storeId));
    }

    public static function discounted(int $publicMinor, int $discountBp): int
    {
        return $publicMinor - intdiv($publicMinor * $discountBp + 9999, 10000);
    }
}
