<?php

declare(strict_types=1);

namespace App\Domain\Packages\Http\Controllers;

use App\Domain\Packages\Http\Resources\PackageResource;
use App\Domain\Packages\Models\Package;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public catalog listing (Module 04 §40 "Customer Package Visibility":
 * "Available Upgrades" must be visible without contacting Umar Techy —
 * this must be readable even by a not-yet-registered visitor comparing
 * plans, so it is deliberately unauthenticated, on
 * /api/v1/public/packages, per ADR-005. Only active packages are
 * listed — a deactivated/legacy package (Module 04 §19 "Package
 * Versioning") is never shown for new signups.
 */
final class PackageController
{
    public function index(): AnonymousResourceCollection
    {
        return PackageResource::collection(
            Package::query()->where('is_active', true)->with('entitlements')->get()
        );
    }
}
