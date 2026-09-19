<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Requests\StoreAttributeRequest;
use App\Domain\Catalog\Http\Resources\AttributeResource;
use App\Domain\Catalog\Models\Attribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Module 07 §36-37 "Attribute Values / Attribute Value Normalization" —
 * normalized_value (lowercased, trimmed) is what the uniqueness
 * constraint actually checks, so "Black" and "black" cannot both be
 * added as distinct values of the same attribute by mistake.
 */
final class AttributeController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Attribute::class);

        return AttributeResource::collection(Attribute::query()->with('values')->get());
    }

    public function store(StoreAttributeRequest $request): AttributeResource
    {
        Gate::forUser($request->user())->authorize('manage', Attribute::class);

        $attribute = DB::transaction(function () use ($request) {
            $attribute = Attribute::query()->create([
                'name' => $request->string('name'),
                'key' => $request->string('key'),
                'type' => $request->string('type'),
            ]);

            foreach ($request->input('values', []) as $index => $value) {
                $attribute->values()->create([
                    'value' => $value,
                    'normalized_value' => Str::lower(trim($value)),
                    'sort_order' => $index,
                ]);
            }

            return $attribute;
        });

        return new AttributeResource($attribute->load('values'));
    }

    public function destroy(Request $request, Attribute $attribute): \Illuminate\Http\Response
    {
        Gate::forUser($request->user())->authorize('manage', $attribute);

        $attribute->delete(); // hard delete (no soft-delete column on attributes — low business-integrity risk, unlike products/orders)

        return response()->noContent();
    }
}
