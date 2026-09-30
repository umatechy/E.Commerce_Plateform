<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sanctum token storage (ADR-002). Sanctum 4 no longer auto-loads its own
// migration — it must be published into the application — and this one
// never was, so every customer/staff token issuance failed with "table
// personal_access_tokens doesn't exist" on the first real run. Platform-
// level table: tokenable_type/tokenable_id already identify the owning
// User or Customer, whose own store scoping applies.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
