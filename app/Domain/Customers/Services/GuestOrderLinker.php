<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;

/**
 * Module 10 §9 "Guest to registered conversion": the guest orders placed
 * with an email address join the account of the customer who has just
 * PROVEN that address (email verification, or a password reset sent to
 * it). Never on a name, never on an unverified address (§9: "must avoid
 * accidentally linking another person's orders based only on weak
 * matching").
 *
 * Only orders of this store with no customer are touched; an order's
 * snapshot (name, address, prices) is never rewritten (§23).
 */
final class GuestOrderLinker
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return int the number of orders now on the customer's account */
    public function link(Customer $customer): int
    {
        if ($customer->email_verified_at === null || $customer->erased_at !== null || ! $customer->isRegistered()) {
            return 0;
        }

        $linked = Order::query()
            ->whereNull('customer_id')
            ->whereRaw('LOWER(guest_email) = ?', [mb_strtolower($customer->email)])
            ->update(['customer_id' => $customer->id]);

        if ($linked > 0) {
            $this->audit->record('customer.guest_orders_linked', ['orders' => $linked], $customer, $customer->store_id, $customer);
        }

        return $linked;
    }
}
