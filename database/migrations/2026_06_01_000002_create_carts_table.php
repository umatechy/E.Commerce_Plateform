<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 11 §5-9 "Cart Ownership / Guest Cart / Cart Lifecycle". A cart
// is owned by EITHER a Customer (registered) OR a guest_token (high
// entropy, §63) — never both, never neither, while ACTIVE.
// `public_id` is the externally-addressable identifier (ADR-003
// convention) — never the internal `id`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_carts_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->string('guest_token', 64)->nullable(); // high-entropy (Module 11 §63), never sequential
            $table->string('status', 20)->default('active'); // CartStatus
            $table->char('currency', 3)->nullable();
            $table->json('shipping_address_snapshot')->nullable(); // optional, for a future checkout review step (Module 13 not yet built)
            $table->json('billing_address_snapshot')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();

            // A customer has at most one row with status='active' in
            // practice (enforced at the application layer in
            // CartService::activeCartFor() via firstOrCreate, not a DB
            // constraint — a unique index on (store_id, customer_id)
            // would incorrectly also block a customer's historical
            // CONVERTED/ABANDONED cart rows from ever coexisting with a
            // new active one).
            $table->index(['store_id', 'customer_id', 'status'], 'idx_carts_store_id_customer_id_status');
            $table->unique(['store_id', 'guest_token'], 'uniq_carts_store_id_guest_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
