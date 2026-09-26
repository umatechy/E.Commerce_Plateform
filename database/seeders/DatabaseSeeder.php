<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            PackageSeeder::class, // must run before any store registers, so DEFAULT_TRIAL_PACKAGE_CODE resolves
            ThemeSeeder::class, // must run before any store registers — StoreObserver looks up the 'default' theme by key
        ]);

        // No demo Store/User data is seeded here (this milestone: "do not
        // delete existing production roles merely because a seeder runs"
        // — extended to mean this seeder never creates speculative
        // business data either; registration (AuthController) is the
        // only path that creates a Store).
    }
}
