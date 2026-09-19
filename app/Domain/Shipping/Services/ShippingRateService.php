<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Services;

use App\Domain\Shipping\Exceptions\DestinationNotServiceableException;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingMethodType;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use Illuminate\Support\Collection;

/**
 * Module 13 §38-40 "Shipping Rate Engine / Server-Authoritative Rate /
 * Shipping Method Eligibility". This is the ONLY place shipping cost is
 * calculated — CheckoutService calls this, never computes a cost
 * itself, and the client NEVER supplies a cost (Final Rule #4).
 *
 * WEIGHT NOTE: `Product` (simple products, Phase B3) has no weight
 * column — only `ProductVariant` does. Weight-based calculation here
 * uses variant weight where available and treats an item with no
 * weight data as 0kg — a documented limitation (see
 * docs/development/b8-inspection-findings.md), not silently assumed
 * correct. No new column was added to the Catalog domain's Product
 * model from this Shipping-module phase, keeping domain boundaries
 * clean (this milestone's own "keep Shipping separate from Order/
 * Payment/Inventory" instruction, extended reasonably to Catalog too).
 */
final class ShippingRateService
{
    /**
     * Module 13 §9 "Zone Priority" — deterministic, never random.
     * Picks the single HIGHEST-specificity zone that matches the given
     * destination; falls back to the store's `is_default` zone if none
     * match exactly.
     */
    public function resolveZone(array $destination): ?ShippingZone
    {
        $zones = ShippingZone::query()->where('is_active', true)->get();

        $matching = $zones->filter(fn (ShippingZone $zone) => ! $zone->is_default && $zone->matches($destination));

        if ($matching->isNotEmpty()) {
            return $matching->sortByDesc(fn (ShippingZone $zone) => $zone->specificity())->first();
        }

        return $zones->firstWhere('is_default', true);
    }

    /**
     * @param list<array{weight: float}> $items
     * @return Collection<int, array{method: ShippingMethod, cost_minor: int}>
     */
    public function eligibleMethods(array $destination, int $subtotalMinor, array $items, string $currency): Collection
    {
        $zone = $this->resolveZone($destination);

        if ($zone === null) {
            return collect();
        }

        return $zone->rates()->with('method')->get()
            ->filter(fn (ShippingRate $rate) => $rate->method->is_active && $rate->currency === $currency)
            ->map(fn (ShippingRate $rate) => [
                'method' => $rate->method,
                'cost_minor' => $this->calculateCost($rate, $rate->method, $subtotalMinor, $items),
            ])
            ->values();
    }

    /**
     * @param list<array{weight: float}> $items
     * @throws DestinationNotServiceableException
     */
    public function quote(int $shippingMethodId, array $destination, int $subtotalMinor, array $items, string $currency): array
    {
        $method = ShippingMethod::query()->find($shippingMethodId);

        if ($method === null || ! $method->is_active) {
            throw new DestinationNotServiceableException();
        }

        $zone = $this->resolveZone($destination);

        if ($zone === null) {
            throw new DestinationNotServiceableException();
        }

        $rate = ShippingRate::query()
            ->where('shipping_zone_id', $zone->id)
            ->where('shipping_method_id', $method->id)
            ->where('currency', $currency)
            ->first();

        if ($rate === null) {
            // Module 13 §40: a method may be unavailable for this
            // destination — not an application error, a legitimate
            // "not serviceable" outcome.
            throw new DestinationNotServiceableException();
        }

        return [
            'method' => $method,
            'zone' => $zone,
            'cost_minor' => $this->calculateCost($rate, $method, $subtotalMinor, $items),
        ];
    }

    /** @param list<array{weight: float}> $items */
    private function calculateCost(ShippingRate $rate, ShippingMethod $method, int $subtotalMinor, array $items): int
    {
        return match ($method->type) {
            ShippingMethodType::Free => $this->isFreeShippingEligible($method, $subtotalMinor) ? 0 : $rate->base_cost_minor,
            ShippingMethodType::StorePickup, ShippingMethodType::LocalDelivery, ShippingMethodType::FlatRate => $rate->base_cost_minor,
            ShippingMethodType::WeightBased => $this->weightBasedCost($rate, $items),
            ShippingMethodType::PriceBased => $this->priceBasedCost($rate, $subtotalMinor),
        };
    }

    /** Module 13 §17 "Free Shipping" — eligible via order-value threshold (null threshold = always free). */
    private function isFreeShippingEligible(ShippingMethod $method, int $subtotalMinor): bool
    {
        return $method->free_shipping_threshold_minor === null || $subtotalMinor >= $method->free_shipping_threshold_minor;
    }

    /** @param list<array{weight: float}> $items */
    private function weightBasedCost(ShippingRate $rate, array $items): int
    {
        $totalWeight = array_sum(array_column($items, 'weight'));

        if ($rate->per_unit_cost_minor === null) {
            return $rate->base_cost_minor;
        }

        return $rate->base_cost_minor + (int) ceil($totalWeight) * $rate->per_unit_cost_minor;
    }

    /** Documented simplification (Module 13 §19) — a single flat surcharge above one price threshold, not tiered brackets. */
    private function priceBasedCost(ShippingRate $rate, int $subtotalMinor): int
    {
        if ($rate->unit_threshold === null) {
            return $rate->base_cost_minor;
        }

        $thresholdMinor = (int) round(((float) $rate->unit_threshold) * 100);

        return $subtotalMinor > $thresholdMinor ? $rate->base_cost_minor + ($rate->per_unit_cost_minor ?? 0) : $rate->base_cost_minor;
    }
}
