<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Http\Controllers;

use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Storefront\Services\StorefrontExperience;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module 05 — the shopper-facing pages (Inertia). Served under
 * /shop/{storeSlug}/... and, on a store's verified custom domain, at the
 * domain root. Page data is rendered into the initial HTML, and the
 * `seo` prop is written into <head> server-side by the root Blade view,
 * so crawlers get titles, canonicals and structured data without
 * running any JavaScript.
 */
final class StorefrontWebController
{
    public function __construct(
        private readonly StorefrontExperience $experience,
        private readonly SeoResolver $seo,
    ) {}

    public function home(Request $request): Response
    {
        $home = $this->experience->home($this->store($request));

        return $this->render($request, 'Storefront/Home', ['sections' => $home['sections']], $home['seo']);
    }

    public function catalog(Request $request): Response
    {
        return $this->listingPage($request, [], null);
    }

    public function search(Request $request): Response
    {
        return $this->listingPage($request, [], ['type' => 'search', 'title' => 'Search results']);
    }

    public function category(Request $request, string $categorySlug): Response
    {
        $data = $this->experience->category($this->store($request), $categorySlug) ?? throw new NotFoundHttpException();

        return $this->listingPage($request, ['category' => $categorySlug], ['type' => 'category', 'title' => $data['category']['name'], 'category' => $data['category']], $data['seo']);
    }

    public function brand(Request $request, string $brandSlug): Response
    {
        $data = $this->experience->brand($this->store($request), $brandSlug) ?? throw new NotFoundHttpException();

        return $this->listingPage($request, ['brand' => $brandSlug], ['type' => 'brand', 'title' => $data['brand']['name'], 'brand' => $data['brand']], $data['seo']);
    }

    public function product(Request $request, string $productSlug): Response
    {
        $data = $this->experience->product($this->store($request), $productSlug) ?? throw new NotFoundHttpException();

        return $this->render($request, 'Storefront/Product', ['product' => $data['product'], 'related' => $data['related']], $data['seo']);
    }

    public function page(Request $request, string $pageSlug): Response
    {
        $data = $this->experience->page($this->store($request), $pageSlug) ?? throw new NotFoundHttpException();

        return $this->render($request, 'Storefront/Page', ['page' => $data['page']], $data['seo']);
    }

    public function cart(Request $request): Response
    {
        return $this->render($request, 'Storefront/Cart', [], $this->privatePageSeo($request, 'Your cart'));
    }

    public function checkout(Request $request): Response
    {
        return $this->render($request, 'Storefront/Checkout', [
            'payment_methods' => $this->experience->paymentMethods(),
        ], $this->privatePageSeo($request, 'Checkout'));
    }

    /**
     * @param array<string, string> $fixed filters implied by the URL (category/brand)
     * @param ?array<string, mixed> $context
     * @param ?array<string, mixed> $seo
     */
    private function listingPage(Request $request, array $fixed, ?array $context, ?array $seo = null): Response
    {
        $store = $this->store($request);
        // Invalid query values are dropped, not answered with an error page.
        $filters = StorefrontApiController::normalize([
            ...Validator::make($request->query(), StorefrontApiController::filterRules())->valid(),
            ...$fixed,
        ]);

        $seo ??= $this->listingSeo($store, $context === null ? 'All products' : $context['title'], $context === null ? 'products' : 'search');
        $refined = array_diff_key($filters, array_flip(['category', 'brand'])) !== [];
        if ($refined || ($context['type'] ?? null) === 'search') {
            // Filtered, sorted, paged and search result pages would be
            // near-duplicates of the plain listing: kept out of the index.
            $seo['robots'] = 'noindex, follow';
        }

        return $this->render($request, 'Storefront/Catalog', [
            'context' => $context ?? ['type' => 'all', 'title' => 'All products'],
            'filters' => $filters,
            'listing' => $this->experience->listing($store, $filters),
            'facets' => $this->experience->facets($store),
        ], $seo);
    }

    /** @return array<string, mixed> */
    private function listingSeo(Store $store, string $title, string $path): array
    {
        $home = $this->seo->forStoreHome($store);

        return [
            'title' => "{$title} | {$store->name}",
            'description' => $home->metaDescription,
            'canonical' => rtrim($home->canonicalUrl, '/').'/'.$path,
            'og_title' => "{$title} | {$store->name}",
            'og_description' => $home->metaDescription,
            'og_image' => $home->ogImageUrl,
            'robots' => $home->robotsContent(),
            'structured_data' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function privatePageSeo(Request $request, string $title): array
    {
        return [...$this->listingSeo($this->store($request), $title, ''), 'robots' => 'noindex, nofollow'];
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $seo
     */
    private function render(Request $request, string $component, array $props, array $seo): Response
    {
        $store = $this->store($request);

        return Inertia::render($component, [
            ...$props,
            'storefront' => [
                ...$this->experience->shell($store),
                'base_path' => $request->attributes->get('storefront.base_path'),
                'preview' => (bool) $request->attributes->get('storefront.preview'),
            ],
            'seo' => $seo,
        ]);
    }

    private function store(Request $request): Store
    {
        return $request->attributes->get('storefront.store');
    }
}
