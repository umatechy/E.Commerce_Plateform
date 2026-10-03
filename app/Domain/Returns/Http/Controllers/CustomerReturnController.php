<?php

declare(strict_types=1);

namespace App\Domain\Returns\Http\Controllers;

use App\Domain\CustomerAccount\Services\CustomerOrderHistory;
use App\Domain\Orders\Models\Customer;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\Http\Resources\ReturnResource;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnStatus;
use App\Domain\Returns\Services\ReturnEligibility;
use App\Domain\Returns\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 09 §45 (Phase B33): a signed-in customer and the returns of
 * their own orders. The order and the return are always looked up
 * through the customer (CustomerOrderHistory, `customer_id`), never by
 * an id alone, so another customer's order or return is "not found".
 * The customer view of a return has no staff names or inspection notes.
 */
final class CustomerReturnController
{
    public function __construct(
        private readonly ReturnService $returns,
        private readonly ReturnEligibility $eligibility,
        private readonly CustomerOrderHistory $orders,
    ) {}

    /** What of this order can be returned now, the store's rules, and the returns it already has. */
    public function returnable(Request $request, string $orderPublicId): JsonResponse
    {
        $order = $this->orders->find($request->user(), $orderPublicId);
        if ($order === null) {
            return $this->notFound('Order not found.', 'order_not_found');
        }

        $enabled = $this->eligibility->customersMayRequest();
        $lines = collect($this->eligibility->lines($order))->map(fn (array $line) => [
            ...$line,
            // For the customer, "returnable" also means inside the return period.
            'returnable' => $line['window_open'] ? $line['returnable'] : 0,
        ])->values();

        return response()->json(['data' => [
            'enabled' => $enabled,
            'blocked' => $this->eligibility->orderBlock($order),
            'window_days' => $this->eligibility->windowDays(),
            'lines' => $lines,
            'returns' => ReturnRequest::query()->where('order_id', $order->id)->where('customer_id', $request->user()->id)
                ->with(['items.orderItem', 'replacementOrder'])->orderByDesc('id')->get()
                ->map(fn (ReturnRequest $return) => (new ReturnResource($return))->resolve($request))->values(),
        ]]);
    }

    public function store(Request $request, string $orderPublicId): JsonResponse
    {
        $order = $this->orders->find($request->user(), $orderPublicId);
        if ($order === null) {
            return $this->notFound('Order not found.', 'order_not_found');
        }
        $rules = ReturnController::requestRules();
        // A customer chooses what they would like; how the parcel travels is agreed when the store approves.
        unset($rules['return_method']);
        $data = $request->validate($rules);

        /** @var Customer $customer */
        $customer = $request->user();

        try {
            $return = $this->returns->request($order, $data['items'], $data, $customer, "customer:{$customer->id}:".$data['idempotency_key']);
        } catch (ReturnActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => (new ReturnResource($return->load(['items.orderItem', 'replacementOrder'])))->resolve($request)], 201);
    }

    public function show(Request $request, string $returnPublicId): JsonResponse
    {
        $return = $this->own($request, $returnPublicId);

        return $return === null
            ? $this->notFound('Return not found.', 'return_not_found')
            : response()->json(['data' => (new ReturnResource($return))->resolve($request)]);
    }

    /** The customer withdraws the request, until the store has received the goods. */
    public function cancel(Request $request, string $returnPublicId): JsonResponse
    {
        return $this->act($request, $returnPublicId, fn (ReturnRequest $return) => $this->returns->cancel($return, $request->user()));
    }

    /** The customer says the parcel is on its way (Module 13 §70), with the carrier and tracking number if there are any. */
    public function shipped(Request $request, string $returnPublicId): JsonResponse
    {
        $data = $request->validate(['carrier' => ['nullable', 'string', 'max:64'], 'tracking_number' => ['nullable', 'string', 'max:128']]);

        return $this->act($request, $returnPublicId, function (ReturnRequest $return) use ($request, $data) {
            if ($return->status !== ReturnStatus::Approved) {
                throw new ReturnActionRefusedException('The store has to approve the return before you send the items back.', 'not_approved');
            }

            return $this->returns->markInTransit($return, $data, $request->user());
        });
    }

    private function act(Request $request, string $returnPublicId, callable $action): JsonResponse
    {
        $return = $this->own($request, $returnPublicId);
        if ($return === null) {
            return $this->notFound('Return not found.', 'return_not_found');
        }

        try {
            $changed = $action($return);
        } catch (ReturnActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => (new ReturnResource($changed->load(['items.orderItem', 'replacementOrder'])))->resolve($request)]);
    }

    private function own(Request $request, string $publicId): ?ReturnRequest
    {
        return ReturnRequest::query()->where('public_id', $publicId)->where('customer_id', $request->user()->id)
            ->with(['items.orderItem', 'replacementOrder'])->first();
    }

    private function notFound(string $message, string $code): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], 404);
    }
}
