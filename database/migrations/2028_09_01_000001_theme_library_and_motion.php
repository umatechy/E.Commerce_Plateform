<?php

declare(strict_types=1);

use App\Domain\Theme\Support\ThemeCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B36 — Module 17 (themes, layout, sections) and Module 18 (motion).
 *
 * 1. The theme library: themes get a description, a package tier and an
 *    order; the five system themes of ThemeCatalog are registered. The B15
 *    theme `default` becomes "Classic" (same key, so every store stays on
 *    it and looks the same until its owner chooses another theme).
 *
 * 2. Package features (owner decision 2026-10-04: Basic 2 themes, Business 3,
 *    Premium all themes + premium layouts + animations + section reorder;
 *    Module 04 §32–33 for the steps in between):
 *
 *      feature                      Basic  Business  Premium
 *      themes.business                -       ✓         ✓
 *      themes.premium                 -       -         ✓
 *      layout.advanced                -       -         ✓
 *      homepage.advanced_sections     -       ✓         ✓
 *      homepage.reorder               -       -         ✓
 *      animation.advanced             -       ✓         ✓
 *      animation.premium              -       -         ✓
 *
 *    Only the three standard packages get rows, and a value platform staff
 *    already set is kept. Platform staff can change any of them on the
 *    Packages page.
 */
return new class extends Migration
{
    private const FEATURES = [
        'themes.business' => ['basic' => false, 'business' => true, 'premium' => true],
        'themes.premium' => ['basic' => false, 'business' => false, 'premium' => true],
        'layout.advanced' => ['basic' => false, 'business' => false, 'premium' => true],
        'homepage.advanced_sections' => ['basic' => false, 'business' => true, 'premium' => true],
        'homepage.reorder' => ['basic' => false, 'business' => false, 'premium' => true],
        'animation.advanced' => ['basic' => false, 'business' => true, 'premium' => true],
        'animation.premium' => ['basic' => false, 'business' => false, 'premium' => true],
    ];

    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('name');
            $table->string('tier', 16)->default('basic')->after('description'); // basic | business | premium (Module 04 §32)
            $table->unsignedSmallInteger('sort_order')->default(0)->after('status');
        });

        foreach (ThemeCatalog::keys() as $order => $key) {
            $theme = ThemeCatalog::get($key);
            $values = ['name' => $theme['name'], 'description' => $theme['description'], 'tier' => $theme['tier'], 'version' => $theme['version'], 'status' => 'active', 'sort_order' => $order, 'updated_at' => now()];
            if (DB::table('themes')->where('key', $key)->exists()) {
                DB::table('themes')->where('key', $key)->update($values);
            } else {
                DB::table('themes')->insert(['key' => $key, ...$values, 'created_at' => now()]);
            }
        }

        foreach (self::FEATURES as $feature => $byPackage) {
            foreach ($byPackage as $code => $enabled) {
                $packageId = DB::table('packages')->where('code', $code)->value('id');
                if ($packageId === null || DB::table('package_entitlements')->where('package_id', $packageId)->where('key', $feature)->exists()) {
                    continue;
                }
                DB::table('package_entitlements')->insert([
                    'package_id' => $packageId, 'key' => $feature, 'type' => 'feature',
                    'boolean_value' => $enabled, 'is_unlimited' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('package_entitlements')->whereIn('key', array_keys(self::FEATURES))->delete();
        DB::table('themes')->whereIn('key', array_diff(ThemeCatalog::keys(), [ThemeCatalog::DEFAULT]))->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('store_themes')->whereColumn('store_themes.theme_id', 'themes.id'),
        )->delete();
        DB::table('themes')->where('key', ThemeCatalog::DEFAULT)->update(['name' => 'Default Storefront Theme', 'version' => '1.0.0']);
        Schema::table('themes', function (Blueprint $table) {
            $table->dropColumn(['description', 'tier', 'sort_order']);
        });
    }
};
