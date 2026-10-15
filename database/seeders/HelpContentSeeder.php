<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Help\Services\HelpBundleSync;
use Illuminate\Database\Seeder;

/**
 * Phase B54: the built-in help articles and release notes (resources/help).
 * The same as `php artisan help:sync`, which deployments run after migrating.
 */
final class HelpContentSeeder extends Seeder
{
    public function run(HelpBundleSync $sync): void
    {
        $result = $sync->sync();
        if ($result['problems'] !== []) {
            throw new \RuntimeException('resources/help has problems; run php artisan help:check. '.implode(' | ', $result['problems']));
        }
    }
}
