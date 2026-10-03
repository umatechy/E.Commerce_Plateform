<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B34 — what B33 left out of Module 09 §45:
 *
 * - "Images" on a return request (`return_request_photos`). The file is
 *   on a private disk; this row is what the API serves it by.
 * - Returns by guests. A guest has no account, so they prove the order
 *   is theirs through a link sent to the order's email
 *   (`return_guest_links`; only the SHA-256 of the token is stored).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_request_photos', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique('uniq_return_photos_public_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('return_request_id')->constrained('return_requests')->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('mime', 32);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->string('uploaded_by', 16); // customer | guest | staff
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'return_request_id'], 'idx_return_photos_store_return');
        });

        Schema::create('return_guest_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique('uniq_return_guest_links_token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'order_id', 'created_at'], 'idx_return_guest_links_store_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_guest_links');
        Schema::dropIfExists('return_request_photos');
    }
};
