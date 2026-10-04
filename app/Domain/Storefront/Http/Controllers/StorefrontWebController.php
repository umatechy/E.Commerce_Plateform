<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Http\Controllers;

use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Storefront\Services\StorefrontExperience;
use App\Domain\Support\Models\SupportCategory;
use App\Domain\Support\Models\SupportDesk;
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
        private readonly \App\Domain\Theme\Services\ThemePreviewLink $themePreview,
    ) {}

    public function home(Request $request): Response
    {
        $home = $this->experience->home($this->store($request), $this->themePreview($request));

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
     * Phase B25 — the customer account pages. The server only renders the
     * page shell; the page reads the signed-in customer's data from the
     * customer APIs itself (the session is an HttpOnly cookie the API
     * understands), so no personal data is ever put into page HTML.
     */
    public function account(Request $request): Response
    {
        $page = (string) $request->route('page');
        $props = match ($page) {
            'Order' => ['order_id' => (string) $request->route('orderId')],
            'SupportTicket' => ['ticket_id' => (string) $request->route('ticketId')],
            // Read from the link in the reset email; only echoed back to the API.
            // Phase B32: the "confirm your email" link, likewise only echoed back to the API.
            'ResetPassword', 'VerifyEmail' => ['token' => (string) $request->query('token', ''), 'email' => (string) $request->query('email', '')],
            'Login' => ['redirect' => $this->safeRedirect($request)],
            default => [],
        };

        return $this->render($request, "Storefront/Account/{$page}", $props, $this->privatePageSeo($request, match ($page) {
            'Login' => 'Sign in',
            'Register' => 'Create an account',
            'ForgotPassword', 'ResetPassword' => 'Reset your password',
            'VerifyEmail' => 'Confirm your email',
            'Support', 'SupportNew', 'SupportTicket' => 'Support',
            default => 'Your account',
        }));
    }

    /**
     * Phase B34 — returns for someone without an account (Module 09
     * §8–9). The page asks for the order number and email, or, opened
     * from the emailed link, carries the link's token to the API.
     *
     * The token is taken out of the address at once: it is kept in the
     * visitor's session (for this store) and the browser is sent on to the
     * same page without it, so it does not stay in the history, in a
     * bookmark or in a Referer header. `?forget=1` ends it. Never indexed.
     */
    public function returns(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $key = 'storefront.return_token.'.$this->store($request)->id;

        if ($request->has('token') || $request->has('forget')) {
            $token = (string) $request->query('token', '');
            // Only a well-formed token is kept; it is only ever echoed back to the API, which checks it.
            if (! $request->has('forget') && preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1) {
                $request->session()->put($key, $token);
            } else {
                $request->session()->forget($key);
            }

            return redirect()->to($request->url());
        }

        return $this->render($request, 'Storefront/Returns', [
            'token' => (string) $request->session()->get($key, ''),
        ], $this->privatePageSeo($request, 'Returns'));
    }

    /**
     * Phase B26 — the contact form (Module 34). Public and indexable like
     * any store page; a signed-in shopper's request goes to their account.
     */
    public function contact(Request $request): Response
    {
        return $this->render($request, 'Storefront/Contact', [
            'categories' => array_map(fn (SupportCategory $c) => $c->value, SupportCategory::forDesk(SupportDesk::Store)),
        ], $this->listingSeo($this->store($request), 'Contact us', 'contact'));
    }

    /**
     * A guest's request, opened from the private link in their email. The
     * access token is in the link's #fragment, which browsers never send,
     * so this request (and its logs) never sees it: the page reads it and
     * sends it to the API in a header.
     */
    public function supportTicket(Request $request): Response
    {
        return $this->render($request, 'Storefront/SupportTicket', [
            'ticket_id' => (string) $request->route('ticketId'),
        ], $this->privatePageSeo($request, 'Your request'));
    }

    /** Where to go after signing in: a path inside this storefront only, never another site. */
    private function safeRedirect(Request $request): ?string
    {
        $target = (string) $request->query('redirect', '');
        $base = (string) $request->attributes->get('storefront.base_path');

        return preg_match('#^/[A-Za-z0-9/_\-]*$#', $target) === 1 && ! str_starts_with($target, '//') && str_starts_with($target, $base.'/')
            ? $target
            : null;
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
        $themePreview = $this->themePreview($request);

        return Inertia::render($component, [
            ...$props,
            'storefront' => [
                ...$this->experience->shell($store, $themePreview),
                'base_path' => $request->attributes->get('storefront.base_path'),
                'preview' => (bool) $request->attributes->get('storefront.preview'),
                // Module 17 §19 (Phase B32): until when the draft theme is shown, or null.
                'theme_preview' => $themePreview['expires_at'] ?? null,
            ],
            // A preview is never indexed.
            'seo' => $this->localizedSeo($themePreview !== null ? [...$seo, 'robots' => 'noindex, nofollow'] : $seo),
        ]);
    }

    /**
     * The draft theme for this request (Module 17 §19), worked out once.
     *
     * @return array{config: mixed, custom_css: ?string, expires_at: string}|null
     */
    private function themePreview(Request $request): ?array
    {
        if (! $request->attributes->has('storefront.theme_preview')) {
            $request->attributes->set('storefront.theme_preview', $this->themePreview->draftFor($request, $this->store($request), (string) $request->attributes->get('storefront.base_path')));
        }

        return $request->attributes->get('storefront.theme_preview');
    }

    private function store(Request $request): Store
    {
        return $request->attributes->get('storefront.store');
    }
    /**
     * Phase B38 — Module 16 §27: each language has its own address (`?lang=`
     * for languages other than the default), the canonical points to the
     * page's own language, and the other languages are listed as hreflang
     * alternates with an x-default.
     *
     * @param array<string, mixed> $seo
     * @return array<string, mixed>
     */
    private function localizedSeo(array $seo): array
    {
        $language = app(\App\Domain\Storefront\Services\StorefrontLocale::class);
        $base = (string) ($seo['canonical'] ?? '');
        if ($base === '') {
            return $seo;
        }
        $parts = parse_url($base);
        parse_str($parts['query'] ?? '', $query);
        unset($query['lang']);
        $with = function (string $locale) use ($base, $query, $language): string {
            $params = $locale === $language->default() ? $query : [...$query, 'lang' => $locale];
            $path = strtok($base, '?');

            return $path.($params === [] ? '' : '?'.http_build_query($params));
        };
        $offered = $language->offered();
        $seo['canonical'] = $with($language->current());
        $seo['og_locale'] = str_replace('-', '_', \App\Domain\Settings\Services\Locales::SUPPORTED[$language->current()][3] ?? 'en-PK');
        $seo['alternates'] = count($offered) < 2 ? [] : [
            ...array_map(fn (string $code) => ['hreflang' => $code, 'href' => $with($code)], $offered),
            ['hreflang' => 'x-default', 'href' => $with($language->default())],
        ];

        return $seo;
    }
}
