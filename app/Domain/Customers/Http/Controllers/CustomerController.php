<?php

declare(strict_types=1);

namespace App\Domain\Customers\Http\Controllers;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\CustomerAccount\Models\CustomerAddress;
use App\Domain\Customers\Exceptions\CustomerActionRefusedException;
use App\Domain\Customers\Http\Resources\StaffCustomerResource;
use App\Domain\Customers\Models\CustomerGroup;
use App\Domain\Customers\Policies\CustomerPolicy;
use App\Domain\Customers\Services\CustomerDirectory;
use App\Domain\Customers\Services\CustomerExport;
use App\Domain\Customers\Services\CustomerManagement;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 10 §51–58, §96 (Phase B32, gap G7): the staff customer API.
 *
 * Every route runs under the store's tenant context: a customer of
 * another store resolves to 404 before this controller runs. Each
 * action checks CustomerPolicy; changes are made by CustomerManagement,
 * which audits them.
 */
final class CustomerController
{
    public function __construct(
        private readonly CustomerDirectory $directory,
        private readonly CustomerManagement $customers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize($request, 'view');
        $filters = $request->validate([...CustomerDirectory::rules(), 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $page = $this->directory->query($filters)->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json(['data' => $page->through(fn (Customer $c) => (new StaffCustomerResource($c))->resolve($request))]);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize($request, 'view');
        $row = $this->directory->find($customer);

        $firstOrder = Order::query()->where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->min('created_at');
        $count = (int) $row->getAttribute('orders_count');

        return response()->json([
            'data' => [
                ...(new StaffCustomerResource($row))->resolve($request),
                'first_order_at' => $firstOrder ? Carbon::parse($firstOrder, 'UTC')->toIso8601String() : null,
                'average_order_value_minor' => $count > 0 ? intdiv((int) $row->getAttribute('total_spent_minor'), $count) : null,
                'addresses' => CustomerAddress::query()->where('customer_id', $customer->id)->orderByDesc('is_default')->orderBy('id')->get()
                    ->map(fn (CustomerAddress $a) => [
                        'label' => $a->label, 'name' => $a->name, 'phone' => $a->phone, 'line1' => $a->line1, 'line2' => $a->line2,
                        'city' => $a->city, 'province' => $a->province, 'postal_code' => $a->postal_code, 'country' => $a->country,
                        'is_default' => (bool) $a->is_default,
                    ])->values(),
                'possible_duplicates' => $this->duplicates($customer),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s.]+$/'],
            'group' => ['nullable', 'string', 'size:26'],
            'tags' => ['nullable', 'array', 'max:'.CustomerManagement::MAX_TAGS_PER_CUSTOMER],
            'tags.*' => ['string', 'max:60'],
        ]);

        $customer = $this->customers->create($data, $request->user());
        if (! empty($data['group'])) {
            $this->customers->setGroup($customer, $this->group($data['group']), $request->user());
        }
        if (! empty($data['tags'])) {
            $this->customers->setTags($customer, $data['tags'], $request->user());
        }

        return response()->json(['data' => (new StaffCustomerResource($this->directory->find($customer)))->resolve($request)], 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s.]+$/'],
            'group' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        return $this->refusable(function () use ($request, $customer, $data) {
            $this->customers->update($customer, $data, $request->user());
            if (array_key_exists('group', $data)) {
                $this->customers->setGroup($customer, $data['group'] === null ? null : $this->group($data['group']), $request->user());
            }

            return $this->fresh($request, $customer);
        });
    }

    public function tags(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate(['tags' => ['present', 'array', 'max:'.CustomerManagement::MAX_TAGS_PER_CUSTOMER], 'tags.*' => ['string', 'max:60']]);

        return $this->refusable(function () use ($request, $customer, $data) {
            $this->customers->setTags($customer, $data['tags'], $request->user());

            return $this->fresh($request, $customer);
        });
    }

    public function block(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->refusable(function () use ($request, $customer, $data) {
            $this->customers->block($customer, $data['reason'], $request->user());

            return $this->fresh($request, $customer);
        });
    }

    public function archive(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->refusable(function () use ($request, $customer, $data) {
            $this->customers->archive($customer, $data['reason'] ?? null, $request->user());

            return $this->fresh($request, $customer);
        });
    }

    /** Unblock or restore: back to active. */
    public function reactivate(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize($request, 'manage');

        return $this->refusable(function () use ($request, $customer) {
            $this->customers->reactivate($customer, $request->user());

            return $this->fresh($request, $customer);
        });
    }

    /**
     * Module 10 §33: what happened with this customer — the audit trail
     * entries about them and their orders, newest first.
     */
    public function activity(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize($request, 'view');

        $audit = AuditLog::query()
            ->where('store_id', $customer->store_id)
            ->where('subject_type', 'customer')
            ->where('subject_id', $customer->id)
            ->orderByDesc('id')->limit(100)->get()
            ->map(fn (AuditLog $log) => [
                'type' => $log->action,
                'at' => $log->created_at->toIso8601String(),
                'by' => $log->actor_label,
                'by_type' => $log->actor_type->value,
                'details' => self::safeContext($log->action, $log->contextData()),
                'order' => null,
            ]);

        $orders = Order::query()->where('customer_id', $customer->id)->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (Order $order) => [
                'type' => 'order.placed',
                'at' => $order->created_at->toIso8601String(),
                'by' => null,
                'by_type' => null,
                'details' => ['status' => $order->status->value],
                'order' => ['id' => $order->public_id, 'order_number' => $order->order_number],
            ]);

        $events = $audit->concat($orders)->sortByDesc('at')->values()->take(100);

        return response()->json(['data' => $events]);
    }

    public function export(Request $request, CustomerExport $export): StreamedResponse
    {
        $this->authorize($request, 'export');
        $filters = $request->validate(CustomerDirectory::rules());

        return $export->stream($filters, $request->user());
    }

    /**
     * Module 10 §57: possible duplicates, as warnings only. Strong signals
     * (the same email, the same phone); never a merge.
     *
     * @return list<array{id: string, name: string, email: string, reason: string}>
     */
    private function duplicates(Customer $customer): array
    {
        if ($customer->erased_at !== null) {
            return [];
        }

        $digits = preg_replace('/\D+/', '', (string) $customer->phone);

        return Customer::query()
            ->whereKeyNot($customer->id)
            ->whereNull('erased_at')
            ->where(function ($q) use ($customer, $digits) {
                $q->whereRaw('LOWER(email) = ?', [mb_strtolower($customer->email)]);
                if (strlen((string) $digits) >= 7) {
                    $q->orWhere('phone', $customer->phone);
                }
            })
            ->limit(10)->get()
            ->map(fn (Customer $other) => [
                'id' => $other->public_id,
                'name' => $other->name,
                'email' => $other->email,
                'reason' => mb_strtolower($other->email) === mb_strtolower($customer->email) ? 'same_email' : 'same_phone',
            ])->values()->all();
    }

    /**
     * What the timeline may show of an audit entry: the reason of a status
     * change and names of groups and tags. Nothing else (IP, user agent and
     * the like stay in the audit log, which has its own permission).
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function safeContext(string $action, array $context): array
    {
        return array_intersect_key($context, array_flip(['reason', 'from', 'to', 'group', 'tags', 'fields', 'orders', 'opt_in', 'source']));
    }

    private function group(string $publicId): CustomerGroup
    {
        return CustomerGroup::query()->where('public_id', $publicId)->first()
            ?? throw \Illuminate\Validation\ValidationException::withMessages(['group' => 'Choose one of your store\'s customer groups.']);
    }

    private function fresh(Request $request, Customer $customer): JsonResponse
    {
        return response()->json(['data' => (new StaffCustomerResource($this->directory->find($customer)))->resolve($request)]);
    }

    private function refusable(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (CustomerActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }

    private function authorize(Request $request, string $ability): void
    {
        abort_unless(app(CustomerPolicy::class)->{$ability}($request->user()), 403);
    }
}
