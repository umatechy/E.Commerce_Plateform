<?php

declare(strict_types=1);

namespace App\Domain\Returns\Services;

use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Order;
use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module 09 §8–9 (Phase B34): a guest and the returns of their order.
 *
 * A guest has no account, so nothing they type can prove an order is
 * theirs — an order number and an email are known to anyone who saw the
 * parcel. The proof is the mailbox: the guest asks with the order number
 * and the email, and a link goes to the ORDER's email address.
 *
 * - The lookup always answers the same, whether or not an order matched
 *   (nothing can be learned about orders or addresses by asking).
 * - The link holds a random token; only its SHA-256 is stored. It works
 *   for `returns.guest_link_hours` and for one order of one store.
 * - It is for orders nobody can sign in for: guest orders, and orders of
 *   a customer record without an account. A customer with an account
 *   uses their account.
 * - At most one link per order per 5 minutes.
 */
final class GuestReturnLinks
{
    public function __construct(private readonly NotificationService $notifications, private readonly SeoResolver $seo) {}

    /** Sends the link if this order number and email belong together; says nothing either way. */
    public function request(Store $store, string $orderNumber, string $email): void
    {
        $email = mb_strtolower(trim($email));
        $order = Order::query()->with('customer')->where('order_number', trim($orderNumber))->first();
        if ($order === null || mb_strtolower((string) ($order->customer->email ?? $order->guest_email)) !== $email) {
            return;
        }
        if ($order->customer !== null && $order->customer->isRegistered()) {
            return; // they have an account: their orders and returns are there
        }

        $recent = DB::table('return_guest_links')->where('order_id', $order->id)->where('created_at', '>', now()->subMinutes(5))->exists();
        if ($recent) {
            return;
        }

        $token = Str::random(64);
        DB::table('return_guest_links')->insert([
            'store_id' => $store->id, 'order_id' => $order->id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours((int) config('returns.guest_link_hours')), 'created_at' => now(),
        ]);

        $link = rtrim($this->seo->forStoreHome($store)->canonicalUrl, '/').'/returns?'.http_build_query(['token' => $token]);
        $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::Customer, $order->customer_id, $email,
            'Your return link for order {{order.number}}',
            'Hi {{customer.name}}, use this link to ask for a return of order {{order.number}} or to follow one: {{return.link}} It works for {{return.hours}} hours. If you did not ask for it, you can ignore this email.',
            [
                'order.number' => $order->order_number,
                'customer.name' => $order->customer->name ?? $order->guest_name ?? 'there',
                'return.hours' => (int) config('returns.guest_link_hours'),
            ],
            "notification:return-link:{$order->id}:".hash('sha256', $token),
            'return.guest_link_requested',
            secretVariables: ['return.link' => $link],
        );
    }

    /** The order this token opens in this store, or null (unknown, expired, another store). */
    public function order(Store $store, ?string $token): ?Order
    {
        if (! is_string($token) || strlen($token) !== 64) {
            return null;
        }

        $orderId = DB::table('return_guest_links')
            ->where('token_hash', hash('sha256', $token))
            ->where('store_id', $store->id)
            ->where('expires_at', '>', now())
            ->value('order_id');

        return $orderId === null ? null : Order::query()->find($orderId);
    }
}
