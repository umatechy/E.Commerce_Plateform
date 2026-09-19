<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 08 §8 "Inventory Record" / §17 "Warehouse Stock": quantity =
// f(Store, Warehouse, Product/Variant). Quantities are plain
// UNSIGNED INT per ADR-003/Module 08 §10 (this platform does not yet
// need fractional stock units — documented simplification; revisit if
// a future module needs weight/volume-based quantities).
//
// KNOWN LIMITATION (documented, not hidden — see
// docs/security/b4-security-review.md "Database Constraints"): MySQL
// treats NULL as distinct from NULL in unique-index evaluation, so a
// composite unique constraint including the nullable product_variant_id
// column cannot, by itself, prevent two duplicate rows for the same
// SIMPLE product (variant_id always NULL) in the same warehouse. The
// unique constraint below still fully protects VARIANT-level inventory
// (variant_id is never null there) and is kept as a real, useful
// defense-in-depth layer; simple-product duplicate prevention is
// additionally enforced at the application layer in InventoryService.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_inventories_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('incoming')->default(0);
            $table->unsignedInteger('reorder_point')->nullable();
            $table->unsignedInteger('reorder_quantity')->nullable();
            $table->timestamps();

            $table->unique(
                ['store_id', 'warehouse_id', 'product_id', 'product_variant_id'],
                'uniq_inventories_store_id_warehouse_id_product_id_product_variant_id'
            );
            $table->index(['store_id', 'product_id'], 'idx_inventories_store_id_product_id');
            $table->index(['store_id', 'product_variant_id'], 'idx_inventories_store_id_product_variant_id');
        });

        // NOTE (revised after B4 design review — see
        // docs/architecture/b4-inventory.md "Concurrency Strategy" and
        // docs/development/b4-inspection-findings.md): a strict
        // `reserved <= on_hand` CHECK constraint was considered here but
        // deliberately NOT added. Module 08 §24 explicitly allows a
        // store to enable overselling, and §20's reservation mechanism
        // is the pathway a future Orders/Checkout module will use to
        // sell — so a store with allow_overselling=true must be able to
        // reserve MORE than on_hand (this is intentional negative-
        // available-stock behavior, not a bug). A database CHECK
        // constraint cannot conditionally reference another table's
        // per-store setting, so enforcing "reserved <= on_hand unless
        // this store allows overselling" is necessarily an
        // APPLICATION-layer rule (InventoryService::reserve()'s
        // conditional atomic UPDATE), not a database-layer one. This is
        // a documented, reviewed trade-off, not an oversight.
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
