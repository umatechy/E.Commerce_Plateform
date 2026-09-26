<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Packages\Http\Requests\PackageRequest;
use App\Domain\Packages\Http\Resources\PackageResource;
use App\Domain\Packages\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Module 04: package definitions are platform-level configuration —
 * this controller is the ONLY place a Package row can be created or
 * modified, and every method requires Gate::authorize('manage', ...)
 * against PackagePolicy (Super Admin only). This is deliberately
 * separate from the public PackageController (read-only, unauthenticated)
 * — the two are never merged into one controller so the
 * read/write authorization boundary stays structurally obvious.
 */
final class SuperAdminPackageController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        \Illuminate\Support\Facades\Gate::forUser($request->user())->authorize('manage', Package::class);

        return PackageResource::collection(Package::query()->with('entitlements')->get());
    }

    public function store(PackageRequest $request): PackageResource
    {
        \Illuminate\Support\Facades\Gate::forUser($request->user())->authorize('manage', Package::class);

        $package = Package::query()->create($request->validated());

        \Illuminate\Support\Facades\Log::channel('audit')->info('super_admin.package.created', [
            'acting_super_admin_id' => $request->user()->id, 'package_id' => $package->id, 'code' => $package->code,
        ]);

        return new PackageResource($package);
    }

    public function update(PackageRequest $request, Package $package): PackageResource
    {
        \Illuminate\Support\Facades\Gate::forUser($request->user())->authorize('manage', Package::class);

        $before = $package->only(array_keys($request->validated()));
        $package->update($request->validated());

        \Illuminate\Support\Facades\Log::channel('audit')->info('super_admin.package.updated', [
            'acting_super_admin_id' => $request->user()->id, 'package_id' => $package->id,
            'before' => $before, 'after' => $package->fresh()->only(array_keys($request->validated())),
        ]);

        return new PackageResource($package->load('entitlements'));
    }
}
