<?php

declare(strict_types=1);

namespace App\Domain\Returns\Http\Controllers;

use App\Domain\CustomerAccount\Services\CustomerOrderHistory;
use App\Domain\Orders\Models\Customer;
use App\Domain\Returns\Models\ReturnRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 09 §45 (Phase B33): a signed-in customer and the returns of
 * their own orders. The order and the return are always looked up
 * through the customer (CustomerOrderHistory, `customer_id`), never by
 * an id alone, so another customer's order or return is "not found".
 */
final class CustomerReturnController
{
    use ServesOwnReturns;

    public function __construct(private readonly CustomerOrderHistory $orders) {}

    /** What of this order can be returned now, the store's rules, and the returns it already has. */
    public function returnable(Request $request, string $orderPublicId): JsonResponse
    {
        $order = $this->orders->find($request->user(), $orderPublicId);

        return $order === null
            ? response()->json(['message' => 'Order not found.', 'code' => 'order_not_found'], 404)
            : $this->returnableResponse($request, $order, ReturnRequest::query()->where('order_id', $order->id)->where('customer_id', $request->user()->id));
    }

    public function store(Request $request, string $orderPublicId): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $order = $this->orders->find($customer, $orderPublicId);

        return $order === null
            ? response()->json(['message' => 'Order not found.', 'code' => 'order_not_found'], 404)
            : $this->createResponse($request, $order, $customer, "customer:{$customer->id}:");
    }

    public function show(Request $request, string $returnPublicId): JsonResponse
    {
        $return = $this->own($request, $returnPublicId);

        return $return === null ? $this->returnNotFound() : $this->ownReturn($request, $return);
    }

    public function cancel(Request $request, string $returnPublicId): JsonResponse
    {
        return $this->cancelResponse($request, $this->own($request, $returnPublicId), $request->user());
    }

    public function shipped(Request $request, string $returnPublicId): JsonResponse
    {
        return $this->shippedResponse($request, $this->own($request, $returnPublicId), $request->user());
    }

    /** Module 09 §45 "Images" (Phase B34). */
    public function addPhoto(Request $request, string $returnPublicId): JsonResponse
    {
        return $this->addPhotoResponse($request, $this->own($request, $returnPublicId), 'customer');
    }

    public function photo(Request $request, string $returnPublicId, string $photo): Response
    {
        return $this->photoResponse($this->own($request, $returnPublicId), $photo);
    }

    private function own(Request $request, string $publicId): ?ReturnRequest
    {
        return ReturnRequest::query()->where('public_id', $publicId)->where('customer_id', $request->user()->id)->first();
    }
}
