<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Theme\Models\Theme;
use Illuminate\Database\Seeder;

/**
 * Module 17 §7 "Theme Registry" — seeds the ONE system theme B15
 * implements (see docs/development/b15-inspection-findings.md "Scope
 * Decision" — no marketplace, no competing themes yet). Must run
 * before any store registers, exactly like PackageSeeder, since
 * StoreObserver::createDefaultForStore() looks this row up by key.
 */
final class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        Theme::query()->firstOrCreate(['key' => 'default'], [
            'name' => 'Default Storefront Theme',
            'version' => '1.0.0',
            'status' => 'active',
        ]);
    }
}
