<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 16 §11-13 "Redirect Management / Redirect Security". Only
// internal, store-relative paths are supported (destination is always
// resolved WITHIN the same store — see RedirectService's explicit
// rejection of any absolute/external URL, honoring "prevent open
// redirect vulnerabilities" and "cross-tenant destinations").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('source_path');
            $table->string('destination_path');
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true);
            $table->string('reason')->nullable(); // e.g. "slug_changed:product:42"
            $table->timestamps();

            $table->unique(['store_id', 'source_path'], 'uniq_redirects_store_id_source_path');
            $table->index(['store_id', 'is_active'], 'idx_redirects_store_id_is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
