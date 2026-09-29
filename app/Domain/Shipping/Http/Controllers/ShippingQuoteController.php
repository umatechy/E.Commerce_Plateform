<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Controllers;

use App\Domain\Cart\Services\CartService;
use App\Domain\Shipping\Http\Resources\ShippingMethodQuoteResource;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Module 13 §81-82 "Shipping API / Shipping Quote API". Guest-
 * accessible (same as Cart/Checkout — a shopper must see shipping
 * options before deciding whether to register). No persisted
 * ShippingQuote entity (see docs/development/b8-inspection-findings.md
 * "No Persisted ShippingQuote Entity") — this recomputes eligible
 * methods and costs live, on every call, from the requester's current
 * cart and the destination they provide.
 */
final class ShippingQuoteController
{
    public function index(Request $request, CartService $carts): AnonymousResourceCollection
    {
        [$cart] = $carts->resolveForRequest($request, 'X-Guest-Cart-Token', 'USD');
        $totals = $carts->totals($cart);

        $items = $cart->items->load('variant')->map(fn ($item) => [
            'weight' => (float) ($item->variant->weight ?? 0) * $item->quantity,
        ])->all();

        $destination = [
            'country' => $request->query('country'),
            'province' => $request->query('province'),
            'city' => $request->query('city'),
            'postal_code' => $request->query('postal_code'),
        ];

        $eligible = app(\App\Domain\Shipping\Services\ShippingRateService::class)
            ->eligibleMethods($destination, $totals['subtotal_minor'], $items, $totals['currency'] ?? 'USD');

        return ShippingMethodQuoteResource::collection($eligible);
    }
}
