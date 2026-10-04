<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B37 — Module 17 §6–7 "Brand identity / Logo & media rules": images
 * a store uploads for its logo, favicon, banners and social sharing image,
 * instead of only pasting an address. Each file is re-encoded (JPEG/PNG,
 * metadata removed) and kept under the store's own folder; the theme may
 * only reference media of its own store (no cross-tenant references, §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_media', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_store_media_public_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('purpose', 16); // logo | favicon | banner | social
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('mime', 32);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['store_id', 'path'], 'uniq_store_media_store_path');
            $table->index(['store_id', 'purpose'], 'idx_store_media_store_purpose');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_media');
    }
};
