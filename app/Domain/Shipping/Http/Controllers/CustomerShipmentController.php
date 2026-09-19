<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Controllers;

use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Shipping\Http\Resources\ShipmentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Module 13 §74 "Customer Tracking". Authenticated-customer-only,
 * ownership resolved by direct identity match (order.customer_id ===
 * the authenticated Customer's own id) — same authorization model as
 * Cart/Wishlist (Phase B6), not the staff Role/Permission Policy
 * pattern, since this is the CUSTOMER's own order, not a staff
 * resource. Never exposes carrier credentials or another customer's
 * data — ShipmentResource's field list is already the safe,
 * customer-appropriate one (shared with the staff view).
 */
final class CustomerShipmentController
{
    public function index(Request $request, string $orderPublicId): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $order = Order::query()->where('public_id', $orderPublicId)->where('customer_id', $customer->id)->firstOrFail();

        return ShipmentResource::collection(
            $order->shipments()->with(['items', 'trackingEvents'])->get()
        );
    }
}
