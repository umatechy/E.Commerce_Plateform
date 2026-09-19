<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Minimal foundation Module 09 §4/§9 needs (Order.customer_id); full
// Customer Management (addresses, storefront auth, order history UI)
// belongs to Module 10 — see docs/development/b5-inspection-findings.md.
// user_id links to a registered platform User (Module 10's future
// customer-facing auth); nullable because a guest order has no User at
// all (Module 09 §8).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_customers_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'email'], 'idx_customers_store_id_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
