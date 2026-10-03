<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-10-03 (Module 10 §87, Module 04 §8): customer
 * groups, tags, CSV import/export and merge are Business and Premium
 * features (`customers.advanced`); Basic keeps accounts, profiles,
 * addresses, search, notes, blocking and privacy requests.
 *
 * PackageSeeder sets this for new installs. Here the three standard
 * packages of an existing database get the row, unless one is already
 * there (a value set by platform staff is kept). Other packages get no
 * row, which means "not included", as for every other feature; platform
 * staff can add it on the Packages page.
 *
 * Nothing a Basic store already made is deleted (Module 04: a downgrade
 * never deletes data): its groups and tags stay visible; only new
 * changes need the feature.
 */
return new class extends Migration
{
    private const VALUES = ['basic' => false, 'business' => true, 'premium' => true];

    public function up(): void
    {
        foreach (self::VALUES as $code => $enabled) {
            $packageId = DB::table('packages')->where('code', $code)->value('id');
            if ($packageId === null) {
                continue;
            }
            $exists = DB::table('package_entitlements')->where('package_id', $packageId)->where('key', 'customers.advanced')->exists();
            if (! $exists) {
                DB::table('package_entitlements')->insert([
                    'package_id' => $packageId, 'key' => 'customers.advanced', 'type' => 'feature',
                    'boolean_value' => $enabled, 'is_unlimited' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('package_entitlements')->where('key', 'customers.advanced')->delete();
    }
};
