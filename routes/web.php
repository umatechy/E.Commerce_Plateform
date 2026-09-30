<?php

use App\Domain\Storefront\Http\Controllers\StorefrontWebController;
use Illuminate\Http\Request;
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

    // Phase B25: customer account pages (data loaded by the page from the
    // customer APIs with the HttpOnly session cookie; never indexed).
    foreach ([
        '/account' => 'Dashboard', '/account/login' => 'Login', '/account/register' => 'Register',
        '/account/forgot-password' => 'ForgotPassword', '/account/reset-password' => 'ResetPassword',
        '/account/orders' => 'Orders', '/account/orders/{orderId}' => 'Order', '/account/addresses' => 'Addresses',
        '/account/profile' => 'Profile', '/account/wishlist' => 'Wishlist',
        // Phase B26: support requests ('new' is registered before the id).
        '/account/support' => 'Support', '/account/support/new' => 'SupportNew', '/account/support/{ticketId}' => 'SupportTicket',
    ] as $uri => $page) {
        Route::get($uri, [StorefrontWebController::class, 'account'])->defaults('page', $page)->where('ticketId', '[0-9A-Za-z]{26}');
    }

    // Phase B26 (Module 34): the contact form, and a guest's request
    // opened from the private link in their email.
    Route::get('/contact', [StorefrontWebController::class, 'contact']);
    Route::get('/support/tickets/{ticketId}', [StorefrontWebController::class, 'supportTicket'])->where('ticketId', '[0-9A-Za-z]{26}');
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
    // Module 34 (Phase B26): the store's inbox, and its own requests to the platform.
    Route::get('/support', fn () => Inertia::render('Support/Index'));
    Route::get('/support/platform', fn () => Inertia::render('Support/Platform'));
    // The platform's inbox (platform staff only; the API checks again).
    Route::get('/super-admin/support', fn () => Inertia::render('SuperAdmin/Support'))->middleware('can:super-admin.platform');
});

// Where the auth middleware was sending a signed-out visitor (it keeps
// the URL in the session): only a path on this host, never another site.
$intendedPath = function (Request $request): ?string {
    $intended = (string) $request->session()->get('url.intended', '');
    $parts = parse_url($intended);
    $path = $parts['path'] ?? '';

    if (($parts['host'] ?? null) !== $request->getHost() || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return null;
    }

    return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
};

Route::middleware('guest')->group(function () use ($intendedPath) {
    // Named: the auth middleware sends signed-out visitors of admin pages
    // here (without the name they got a 500, "Route [login] not defined").
    Route::get('/login', fn (Request $request) => Inertia::render('Auth/Login', ['intended' => $intendedPath($request)]))->name('login');
    Route::get('/register', fn () => Inertia::render('Auth/Register'));
});
