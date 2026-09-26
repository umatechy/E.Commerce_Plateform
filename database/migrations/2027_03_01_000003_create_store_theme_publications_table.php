<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 17 §20 "Theme Rollback" — append-only publish-history ledger
// (same 2-in-1 collapse pattern as every append-only ledger since
// Phase B7's PaymentTransaction). Rollback = re-publishing an earlier
// snapshot's `config` — no separate version-numbering system needed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_theme_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_theme_id')->constrained('store_themes')->cascadeOnDelete();
            $table->json('config');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'created_at'], 'idx_store_theme_publications_store_id_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_theme_publications');
    }
};
