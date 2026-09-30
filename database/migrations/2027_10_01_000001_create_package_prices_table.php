<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 29 (Phase B23) — what a Package costs per billing interval and
// currency. Platform-level catalog like `packages` itself: never
// tenant-scoped. A price change applies from the next invoice issued;
// issued invoices keep their own amounts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_prices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_package_prices_public_id');
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->string('billing_interval', 16); // BillingInterval
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['package_id', 'billing_interval', 'currency'], 'uniq_package_prices_package_interval_currency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_prices');
    }
};
