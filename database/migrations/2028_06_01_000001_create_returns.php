<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 09 §45–54, Module 13 §70 (Phase B33, gap G8): returns,
 * inspection, refunds and replacement orders.
 *
 * A return is its own record with its own states (§46); it never edits
 * the order it belongs to (Module 09 Final Rule: orders are history).
 * The order only carries a summary (`return_status`), written by
 * OrderService like its payment and fulfillment summaries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_return_requests_public_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('return_number', 40);
            $table->string('status', 24)->default('requested'); // ReturnStatus
            $table->string('resolution', 16); // ReturnResolution: what the customer asks for
            $table->string('reason', 32); // ReturnReason
            $table->text('description')->nullable();
            $table->string('requested_by', 16); // customer | staff
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Decision (§46 approved / rejected).
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable(); // shown to the customer
            $table->timestamp('decided_at')->nullable();

            // Module 13 §70 "Return shipping": how the goods come back and who pays.
            $table->string('return_method', 24)->nullable(); // ReturnMethod
            $table->string('return_shipping_paid_by', 16)->nullable(); // customer | store
            $table->string('return_carrier', 64)->nullable();
            $table->string('return_tracking_number', 128)->nullable();
            $table->timestamp('shipped_back_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete(); // where the goods were received

            // Money (§50), in the order's currency; set when the refund is approved.
            $table->char('currency', 3);
            $table->bigInteger('items_refund_minor')->default(0);
            $table->bigInteger('shipping_refund_minor')->default(0);
            $table->bigInteger('restocking_fee_minor')->default(0);
            $table->bigInteger('refund_total_minor')->default(0);
            $table->bigInteger('refunded_minor')->default(0); // what the payment actually returned
            $table->foreignId('refund_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();

            // §53–54: the replacement or exchange order made from this return.
            $table->foreignId('replacement_order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('idempotency_key');
            $table->timestamps();

            $table->unique(['store_id', 'return_number'], 'uniq_return_requests_store_number');
            $table->unique(['store_id', 'idempotency_key'], 'uniq_return_requests_store_idempotency');
            $table->index(['store_id', 'status'], 'idx_return_requests_store_status');
            $table->index(['store_id', 'order_id'], 'idx_return_requests_store_order');
            $table->index(['store_id', 'customer_id'], 'idx_return_requests_store_customer');
        });

        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('return_request_id')->constrained('return_requests')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity'); // asked to return (§47)
            // §48 inspection result; the three add up to what was received.
            $table->unsignedInteger('resalable_quantity')->default(0); // back into stock
            $table->unsignedInteger('damaged_quantity')->default(0); // received, written off, never sellable
            $table->unsignedInteger('rejected_quantity')->default(0); // not accepted; goes back to the customer
            $table->string('inspection_note', 500)->nullable();
            $table->bigInteger('refund_minor')->default(0); // this line's part of items_refund_minor
            $table->timestamps();

            $table->unique(['return_request_id', 'order_item_id'], 'uniq_return_items_return_order_item');
            $table->index(['store_id', 'order_item_id'], 'idx_return_items_store_order_item');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('return_status', 24)->default('none')->after('fulfillment_status'); // OrderReturnStatus
            // §54: a replacement order keeps a link to what it replaces.
            $table->foreignId('replacement_for_order_id')->nullable()->after('source')->constrained('orders')->nullOnDelete();
        });

        // Module 09 §65: Manage Returns, Approve Returns (Process Refunds = payments.refund, since B7).
        $keys = [
            'returns.view' => 'View return requests (Module 09 — Phase B33)',
            'returns.manage' => 'Create returns for a customer, receive and inspect returned goods, create replacement orders (Phase B33)',
            'returns.approve' => 'Approve or reject return requests (Phase B33)',
        ];
        foreach ($keys as $key => $description) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert(['key' => $key, 'group' => 'returns', 'description' => $description]);
            }
        }

        // The system roles of existing stores, as SystemRoles gives them to new stores.
        $grants = [
            'administrator' => ['returns.view', 'returns.manage', 'returns.approve'],
            'manager' => ['returns.view', 'returns.manage', 'returns.approve'],
            'order-manager' => ['returns.view', 'returns.manage'],
            'staff' => ['returns.view'],
        ];
        $ids = DB::table('permissions')->whereIn('key', array_keys($keys))->pluck('id', 'key');
        foreach ($grants as $slug => $granted) {
            foreach (DB::table('roles')->where('slug', $slug)->where('is_system', true)->pluck('id') as $roleId) {
                foreach ($granted as $key) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$key]]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replacement_for_order_id');
            $table->dropColumn('return_status');
        });
        Schema::dropIfExists('return_request_items');
        Schema::dropIfExists('return_requests');
        DB::table('permissions')->whereIn('key', ['returns.view', 'returns.manage', 'returns.approve'])->delete();
    }
};
