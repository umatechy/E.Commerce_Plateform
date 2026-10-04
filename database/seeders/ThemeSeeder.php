<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Theme\Models\Theme;
use App\Domain\Theme\Support\ThemeCatalog;
use Illuminate\Database\Seeder;

/**
 * Module 17 §7 "Theme Registry" — registers the system themes
 * (ThemeCatalog, Phase B36: Classic, Minimal, Modern, Boutique, Bold).
 * Must run before any store registers, exactly like PackageSeeder, since
 * StoreObserver::createDefaultForStore() looks the default theme up by key.
 */
final class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ThemeCatalog::keys() as $order => $key) {
            $theme = ThemeCatalog::get($key);
            Theme::query()->updateOrCreate(['key' => $key], [
                'name' => $theme['name'], 'description' => $theme['description'], 'tier' => $theme['tier'],
                'version' => $theme['version'], 'status' => 'active', 'sort_order' => $order,
            ]);
        }
    }
}
