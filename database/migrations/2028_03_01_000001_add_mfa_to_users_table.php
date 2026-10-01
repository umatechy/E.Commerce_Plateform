<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase B29 (gap G2) — multi-factor authentication for staff accounts
// (Module 32 §8, Module 02 §13; SRS AUTH-006, AUTH-007).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The TOTP secret, encrypted at rest (model cast). Present but
            // unconfirmed while enrollment is in progress.
            $table->text('mfa_secret')->nullable()->after('password');
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret');
            // The 30-second step of the last accepted code: a code cannot be used twice.
            $table->unsignedBigInteger('mfa_last_used_step')->nullable()->after('mfa_confirmed_at');
        });

        Schema::create('user_mfa_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // HMAC-SHA256 of the code under the application key. The code itself is shown once and never stored.
            $table->char('code_hash', 64);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'code_hash'], 'uniq_mfa_recovery_user_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mfa_recovery_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_confirmed_at', 'mfa_last_used_step']);
        });
    }
};
