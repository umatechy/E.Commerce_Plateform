<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Domain\Catalog\Models\Product;
use App\Domain\DeveloperPlatform\Http\Resources\DevProductResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Module 31 §5/§34 "Developer Platform Concept / Business Domain
 * Reuse" — reads the SAME Product model B3 owns; no duplicate
 * business logic. Tenant scope is automatic (Product uses
 * BelongsToTenant; TenantContext was already resolved by
 * EnsureApiKeyAuthenticated before this controller runs). Pagination
 * is bounded (max 100/page, Module 31 §24/§60) — never an unbounded
 * dataset.
 */
final class DevProductController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 25)));

        return DevProductResource::collection(Product::query()->paginate($perPage));
    }

    public function show(Product $product): DevProductResource
    {
        return new DevProductResource($product);
    }
}
