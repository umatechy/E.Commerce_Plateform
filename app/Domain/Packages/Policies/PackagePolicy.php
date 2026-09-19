<?php

declare(strict_types=1);

namespace App\Domain\Packages\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;

/**
 * Module 04: "Package definitions are platform-level configuration.
 * Normal tenant users must NOT be able to modify platform packages
 * unless the specification explicitly grants that capability" — it
 * does not, so this Policy is Super-Admin-only for every mutating
 * action. Viewing the package catalog is public (marketing/upgrade
 * pages) — see PackageController::index(), which is on the
 * /api/v1/public/... surface and does not go through this Policy at
 * all (public read, no policy needed).
 */
final class PackagePolicy
{
    public function manage(User $user): bool
    {
        return $user->isPlatformStaff();
    }
}
