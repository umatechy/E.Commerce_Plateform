<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Package services (owner decision 2026-10-26): what Umar Techy does for a
 * store on each package — help, setup, support — next to what the software
 * includes. Plain lines, edited by platform staff in Super Admin → Packages.
 *
 * The three standard packages get the owner's first wording; a package that
 * already has services (or any other package) is left as it is.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'basic' => [
            'Help centre and email support',
            'Daily encrypted backups of your store',
            'Secure HTTPS on your store address',
        ],
        'business' => [
            'Store setup with you, including your first 50 products',
            'Your own domain connected and secured for you',
            'A one-hour training session for you and your team',
            'Priority support on WhatsApp and email',
        ],
        'premium' => [
            'Everything in the Business services',
            'A dedicated WhatsApp support line',
            'Two hours of store design changes every month',
            'A monthly performance report with suggestions',
            'New features first',
        ],
    ];

    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->json('services')->nullable()->after('name');
        });

        foreach (self::DEFAULTS as $code => $services) {
            DB::table('packages')->where('code', $code)->whereNull('services')->update(['services' => json_encode($services)]);
        }
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('services');
        });
    }
};
