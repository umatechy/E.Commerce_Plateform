<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase S2 (owner decision 2026-10-31): a store is public only on the
 * customer's own domain. After the TXT record proves the domain is theirs,
 * the platform checks that it points at the shop (A record) and that its
 * certificate works, and only then makes it live.
 *
 * - `dns_status`: pointing | elsewhere | missing (null: not checked yet).
 * - `dns_checked_at`, `ssl_checked_at`: when each was last looked at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('dns_status', 12)->nullable()->after('ssl_status');
            $table->timestamp('dns_checked_at')->nullable()->after('dns_status');
            $table->timestamp('ssl_checked_at')->nullable()->after('dns_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', fn (Blueprint $table) => $table->dropColumn(['dns_status', 'dns_checked_at', 'ssl_checked_at']));
    }
};
