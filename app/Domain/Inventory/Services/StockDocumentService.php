<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\StockMovementType;
use App\Domain\Inventory\Models\StockReceipt;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase P4a (Module 08 §71, Business: purchase receiving and transfers).
 * Documents that move stock; every unit moves through InventoryService as a
 * stock movement pointing at its document, in one transaction with it. A
 * repeated request (same idempotency key) returns the document already made.
 */
final class StockDocumentService
{
    public function __construct(private readonly InventoryService $inventory, private readonly AuditLogger $audit) {}

    /**
     * @param list<array{product_id: int, product_variant_id?: ?int, quantity: int}> $lines
     */
    public function receive(User $by, Warehouse $warehouse, string $supplier, ?string $reference, ?string $note, array $lines, string $idempotencyKey): StockReceipt
    {
        if ($existing = StockReceipt::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($by, $warehouse, $supplier, $reference, $note, $lines, $idempotencyKey) {
            $receipt = StockReceipt::query()->create([
                'number' => $this->nextNumber('stock_receipts', 'REC'), 'warehouse_id' => $warehouse->id, 'supplier' => $supplier,
                'reference' => $reference, 'note' => $note, 'received_by' => $by->id, 'idempotency_key' => $idempotencyKey,
            ]);
            foreach ($this->merge($lines) as $i => $line) {
                $record = $this->recordAt($warehouse, $line['product_id'], $line['product_variant_id'] ?? null, "lines.{$i}");
                $receipt->lines()->create(['inventory_id' => $record->id, 'quantity' => $line['quantity']]);
                $this->inventory->stockIn($record, $line['quantity'], StockMovementType::PurchaseIn, 'stock_receipt', $receipt->id,
                    "Received {$receipt->number} from {$supplier}", $by->id, "receipt:{$receipt->id}:{$record->id}");
            }
            $this->audit->record('inventory.stock_received', ['number' => $receipt->number, 'units' => array_sum(array_column($lines, 'quantity'))], $receipt, actor: $by);

            return $receipt;
        });
    }

    /**
     * Sends stock: out of the source now, shown as incoming at the destination.
     *
     * @param list<array{product_id: int, product_variant_id?: ?int, quantity: int}> $lines
     *
     * @throws ValidationException
     */
    public function send(User $by, Warehouse $from, Warehouse $to, ?string $note, array $lines, string $idempotencyKey): StockTransfer
    {
        if ($existing = StockTransfer::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'Choose another location to send to.']);
        }

        return DB::transaction(function () use ($by, $from, $to, $note, $lines, $idempotencyKey) {
            $transfer = StockTransfer::query()->create([
                'number' => $this->nextNumber('stock_transfers', 'TR'), 'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
                'status' => 'in_transit', 'note' => $note, 'created_by' => $by->id, 'idempotency_key' => $idempotencyKey,
            ]);
            foreach ($this->merge($lines) as $i => $line) {
                $source = Inventory::query()->where('warehouse_id', $from->id)->where('product_id', $line['product_id'])
                    ->where('product_variant_id', $line['product_variant_id'] ?? null)->first()
                    ?? throw ValidationException::withMessages(["lines.{$i}.product_id" => "{$from->name} has none of this product."]);
                $target = $this->recordAt($to, $line['product_id'], $line['product_variant_id'] ?? null, "lines.{$i}");
                try {
                    $this->inventory->stockOut($source, $line['quantity'], StockMovementType::TransferOut, 'stock_transfer', $transfer->id,
                        "Sent to {$to->name} ({$transfer->number})", $by->id, "transfer:{$transfer->id}:out:{$source->id}");
                } catch (InsufficientStockException) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => 'Only '.max(0, $source->fresh()->on_hand - $source->fresh()->reserved)." can be sent from {$from->name}: the rest is on hand for orders or not there."]);
                }
                $this->inventory->changeIncoming($target, $line['quantity']);
                $transfer->lines()->create(['from_inventory_id' => $source->id, 'to_inventory_id' => $target->id, 'quantity' => $line['quantity']]);
            }
            $this->audit->record('inventory.transfer_sent', ['number' => $transfer->number, 'from' => $from->name, 'to' => $to->name], $transfer, actor: $by);

            return $transfer;
        });
    }

    /** The stock arrived: into the destination. */
    public function arrive(User $by, StockTransfer $transfer): StockTransfer
    {
        return $this->close($by, $transfer, 'received', function (StockTransfer $locked) use ($by) {
            foreach ($locked->lines as $line) {
                $this->inventory->stockIn($line->toInventory, $line->quantity, StockMovementType::TransferIn, 'stock_transfer', $locked->id,
                    "Received from {$locked->from->name} ({$locked->number})", $by->id, "transfer:{$locked->id}:in:{$line->to_inventory_id}", fromIncoming: true);
            }
        });
    }

    /** Called off while on the way: the stock goes back to the source. */
    public function cancel(User $by, StockTransfer $transfer): StockTransfer
    {
        return $this->close($by, $transfer, 'cancelled', function (StockTransfer $locked) use ($by) {
            foreach ($locked->lines as $line) {
                $this->inventory->changeIncoming($line->toInventory, -$line->quantity);
                $this->inventory->stockIn($line->fromInventory, $line->quantity, StockMovementType::TransferIn, 'stock_transfer', $locked->id,
                    "Transfer {$locked->number} cancelled", $by->id, "transfer:{$locked->id}:back:{$line->from_inventory_id}");
            }
        });
    }

    /** @param \Closure(StockTransfer): void $apply */
    private function close(User $by, StockTransfer $transfer, string $status, \Closure $apply): StockTransfer
    {
        return DB::transaction(function () use ($by, $transfer, $status, $apply) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->status === $status) {
                return $locked; // done already: a repeat changes nothing
            }
            if ($locked->status !== 'in_transit') {
                throw ValidationException::withMessages(['status' => "This transfer is already {$locked->status}."]);
            }
            $apply($locked->load('lines.toInventory', 'lines.fromInventory', 'from'));
            $locked->update(['status' => $status, 'closed_by' => $by->id, 'closed_at' => now()]);
            $this->audit->record("inventory.transfer_{$status}", ['number' => $locked->number], $locked, actor: $by);

            return $locked;
        });
    }

    /** The product's stock record at a location, made empty when it has none yet. */
    private function recordAt(Warehouse $warehouse, int $productId, ?int $variantId, string $field): Inventory
    {
        $product = Product::query()->find($productId) ?? throw ValidationException::withMessages(["{$field}.product_id" => 'This product is not in your store.']);
        if ($variantId !== null && ! ProductVariant::query()->where('id', $variantId)->where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages(["{$field}.product_variant_id" => 'This variant is not one of the product’s.']);
        }

        return Inventory::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'product_variant_id' => $variantId],
            ['on_hand' => 0, 'reserved' => 0, 'incoming' => 0],
        );
    }

    /**
     * The same product twice in one document becomes one line.
     *
     * @param list<array{product_id: int, product_variant_id?: ?int, quantity: int}> $lines
     *
     * @return list<array{product_id: int, product_variant_id: ?int, quantity: int}>
     */
    private function merge(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $key = $line['product_id'].':'.($line['product_variant_id'] ?? '');
            $out[$key] ??= ['product_id' => (int) $line['product_id'], 'product_variant_id' => isset($line['product_variant_id']) ? (int) $line['product_variant_id'] : null, 'quantity' => 0];
            $out[$key]['quantity'] += (int) $line['quantity'];
        }

        return array_values($out);
    }

    /** REC-0001, TR-0001…: per store, numbered under a lock on the store row. */
    private function nextNumber(string $table, string $prefix): string
    {
        $storeId = app(TenantContext::class)->storeId();
        Store::query()->withoutGlobalScopes()->whereKey($storeId)->lockForUpdate()->first();

        return sprintf('%s-%04d', $prefix, DB::table($table)->where('store_id', $storeId)->count() + 1);
    }
}
