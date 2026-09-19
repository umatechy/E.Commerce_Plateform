<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 07 §5-12. Self-referencing hierarchy; parent_id FK enforces
// same-tenant parenting at the database level in addition to the
// application-level validation in CategoryController (Module 07 §8:
// "must belong to the same tenant as its parent").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_categories_public_id');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->references('id')->on('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('visibility', 16)->default('hidden');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'slug'], 'uniq_categories_store_id_slug');
            $table->index(['store_id', 'parent_id'], 'idx_categories_store_id_parent_id');
            $table->index(['store_id', 'status'], 'idx_categories_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
