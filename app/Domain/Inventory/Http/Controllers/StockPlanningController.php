<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockReceipt;
use App\Domain\Inventory\Models\StockReceiptLine;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Policies\StockPlanningPolicy;
use App\Domain\Inventory\Services\ReorderPlanner;
use App\Domain\Inventory\Services\StockDocumentService;
use App\Domain\Packages\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase P4a — Products → Stock planning (Business and Premium,
 * `inventory.advanced`; Module 08 §71): what to order again, stock received
 * from suppliers, transfers between locations. Everything is the active
 * store's: queries and route bindings are tenant-scoped, and a location or
 * product of another store is "not found".
 */
final class StockPlanningController
{
    public const FEATURE = 'inventory.advanced';

    public function __construct(private readonly StockDocumentService $documents) {}

    public function reorder(Request $request, ReorderPlanner $planner): JsonResponse
    {
        if ($refused = $this->refused($request, 'view')) {
            return $refused;
        }
        $data = $request->validate(['sales_days' => ['nullable', 'integer', 'min:7', 'max:365'], 'cover_days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $salesDays = (int) ($data['sales_days'] ?? 30);
        $coverDays = (int) ($data['cover_days'] ?? 30);

        return response()->json(['data' => $planner->plan($salesDays, $coverDays), 'meta' => ['sales_days' => $salesDays, 'cover_days' => $coverDays]]);
    }

    public function receipts(Request $request): JsonResponse
    {
        if ($refused = $this->refused($request, 'view')) {
            return $refused;
        }

        return response()->json(['data' => StockReceipt::query()->with(['warehouse:id,name', 'receiver:id,name', 'lines.inventory.product:id,name'])->orderByDesc('id')->limit(50)->get()
            ->map(fn (StockReceipt $r) => [
                'id' => $r->public_id, 'number' => $r->number, 'warehouse' => $r->warehouse?->name, 'supplier' => $r->supplier, 'reference' => $r->reference, 'note' => $r->note,
                'by' => $r->receiver?->name, 'received_at' => $r->created_at?->toIso8601String(),
                'lines' => $r->lines->map(fn (StockReceiptLine $l): array => ['product' => $l->inventory?->product?->name, 'quantity' => $l->quantity])->values(),
            ])->values()]);
    }

    public function receive(Request $request): JsonResponse
    {
        if ($refused = $this->refused($request, 'manage')) {
            return $refused;
        }
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'supplier' => ['required', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:500'],
            ...$this->lineRules(),
        ]);
        $receipt = $this->documents->receive($this->user($request), $this->warehouse($data['warehouse_id'], 'warehouse_id'), $data['supplier'], $data['reference'] ?? null, $data['note'] ?? null, $data['lines'], $data['idempotency_key']);

        return response()->json(['data' => ['id' => $receipt->public_id, 'number' => $receipt->number], 'message' => "Received as {$receipt->number}. The stock is on hand."], 201);
    }

    public function transfers(Request $request): JsonResponse
    {
        if ($refused = $this->refused($request, 'view')) {
            return $refused;
        }

        return response()->json(['data' => StockTransfer::query()->with(['from:id,name', 'to:id,name', 'creator:id,name', 'lines.fromInventory.product:id,name'])
            ->orderByRaw("FIELD(status, 'in_transit') DESC")->orderByDesc('id')->limit(50)->get()
            ->map(fn (StockTransfer $t) => [
                'id' => $t->public_id, 'number' => $t->number, 'status' => $t->status, 'from' => $t->from?->name, 'to' => $t->to?->name, 'note' => $t->note,
                'by' => $t->creator?->name, 'sent_at' => $t->created_at?->toIso8601String(), 'closed_at' => $t->closed_at?->toIso8601String(),
                'lines' => $t->lines->map(fn (StockTransferLine $l): array => ['product' => $l->fromInventory?->product?->name, 'quantity' => $l->quantity])->values(),
            ])->values()]);
    }

    public function send(Request $request): JsonResponse
    {
        if ($refused = $this->refused($request, 'manage')) {
            return $refused;
        }
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            ...$this->lineRules(),
        ]);
        $transfer = $this->documents->send($this->user($request), $this->warehouse($data['from_warehouse_id'], 'from_warehouse_id'), $this->warehouse($data['to_warehouse_id'], 'to_warehouse_id'), $data['note'] ?? null, $data['lines'], $data['idempotency_key']);

        return response()->json(['data' => ['id' => $transfer->public_id, 'number' => $transfer->number], 'message' => "{$transfer->number} is on its way."], 201);
    }

    public function arrive(Request $request, StockTransfer $transfer): JsonResponse
    {
        if ($refused = $this->refused($request, 'manage')) {
            return $refused;
        }
        $this->documents->arrive($this->user($request), $transfer);

        return response()->json(['message' => "{$transfer->number} received. The stock is on hand."]);
    }

    public function cancel(Request $request, StockTransfer $transfer): JsonResponse
    {
        if ($refused = $this->refused($request, 'manage')) {
            return $refused;
        }
        $this->documents->cancel($this->user($request), $transfer);

        return response()->json(['message' => "{$transfer->number} cancelled. The stock is back where it came from."]);
    }

    /** @return array<string, list<string>> */
    private function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }

    private function warehouse(int $id, string $field): Warehouse
    {
        return Warehouse::query()->find($id) ?? throw ValidationException::withMessages([$field => 'This location is not in your store.']);
    }

    /** The permission first (403), then the package (403 feature_not_entitled). */
    private function refused(Request $request, string $ability): ?JsonResponse
    {
        abort_unless(app(StockPlanningPolicy::class)->{$ability}($request->user()), 403);
        if (! app(EntitlementService::class)->hasFeature(self::FEATURE)) {
            return response()->json(['message' => 'Stock planning, receiving and transfers are part of the Business and Premium packages.', 'code' => 'feature_not_entitled'], 403);
        }

        return null;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
