<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tenant-membership pivot: the ONLY table that links a User to a Store.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('status', 32)->default('active'); // active|suspended|revoked
            $table->timestamps();

            $table->unique(['store_id', 'user_id'], 'uniq_store_user_store_id_user_id');
            $table->index(['store_id', 'status'], 'idx_store_user_store_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_user');
    }
};
