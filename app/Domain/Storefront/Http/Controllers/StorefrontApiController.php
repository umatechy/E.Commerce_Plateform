<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Http\Controllers;

use App\Domain\Storefront\Services\StorefrontExperience;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Module 05 — the public storefront API (/api/v1/storefront/...), for
 * headless and mobile storefronts. Same data as the server-rendered
 * pages (both read StorefrontExperience). The store was resolved and
 * found open by ResolveStorefrontStore.
 */
final class StorefrontApiController
{
    public function __construct(private readonly StorefrontExperience $experience) {}

    public function show(Request $request): JsonResponse
    {
        $store = $this->store($request);

        return $this->ok([
            'shell' => $this->experience->shell($store),
            'home' => $this->experience->home($store),
            'checkout' => ['payment_methods' => $this->experience->paymentMethods()],
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), self::filterRules())->validate();

        return $this->ok($this->experience->listing($this->store($request), self::normalize($filters)));
    }

    public function product(Request $request, string $slug): JsonResponse
    {
        return $this->okOr404($this->experience->product($this->store($request), $slug), 'product');
    }

    public function categories(Request $request): JsonResponse
    {
        return $this->ok($this->experience->facets($this->store($request))['categories']);
    }

    public function category(Request $request, string $slug): JsonResponse
    {
        return $this->okOr404($this->experience->category($this->store($request), $slug), 'category');
    }

    public function brands(Request $request): JsonResponse
    {
        return $this->ok($this->experience->facets($this->store($request))['brands']);
    }

    public function brand(Request $request, string $slug): JsonResponse
    {
        return $this->okOr404($this->experience->brand($this->store($request), $slug), 'brand');
    }

    /** Phase B39: the live collections, and one collection's head (its products: /products?collection=slug). */
    public function collections(Request $request): JsonResponse
    {
        return $this->ok($this->experience->collections($this->store($request)));
    }

    public function collection(Request $request, string $slug): JsonResponse
    {
        return $this->okOr404($this->experience->collection($this->store($request), $slug), 'collection');
    }

    public function page(Request $request, string $slug): JsonResponse
    {
        return $this->okOr404($this->experience->page($this->store($request), $slug), 'page');
    }

    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return $this->ok($this->experience->suggestions(trim($validated['q'])));
    }

    /** @return array<string, list<mixed>> listing filters, shared with the server-rendered catalog pages */
    public static function filterRules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:191'],
            'brand' => ['nullable', 'string', 'max:191'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'in_stock' => ['nullable', 'boolean'],
            // Phase B39: a collection (live only), a tag; a collection's own order and best sellers.
            'collection' => ['nullable', 'string', 'max:191'],
            'tag' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:'.implode(',', \App\Domain\Storefront\Services\StorefrontCatalog::COLLECTION_SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('storefront.max_per_page')],
        ];
    }

    /**
     * Typed and trimmed, empty values dropped, so equivalent URLs share
     * one cache entry.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function normalize(array $filters): array
    {
        $out = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $out[$key] = match ($key) {
                'min_price', 'max_price', 'page', 'per_page' => (int) $value,
                'in_stock' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => trim((string) $value),
            };
        }

        if (($out['in_stock'] ?? null) === false) {
            unset($out['in_stock']);
        }

        return $out;
    }

    private function store(Request $request): Store
    {
        return $request->attributes->get('storefront.store');
    }

    /** @param array<array-key, mixed> $data */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }

    /** @param ?array<string, mixed> $data */
    private function okOr404(?array $data, string $what): JsonResponse
    {
        return $data === null
            ? response()->json(['message' => ucfirst($what).' not found.', 'code' => "{$what}_not_found"], 404)
            : $this->ok($data);
    }
}
