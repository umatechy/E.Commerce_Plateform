<?php

declare(strict_types=1);

namespace App\Domain\Returns\Http\Controllers;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Exceptions\UsageLimitExceededException;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\Http\Resources\ReturnResource;
use App\Domain\Returns\Models\ReturnMethod;
use App\Domain\Returns\Models\ReturnReason;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnResolution;
use App\Domain\Returns\Models\ReturnStatus;
use App\Domain\Returns\Policies\ReturnPolicy;
use App\Domain\Returns\Services\ReturnEligibility;
use App\Domain\Returns\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module 09 §45–54, §65 (Phase B33, gap G8): the staff return API.
 *
 * Every route runs under the store's tenant context: a return or an
 * order of another store resolves to 404 before this controller runs.
 * Each action checks ReturnPolicy (view / manage / approve / refund are
 * separate permissions); the rules and every change are ReturnService's.
 * Amounts are never taken from the request, except the two staff
 * choices the service bounds (shipping refund, restocking fee).
 */
final class ReturnController
{
    private const WITH = ['items.orderItem', 'order.customer', 'replacementOrder', 'warehouse'];

    public function __construct(private readonly ReturnService $returns, private readonly ReturnEligibility $eligibility) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize($request, 'view');
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ReturnStatus::class)],
            'open' => ['nullable', 'boolean'],
            'order' => ['nullable', 'string', 'size:26'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = ReturnRequest::query()->with(['order.customer', 'items'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('open'), fn ($q) => $q->whereNotIn('status', [ReturnStatus::Rejected->value, ReturnStatus::Completed->value, ReturnStatus::Cancelled->value]))
            ->when($filters['order'] ?? null, fn ($q, string $order) => $q->whereHas('order', fn ($o) => $o->where('public_id', $order)))
            ->when($filters['search'] ?? null, fn ($q, string $search) => $q->where(fn ($w) => $w
                ->where('return_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', '%'.addcslashes($search, '%_\\').'%'))))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json(['data' => $page->through(fn (ReturnRequest $return) => (new ReturnResource($return))->forStaff()->resolve($request))]);
    }

    public function show(Request $request, ReturnRequest $return): JsonResponse
    {
        $this->authorize($request, 'view');

        return $this->one($request, $return);
    }

    /** What of this order can be returned now, and the store's return rules. */
    public function returnable(Request $request, Order $order): JsonResponse
    {
        $this->authorize($request, 'view');

        return response()->json(['data' => [
            'blocked' => $this->eligibility->orderBlock($order),
            'window_days' => $this->eligibility->windowDays(),
            'customer_requests_enabled' => $this->eligibility->customersMayRequest(),
            'lines' => array_values($this->eligibility->lines($order)),
        ]]);
    }

    /** Staff record a return for the customer (phone, counter, a guest's order). */
    public function store(Request $request, Order $order): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate(self::requestRules());

        return $this->guarded(fn () => $this->one($request, $this->returns->request($order, $data['items'], $data, $request->user(), 'staff:'.$data['idempotency_key']), 201));
    }

    public function review(Request $request, ReturnRequest $return): JsonResponse
    {
        $this->authorize($request, 'approve');

        return $this->guarded(fn () => $this->one($request, $this->returns->startReview($return, $request->user())));
    }

    public function approve(Request $request, ReturnRequest $return): JsonResponse
    {
        $this->authorize($request, 'approve');
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'return_method' => ['nullable', Rule::enum(ReturnMethod::class)],
            'return_shipping_paid_by' => ['nullable', 'in:customer,store'],
        ]);

        return $this->guarded(fn () => $this->one($request, $this->returns->approve($return, $data, $request->user())));
    }

    public function reject(Request $request, ReturnRequest $return): JsonResponse
    {
        $this->authorize($request, 'approve');
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        return $this->guarded(fn () => $this->one($request, $this->returns->reject($return, $data['note'], $request->user())));
    }

    public function cancel(Request $request, ReturnRequest $return): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->guarded(fn () => $this->one($request, $this->returns->cancel($return, $request->user(), $data['note'] ?? null)));
    }

    public function inTransit(Request $request, ReturnRequest $return): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate(['carrier' => ['nullable', 'string', 'max:64'], 'tracking_number' => ['nullable', 'string', 'max:128']]);

        return $this->guarded(fn () => $this->one($request, $this->returns->markInTransit($return, $data, $request->user())));
    }

    public function receive(Request $request, ReturnRequest $return): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer']]);
        // Under the tenant scope: another store's warehouse is not found.
        $warehouse = isset($data['warehouse_id'])
            ? (Warehouse::query()->find($data['warehouse_id']) ?? throw \Illuminate\Validation\ValidationException::withMessages(['warehouse_id' => 'Choose one of your warehouses.']))
            : null;

        return $this->guarded(fn () => $this->one($request, $this->returns->receive($return, $warehouse, $request->user())));
    }

    public function inspect(Request $request, ReturnRequest $return): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.resalable' => ['required', 'integer', 'min:0', 'max:1000000'],
            'items.*.damaged' => ['required', 'integer', 'min:0', 'max:1000000'],
            'items.*.rejected' => ['required', 'integer', 'min:0', 'max:1000000'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->guarded(fn () => $this->one($request, $this->returns->inspect($return, $data['items'], $request->user(), $data['note'] ?? null)));
    }

    public function approveRefund(Request $request, ReturnRequest $return): JsonResponse
    {
        $this->authorize($request, 'approve');
        $data = $request->validate([
            'shipping_refund_minor' => ['nullable', 'integer', 'min:0'],
            'restocking_fee_minor' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->guarded(fn () => $this->one($request, $this->returns->approveRefund($return, (int) ($data['shipping_refund_minor'] ?? 0), (int) ($data['restocking_fee_minor'] ?? 0), $request->user())));
    }

    public function refund(Request $request, ReturnRequest $return): JsonResponse
    {
        $this->authorize($request, 'refund');

        return $this->guarded(fn () => $this->one($request, $this->returns->refund($return, $request->user())));
    }

    /** §53–54: the replacement or exchange order. */
    public function replacement(Request $request, ReturnRequest $return): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate([
            'resolution' => ['required', 'in:replacement,exchange'],
            'items' => ['required_if:resolution,exchange', 'nullable', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['nullable', 'integer', 'required_without:items.*.product_variant_id'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'payment_method' => ['nullable', 'in:cod,bank_transfer'],
        ]);

        return $this->guarded(function () use ($request, $return, $data) {
            try {
                $result = $this->returns->createReplacement(
                    $return, ReturnResolution::from($data['resolution']),
                    $data['resolution'] === 'exchange' ? $data['items'] : null,
                    isset($data['payment_method']) ? PaymentMethod::from($data['payment_method']) : null,
                    $request->user(),
                );
            } catch (InsufficientStockException) {
                return response()->json(['message' => 'There is not enough stock for the new order. Refund the return, or choose other products.', 'code' => 'insufficient_stock'], 422);
            } catch (UsageLimitExceededException $e) {
                return response()->json(['message' => "Your package's monthly order limit ({$e->limit}) is reached, so the new order cannot be created now. You can refund the return instead.", 'code' => 'usage_limit_exceeded'], 403);
            }

            return $this->one($request, $result);
        });
    }

    /** @return array<string, list<mixed>> */
    public static function requestRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'resolution' => ['required', Rule::enum(ReturnResolution::class)],
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'return_method' => ['nullable', Rule::enum(ReturnMethod::class)],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }

    private function one(Request $request, ReturnRequest $return, int $status = 200): JsonResponse
    {
        return response()->json(['data' => (new ReturnResource($return->load(self::WITH)))->forStaff()->resolve($request)], $status);
    }

    private function guarded(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ReturnActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }

    private function authorize(Request $request, string $ability): void
    {
        abort_unless(app(ReturnPolicy::class)->{$ability}($request->user()), 403);
    }
}
