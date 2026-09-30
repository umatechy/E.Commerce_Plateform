<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Http\Controllers;

use App\Domain\CustomerAccount\Services\CustomerOrderHistory;
use App\Domain\Orders\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phase B25 — "My orders": the signed-in customer's own orders only. */
final class CustomerOrderController
{
    public function __construct(private readonly CustomerOrderHistory $history) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50'], 'page' => ['nullable', 'integer', 'min:1']]);
        $orders = $this->history->paginate($request->user(), (int) ($validated['per_page'] ?? 10));

        return response()->json(['data' => [
            'orders' => collect($orders->items())->map(fn (Order $order) => $this->history->summary($order))->all(),
            'pagination' => ['page' => $orders->currentPage(), 'per_page' => $orders->perPage(), 'total' => $orders->total(), 'last_page' => $orders->lastPage()],
        ]]);
    }

    public function show(Request $request, string $orderPublicId): JsonResponse
    {
        $order = $this->history->find($request->user(), $orderPublicId);

        return $order === null
            ? response()->json(['message' => 'Order not found.', 'code' => 'order_not_found'], 404)
            : response()->json(['data' => $this->history->detail($order)]);
    }
}
