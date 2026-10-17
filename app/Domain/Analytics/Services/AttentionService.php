<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnStatus;
use App\Domain\Tenancy\Models\Store;

/**
 * UI-17 — what needs the store's attention now, for the bell in the admin
 * header: one short list instead of opening six pages. Each entry is only
 * counted for a user who may open the page it links to (the same
 * permission as that page's API); nothing is shown with a count of zero.
 * Reads the active store only (tenant scope).
 */
final class AttentionService extends BaseTenantPolicy
{
    /** @return list<array{key: string, count: int, tone: string, href: string}> */
    public function for(User $user, Store $store): array
    {
        $items = [];
        $add = function (string $key, int $count, string $tone, string $href) use (&$items): void {
            if ($count > 0) {
                $items[] = ['key' => $key, 'count' => $count, 'tone' => $tone, 'href' => $href];
            }
        };
        $may = fn (string $permission): bool => $this->isOwner($user) || $this->userHasPermission($user, $permission);

        if ($may('billing.view')) {
            $add('invoice_overdue', Invoice::query()->where('status', InvoiceStatus::Open->value)->where('due_at', '<=', now())->count(), 'red', '/billing');
        }
        if ($may('orders.view')) {
            $add('orders_to_confirm', Order::query()->where('status', OrderStatus::PendingConfirmation->value)->count(), 'amber', '/orders?status=pending_confirmation');
            $add('orders_to_ship', Order::query()->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Processing->value, OrderStatus::ReadyToFulfill->value])->count(), 'blue', '/orders?status=confirmed');
        }
        if ($may('payments.view')) {
            // Bank transfers wait for the store to check its account; cash on delivery is collected by the courier.
            $add('transfers_to_check', Payment::query()->where('method', 'bank_transfer')->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::RequiresAction->value])->count(), 'amber', '/payments?status=pending');
        }
        if ($may('returns.view')) {
            $add('returns_requested', ReturnRequest::query()->where('status', ReturnStatus::Requested->value)->count(), 'amber', '/returns?status=requested');
        }
        if ($may('inventory.view')) {
            $add('low_stock', Inventory::query()->whereNotNull('reorder_point')->whereRaw('(on_hand - reserved) <= reorder_point')->count(), 'amber', '/inventory?stock=low');
        }
        if ($may('storefront.manage') && $store->activated_at === null) {
            $add('store_not_open', 1, 'blue', '/');
        }

        return $items;
    }
}
