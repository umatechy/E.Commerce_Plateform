<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UI-25: script errors seen in the admin's browsers, so Umar Techy hears of a
 * broken page before a seller reports it. One row per distinct error (the
 * fingerprint of its message, top stack frame and page), counted. Only the
 * error text, the page path (no query string, ids replaced) and the browser
 * are kept — no form values, no customer data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_errors', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 64)->unique();
            $table->string('message', 500);
            $table->string('source', 300)->nullable();
            $table->string('path', 300);
            $table->string('browser', 200)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_errors');
    }
};
