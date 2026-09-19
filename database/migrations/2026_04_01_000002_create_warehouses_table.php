<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 08 §11-14.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_warehouses_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->text('address')->nullable();
            $table->string('contact')->nullable();
            $table->string('status', 16)->default('active');
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('fulfillment_priority')->default(100);
            $table->timestamps();

            $table->unique(['store_id', 'code'], 'uniq_warehouses_store_id_code');
            $table->index(['store_id', 'status'], 'idx_warehouses_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
