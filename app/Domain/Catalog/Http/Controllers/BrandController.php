<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Requests\StoreBrandRequest;
use App\Domain\Catalog\Http\Resources\BrandResource;
use App\Domain\Catalog\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class BrandController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Brand::class);

        return BrandResource::collection(Brand::query()->orderBy('name')->get());
    }

    public function store(StoreBrandRequest $request): BrandResource
    {
        Gate::forUser($request->user())->authorize('manage', Brand::class);

        $brand = Brand::query()->create([
            ...$request->validated(),
            'slug' => Str::slug($request->string('name')).'-'.Str::lower(Str::random(6)),
        ]);

        return new BrandResource($brand);
    }

    public function update(StoreBrandRequest $request, Brand $brand): BrandResource
    {
        Gate::forUser($request->user())->authorize('manage', $brand);

        $brand->update($request->validated());

        return new BrandResource($brand->refresh());
    }

    public function destroy(Request $request, Brand $brand): \Illuminate\Http\Response
    {
        Gate::forUser($request->user())->authorize('manage', $brand);

        $brand->delete(); // soft delete — products.brand_id is nulled by FK (nullOnDelete)

        return response()->noContent();
    }
}
