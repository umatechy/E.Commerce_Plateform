<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Cart\Models\WishlistItem;
use App\Domain\Compliance\Exceptions\CustomerErasureBlockedException;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * Module 32 "Compliance" — a customer's right of access (export) and
 * right to erasure (Phase B22). The store is the data controller, so
 * both are store-staff actions (plus a self-service export for the
 * customer).
 *
 * Erasure anonymizes rather than deletes: orders are financial records
 * that must be retained (ADR-003 §43), so they stay — with every personal
 * field removed — and keep pointing at the anonymized customer row.
 */
final class CustomerDataService
{
    /** Orders in these states are still being worked on; erasing their customer would break fulfilment. */
    private const OPEN_ORDER_STATUSES = [
        OrderStatus::Draft, OrderStatus::PendingConfirmation, OrderStatus::Confirmed, OrderStatus::Processing,
        OrderStatus::ReadyToFulfill, OrderStatus::Fulfilling, OrderStatus::Shipped,
        OrderStatus::ReturnRequested, OrderStatus::RefundPending,
    ];

    private const ERASED_NAME = 'Erased customer';

    /** @return array<string, mixed> */
    public function export(Customer $customer): array
    {
        $orders = Order::query()->where('customer_id', $customer->id)->with('items')->orderBy('created_at')->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'customer' => [
                'id' => $customer->public_id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'has_account' => $customer->password !== null,
                'marketing_email_opt_in' => (bool) $customer->marketing_email_opt_in,
                'created_at' => $customer->created_at?->toIso8601String(),
                'erased_at' => $customer->erased_at?->toIso8601String(),
            ],
            // Phase B32: the address book (B25) is personal data too.
            'addresses' => \App\Domain\CustomerAccount\Models\CustomerAddress::query()->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(fn (\App\Domain\CustomerAccount\Models\CustomerAddress $a) => $a->only(['label', 'name', 'phone', 'line1', 'line2', 'city', 'province', 'postal_code', 'country', 'is_default']))->all(),
            'orders' => $orders->map(fn (Order $order) => [
                'order_number' => $order->order_number,
                'status' => $order->status->value,
                'currency' => $order->currency,
                'grand_total_minor' => $order->grand_total_minor,
                'placed_at' => $order->created_at?->toIso8601String(),
                'billing_address' => $order->billing_address_snapshot,
                'shipping_address' => $order->shipping_address_snapshot,
                'notes' => $order->notes,
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'product' => $item->product_name_snapshot,
                    'sku' => $item->sku_snapshot,
                    'quantity' => $item->quantity,
                    'line_total_minor' => $item->line_total_minor,
                ])->all(),
            ])->all(),
            // Phase B33: the returns the customer asked for, with their own words.
            'returns' => \App\Domain\Returns\Models\ReturnRequest::query()->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(fn (\App\Domain\Returns\Models\ReturnRequest $return) => [
                    'return_number' => $return->return_number, 'status' => $return->status->value, 'resolution' => $return->resolution->value,
                    'reason' => $return->reason->value, 'description' => $return->description, 'requested_at' => $return->created_at->toIso8601String(),
                    'refunded_minor' => $return->refunded_minor, 'currency' => $return->currency,
                ])->all(),
            // Phase B34: store credit, every entry.
            'store_credit' => \App\Domain\StoreCredit\Models\StoreCreditEntry::query()->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(fn (\App\Domain\StoreCredit\Models\StoreCreditEntry $entry) => [
                    'type' => $entry->type->value, 'amount_minor' => $entry->amount_minor, 'balance_after_minor' => $entry->balance_after_minor,
                    'currency' => $entry->currency, 'at' => $entry->created_at->toIso8601String(),
                ])->all(),
            'wishlist' => WishlistItem::query()->where('customer_id', $customer->id)->with('product')->get()
                ->map(fn (WishlistItem $item) => ['product' => $item->product?->name, 'added_at' => $item->created_at->toIso8601String()])
                ->all(),
            'notifications' => $this->notificationsFor($customer)->get()
                ->map(fn (NotificationMessage $message) => [
                    'channel' => $message->channel->value,
                    'subject' => $message->subject,
                    'status' => $message->status->value,
                    'sent_at' => $message->sent_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * @return array{orders_anonymized: int, notifications_anonymized: int, wishlist_items_removed: int, tokens_revoked: int}
     *
     * @throws CustomerErasureBlockedException
     */
    public function erase(Customer $customer): array
    {
        $openOrders = Order::query()->where('customer_id', $customer->id)
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, self::OPEN_ORDER_STATUSES))
            ->count();

        if ($openOrders > 0) {
            throw new CustomerErasureBlockedException($openOrders);
        }

        return DB::transaction(function () use ($customer) {
            $orders = Order::query()->where('customer_id', $customer->id)->get();

            foreach ($orders as $order) {
                $order->update([
                    'guest_name' => null,
                    'guest_email' => null,
                    'guest_phone' => null,
                    'notes' => null,
                    // Country is kept: it is not identifying on its own and
                    // tax/revenue reporting by country depends on it.
                    'billing_address_snapshot' => $this->redactAddress($order->billing_address_snapshot),
                    'shipping_address_snapshot' => $this->redactAddress($order->shipping_address_snapshot),
                ]);
            }

            $notifications = $this->notificationsFor($customer)->update([
                'destination' => 'erased',
                'subject' => null,
                'body' => '[erased]',
            ]);

            $wishlist = WishlistItem::query()->where('customer_id', $customer->id)->delete();
            $tokens = $customer->tokens()->delete();
            // Phase B32 (Module 10 §32): staff notes may hold personal details;
            // tags, group and the email links go with the person.
            $notes = \App\Domain\Customers\Models\CustomerNote::query()->where('customer_id', $customer->id)->delete();
            // The address book (B25) was missed by erasure until Phase B32.
            $addresses = \App\Domain\CustomerAccount\Models\CustomerAddress::query()->where('customer_id', $customer->id)->delete();
            $customer->tags()->detach();
            // Phase B34: nobody can use the balance any more; it ends on the record (the ledger stays, as financial history).
            app(\App\Domain\StoreCredit\Services\StoreCreditService::class)->endForErasedCustomer($customer);
            // Phase B33: what the person wrote on a return, and their parcel's tracking number. The
            // return itself stays: it belongs to the order's financial record.
            $returns = \App\Domain\Returns\Models\ReturnRequest::query()->where('customer_id', $customer->id)
                ->update(['description' => null, 'return_tracking_number' => null]);
            // Phase B34: their photos show their goods and may show their home: the files go.
            app(\App\Domain\Returns\Services\ReturnPhotoService::class)->removeForReturns(
                \App\Domain\Returns\Models\ReturnRequest::query()->where('customer_id', $customer->id)->pluck('id'),
            );
            \Illuminate\Support\Facades\DB::table('customer_email_verifications')->where('customer_id', $customer->id)->delete();

            // No password: the account can never be signed into again. The
            // email is replaced by a unique, undeliverable placeholder.
            $customer->forceFill([
                'name' => self::ERASED_NAME,
                'email' => "erased+{$customer->public_id}@erased.invalid",
                'phone' => null,
                'password' => null,
                'email_verified_at' => null,
                'marketing_email_opt_in' => false,
                'erased_at' => now(),
                'customer_group_id' => null,
                'status_reason' => null,
            ])->save();

            return [
                'orders_anonymized' => $orders->count(),
                'notifications_anonymized' => $notifications,
                'wishlist_items_removed' => $wishlist,
                'tokens_revoked' => $tokens,
                'notes_removed' => $notes,
                'addresses_removed' => $addresses,
                'returns_anonymized' => $returns,
            ];
        });
    }

    /** @return \Illuminate\Database\Eloquent\Builder<NotificationMessage> */
    private function notificationsFor(Customer $customer): \Illuminate\Database\Eloquent\Builder
    {
        return NotificationMessage::query()
            ->where('recipient_type', RecipientType::Customer->value)
            ->where('recipient_id', $customer->id);
    }

    /**
     * @param ?array<string, mixed> $address
     * @return ?array<string, mixed>
     */
    private function redactAddress(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return ['country' => $address['country'] ?? null, 'redacted' => true];
    }
}
