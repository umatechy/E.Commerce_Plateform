<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 09 §5/§22 "Order Number Generation" — one atomically-incremented
// counter row per store. See App\Domain\Orders\Services\OrderNumberGenerator
// for the concurrency-safe generation strategy (same atomic-SQL
// philosophy as B2's UsageTrackingService and B4's InventoryService —
// no read-then-write in PHP for the number itself).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->foreignId('store_id')->primary()->constrained('stores')->cascadeOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
    }
};
