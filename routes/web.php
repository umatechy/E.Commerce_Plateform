<?php

use App\Domain\Storefront\Http\Controllers\StorefrontWebController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Page (GET) routes only — actual mutations (login/register/logout) go
// through /api/v1/auth/* (ADR-005), called via Inertia's useForm from
// these pages. This keeps the "web" vs "api" boundary consistent with
// ADR-005 rather than mixing session-mutating POSTs into routes/web.php.

// --- Storefront (Module 05, Phase B24) ---
// The same shopper pages at two addresses: /shop/{storeSlug}/... on the
// platform host, and the root of a store's verified custom domain. The
// domain group is constrained to hosts other than the platform's own
// (APP_URL), so the admin routes below are never shadowed; an unknown
// host that is not a verified store domain gets a 404 from the
// middleware.
$storefrontPages = function (): void {
    Route::get('/', [StorefrontWebController::class, 'home']);
    Route::get('/products', [StorefrontWebController::class, 'catalog']);
    Route::get('/search', [StorefrontWebController::class, 'search']);
    Route::get('/products/{productSlug}', [StorefrontWebController::class, 'product']);
    Route::get('/categories/{categorySlug}', [StorefrontWebController::class, 'category']);
    Route::get('/brands/{brandSlug}', [StorefrontWebController::class, 'brand']);
    Route::get('/pages/{pageSlug}', [StorefrontWebController::class, 'page']);
    Route::get('/cart', [StorefrontWebController::class, 'cart']);
    Route::get('/checkout', [StorefrontWebController::class, 'checkout']);
};

Route::domain('{storefrontHost}')
    ->where(['storefrontHost' => '(?!'.preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST), '#').'$)[A-Za-z0-9.\-]+'])
    ->middleware('storefront.store:host')
    ->group($storefrontPages);

Route::prefix('shop/{storeSlug}')
    ->where(['storeSlug' => '[a-z0-9][a-z0-9\-]*'])
    ->middleware('storefront.store:path')
    ->group($storefrontPages);

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/billing', fn () => Inertia::render('Billing/Overview'));
    Route::get('/inventory', fn () => Inertia::render('Inventory/Index'));
    Route::get('/orders', fn () => Inertia::render('Orders/Index'));
    Route::get('/store-health', fn () => Inertia::render('StoreHealth/Index')); // Module 24 (Phase B21)
});

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => Inertia::render('Auth/Login'));
    Route::get('/register', fn () => Inertia::render('Auth/Register'));
});
