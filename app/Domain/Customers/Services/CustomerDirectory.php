<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Customers\Models\CustomerGroup;
use App\Domain\Customers\Models\CustomerStatus;
use App\Domain\Customers\Models\CustomerTag;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Settings\Services\StoreClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Module 10 §51–52: the staff customer list — search, filters, sort and
 * the order figures of each customer (§28).
 *
 * Every query runs under the tenant scope of Customer and Order: a
 * store's list can never contain another store's customer, and the
 * figures count only that store's orders. The figures are derived from
 * the orders themselves (§28 "derived from authoritative order data");
 * cancelled orders do not count.
 */
final class CustomerDirectory
{
    public const SORTS = ['newest', 'oldest', 'name', 'orders', 'spent', 'last_order'];

    public function __construct(private readonly StoreClock $clock) {}

    /** @return array<string, list<mixed>> validation rules for the list filters */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(CustomerStatus::class)],
            'group' => ['nullable', 'string', 'max:26'],
            'tag' => ['nullable', 'string', 'size:26'],
            'consent' => ['nullable', 'in:yes,no'],
            'account' => ['nullable', 'in:registered,no_account'],
            'min_orders' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'registered_from' => ['nullable', 'date'],
            'registered_to' => ['nullable', 'date', 'after_or_equal:registered_from'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
        ];
    }

    /**
     * The customers with their figures, filtered and sorted.
     *
     * @param array<string, mixed> $filters validated with rules()
     * @return Builder<Customer>
     */
    public function query(array $filters): Builder
    {
        $counted = fn () => Order::query()->whereColumn('orders.customer_id', 'customers.id')->where('orders.status', '!=', OrderStatus::Cancelled->value);

        $query = Customer::query()
            ->select('customers.*')
            ->selectSub($counted()->selectRaw('COUNT(*)'), 'orders_count')
            ->selectSub($counted()->selectRaw('COALESCE(SUM(orders.grand_total_minor), 0)'), 'total_spent_minor')
            ->selectSub($counted()->selectRaw('MAX(orders.created_at)'), 'last_order_at')
            ->with(['group', 'tags']);

        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $q) use ($like, $search) {
                $q->where('customers.name', 'like', $like)
                    ->orWhere('customers.email', 'like', $like)
                    ->orWhere('customers.phone', 'like', $like)
                    ->orWhere('customers.public_id', $search)
                    ->orWhereHas('orders', fn (Builder $o) => $o->where('order_number', $search));
            });
        }

        if (! empty($filters['status'])) {
            $query->where('customers.status', $filters['status']);
        }

        if (! empty($filters['group'])) {
            $filters['group'] === 'none'
                ? $query->whereNull('customers.customer_group_id')
                : $query->where('customers.customer_group_id', CustomerGroup::query()->where('public_id', $filters['group'])->value('id') ?? 0);
        }

        if (! empty($filters['tag'])) {
            $tagId = CustomerTag::query()->where('public_id', $filters['tag'])->value('id') ?? 0;
            $query->whereHas('tags', fn (Builder $t) => $t->where('customer_tags.id', $tagId));
        }

        if (($filters['consent'] ?? null) === 'yes') {
            $query->where('customers.marketing_email_opt_in', true);
        } elseif (($filters['consent'] ?? null) === 'no') {
            $query->where('customers.marketing_email_opt_in', false);
        }

        if (($filters['account'] ?? null) === 'registered') {
            $query->whereNotNull('customers.password');
        } elseif (($filters['account'] ?? null) === 'no_account') {
            $query->whereNull('customers.password');
        }

        if (isset($filters['min_orders']) && (int) $filters['min_orders'] > 0) {
            $query->whereHas('orders', fn (Builder $o) => $o->where('status', '!=', OrderStatus::Cancelled->value), '>=', (int) $filters['min_orders']);
        }

        // Days are the store's days (Module 33 §50.3).
        if (! empty($filters['registered_from'])) {
            $query->where('customers.created_at', '>=', $this->clock->parseLocal((string) $filters['registered_from'])->startOfDay()->utc());
        }
        if (! empty($filters['registered_to'])) {
            $query->where('customers.created_at', '<=', $this->clock->parseLocal((string) $filters['registered_to'])->endOfDay()->utc());
        }

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('customers.created_at')->orderBy('customers.id'),
            'name' => $query->orderBy('customers.name')->orderBy('customers.id'),
            'orders' => $query->orderByDesc('orders_count')->orderByDesc('customers.id'),
            'spent' => $query->orderByDesc('total_spent_minor')->orderByDesc('customers.id'),
            'last_order' => $query->orderByRaw('last_order_at IS NULL')->orderByDesc('last_order_at')->orderByDesc('customers.id'),
            default => $query->orderByDesc('customers.created_at')->orderByDesc('customers.id'),
        };

        return $query;
    }

    /** One customer with the same figures as the list. */
    public function find(Customer $customer): Customer
    {
        return $this->query([])->whereKey($customer->getKey())->firstOrFail();
    }
}
