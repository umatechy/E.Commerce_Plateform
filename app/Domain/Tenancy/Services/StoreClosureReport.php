<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Settings\Services\Currencies;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreLifecycleEvent;
use App\Domain\Tenancy\Models\StoreStatus;
use Illuminate\Support\Facades\DB;

/**
 * Phase B51 (Module 03 §35, §40; Module 29 §159) — what an owner should
 * know before closing, and where a closed store stands. Nothing here stops
 * a closure (it is the owner's right); it shows plainly what is still open
 * so nothing is left behind by surprise. Read by explicit store id.
 */
final class StoreClosureReport
{
    private const OPEN_ORDER_STATUSES = ['pending_confirmation', 'confirmed', 'processing', 'ready_to_fulfill', 'fulfilling', 'shipped', 'refund_pending'];

    private const OPEN_RETURN_STATUSES = ['requested', 'under_review', 'approved', 'in_transit', 'received', 'inspected', 'approved_for_refund'];

    public function __construct(private readonly StoreLifecycleService $lifecycle) {}

    /** @return array<string, mixed> */
    public function forStore(Store $store): array
    {
        $creditByCurrency = DB::table('store_credit_accounts')->where('store_id', $store->id)->where('balance_minor', '>', 0)
            ->selectRaw('currency, sum(balance_minor) as total, count(*) as n')->groupBy('currency')->get();
        $openInvoices = DB::table('invoices')->where('store_id', $store->id)->where('status', 'open')
            ->selectRaw('currency, sum(total_minor - amount_paid_minor - amount_credited_minor) as due, count(*) as n')->groupBy('currency')->get();

        $open = array_values(array_filter([
            $this->item('orders', DB::table('orders')->where('store_id', $store->id)->whereIn('status', self::OPEN_ORDER_STATUSES)->count(),
                'orders are not finished yet', 'Customers who paid or are waiting for a parcel should hear from you. Closing does not cancel or refund them.'),
            $this->item('returns', DB::table('return_requests')->where('store_id', $store->id)->whereIn('status', self::OPEN_RETURN_STATUSES)->count(),
                'returns are still open', 'Finish them, or tell the customers how you will handle them.'),
            $creditByCurrency->isEmpty() ? null : [
                'key' => 'store_credit', 'count' => (int) $creditByCurrency->sum('n'),
                'label' => 'customers still have store credit: '.$creditByCurrency->map(fn ($r) => Currencies::readable((int) $r->total, (string) $r->currency))->implode(', '),
                'hint' => 'Store credit can no longer be spent once the shop is closed.',
            ],
            $openInvoices->isEmpty() ? null : [
                'key' => 'invoices', 'count' => (int) $openInvoices->sum('n'),
                'label' => 'Umar Techy invoices are unpaid: '.$openInvoices->map(fn ($r) => Currencies::readable((int) $r->due, (string) $r->currency))->implode(', '),
                'hint' => 'Invoices already issued stay due after closing (no new ones are issued).',
            ],
            $this->item('domains', DB::table('domains')->where('store_id', $store->id)->where('domain_type', 'custom_domain')->whereNotIn('status', ['removed', 'disabled'])->count(),
                'own web addresses point to this store', 'They stop showing the shop. Point them elsewhere at your domain company when you are ready.'),
        ]));

        return [
            'status' => $store->status->value,
            'stage_label' => StoreLifecycle::label($store->status),
            'closed_at' => $store->closed_at?->toIso8601String(),
            'suspended_at' => $store->suspended_at?->toIso8601String(),
            'suspension_reason' => $store->suspension_reason,
            'retention_ends_at' => $store->retention_ends_at?->toIso8601String(),
            'retention_days' => $this->lifecycle->retentionDays(),
            'can_close' => in_array($store->status->value, ['pending_setup', 'active'], true),
            'can_reopen' => $store->status === StoreStatus::Cancelled && $store->suspended_at === null && ($store->retention_ends_at?->isFuture() ?? false),
            'open_items' => $open,
            'reasons' => StoreLifecycleService::CLOSURE_REASONS,
            'history' => StoreLifecycleEvent::query()->where('store_id', $store->id)->orderByDesc('id')->limit(20)->get()
                ->map(fn (StoreLifecycleEvent $e) => ['action' => $e->action, 'from' => $e->from_status, 'to' => $e->to_status, 'by' => $e->actor_type, 'at' => $e->created_at->toIso8601String()])->all(),
        ];
    }

    /** @return array{key: string, count: int, label: string, hint: string}|null */
    private function item(string $key, int $count, string $label, string $hint): ?array
    {
        return $count === 0 ? null : ['key' => $key, 'count' => $count, 'label' => "{$count} {$label}", 'hint' => $hint];
    }
}
