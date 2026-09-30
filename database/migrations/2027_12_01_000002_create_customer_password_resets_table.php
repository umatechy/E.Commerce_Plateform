<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase B25 — storefront "forgot password". Not Laravel's
// password_reset_tokens: that table is keyed by email alone, but the same
// email can be a separate customer in every store. Only a SHA-256 of the
// token is stored; tokens are single-use and short-lived.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique('uniq_customer_password_resets_token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'created_at'], 'idx_customer_password_resets_customer_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_resets');
    }
};
