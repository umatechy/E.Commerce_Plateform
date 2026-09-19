<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-003: internal BIGINT PK, ULID public_id, tenant identity table
// itself (no store_id column — a Store does not belong to a Store).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_stores_public_id');
            $table->string('name');
            $table->string('slug')->unique('uniq_stores_slug');
            $table->string('status', 32)->default('pending_setup');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
