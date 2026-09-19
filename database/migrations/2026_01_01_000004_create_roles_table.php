<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tenant-owned (store-scoped). ADR-003: store_id first in every composite index.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_system')->default(false); // seeded default roles, not deletable
            $table->timestamps();

            $table->unique(['store_id', 'slug'], 'uniq_roles_store_id_slug');
            $table->index(['store_id'], 'idx_roles_store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
