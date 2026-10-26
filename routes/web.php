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
    Route::get('/collections/{collectionSlug}', [StorefrontWebController::class, 'collection']);
    Route::get('/tags/{tagSlug}', [StorefrontWebController::class, 'tag']);
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
        '/account/rewards' => 'Rewards', // Phase P2: loyalty points
        // Phase B32: the link in the "confirm your email" message.
        '/account/verify-email' => 'VerifyEmail',
        // Phase B26: support requests ('new' is registered before the id).
        '/account/support' => 'Support', '/account/support/new' => 'SupportNew', '/account/support/{ticketId}' => 'SupportTicket',
    ] as $uri => $page) {
        Route::get($uri, [StorefrontWebController::class, 'account'])->defaults('page', $page)->where('ticketId', '[0-9A-Za-z]{26}');
    }

    // Phase B26 (Module 34): the contact form, and a guest's request
    // opened from the private link in their email.
    Route::get('/contact', [StorefrontWebController::class, 'contact']);
    // Phase B34 (Module 09 §8–9): returns for a guest, by the link sent to the order's email.
    Route::get('/returns', [StorefrontWebController::class, 'returns']);
    // Phase B55 (Module 25): the store's app files. The worker is answered whatever the
    // shop's state (storefront_always), so it can retire itself when the shop is closed.
    $pwa = \App\Domain\Pwa\Http\Controllers\StorefrontPwaController::class;
    Route::get('/manifest.webmanifest', [$pwa, 'manifest']);
    Route::get('/sw.js', [$pwa, 'worker'])->defaults('storefront_always', true);
    Route::get('/offline', [$pwa, 'offline']);
    // Phase B57 (Module 28): applying to the store's affiliate program, and an affiliate's private page.
    Route::get('/affiliates/join', [StorefrontWebController::class, 'affiliateJoin']);
    Route::get('/affiliate', [StorefrontWebController::class, 'affiliatePortal']);
    // Phase B54 (Module 34 §43): the store's help center for shoppers.
    Route::get('/help', [StorefrontWebController::class, 'help']);
    Route::get('/help/{articleKey}', [StorefrontWebController::class, 'helpArticle'])->where('articleKey', '[a-z0-9][a-z0-9\-]{2,79}');
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

// Signed in: the admin home with links to every built admin page.
// Signed out: the landing page with sign-in and registration links.
// UI-19: the admin installs on a phone's home screen like an app (name from Platform settings).
// No service worker: admin data is never kept for offline use on the device.
Route::get('/admin.webmanifest', function () {
    $name = (string) (app(\App\Domain\Settings\Services\ConfigService::class)->get('platform.brand_name') ?: 'Umar Techy');

    return response()->json([
        'name' => $name.' Admin',
        'short_name' => $name,
        'description' => 'Run your store: orders, products, customers and payments.',
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'background_color' => '#f8fafc',
        'theme_color' => '#4f46e5',
        'icons' => [
            ['src' => '/brand/app-icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ['src' => '/brand/app-icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'maskable'],
        ],
    ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
});

Route::get('/', function (Request $request) {
    if ($request->user()) {
        return Inertia::render('Dashboard');
    }

    // UI-1: the public home page — the packages as Umar Techy priced them, and whether sign-up is open.
    return Inertia::render('Welcome', [
        'plans' => \App\Domain\Packages\Support\PublicPlans::all(),
        'trialDays' => (int) app(\App\Domain\Settings\Services\ConfigService::class)->get('platform.trial_days'),
        'signupOpen' => app(\App\Domain\Settings\Services\ConfigService::class)->get('platform.self_signup_enabled') !== false,
    ]);
})->middleware('required.mfa');

// 'required.mfa': a Store Owner without MFA is sent to /security to enrol.
Route::middleware(['auth', 'required.mfa'])->group(function () {
    Route::get('/billing', fn () => Inertia::render('Billing/Overview'));
    Route::get('/inventory', fn () => Inertia::render('Inventory/Index'));
    Route::get('/orders', fn () => Inertia::render('Orders/Index'));
    Route::get('/store-health', fn () => Inertia::render('StoreHealth/Index')); // Module 24 (Phase B21)
    Route::get('/team', fn () => Inertia::render('Team/Index')); // Module 02 §18–19 (Phase G1)
    Route::get('/security', fn () => Inertia::render('Security/Index')); // Module 32 §8 (Phase B29): the user's own two-step sign-in
    // Module 34 (Phase B26): the store's inbox, and its own requests to the platform.
    Route::get('/support', fn () => Inertia::render('Support/Index'));
    Route::get('/support/platform', fn () => Inertia::render('Support/Platform'));
    // Module 34 (Phase B54): the help center, one article, and the release notes.
    Route::get('/help', fn () => Inertia::render('Help/Index'));
    Route::get('/help/whats-new', fn () => Inertia::render('Help/WhatsNew'));
    Route::get('/help/articles/{key}', fn (string $key) => Inertia::render('Help/Article', ['articleKey' => $key]))->where('key', '[a-z0-9][a-z0-9\-]{2,79}');

    // Phase B31 (gap G6): the Store Admin pages. Each one only renders the
    // page; its data comes from /api/v1, where the policy for that data
    // decides. A page a user may not use shows the API's refusal.
    foreach ([
        '/products' => 'Catalog/Products', '/collections' => 'Catalog/Collections', '/badges' => 'Catalog/Badges', '/reviews' => 'Catalog/Reviews', '/categories' => 'Catalog/Categories', '/brands' => 'Catalog/Brands', '/attributes' => 'Catalog/Attributes',
        '/warehouses' => 'Inventory/Warehouses',
        '/payments' => 'Payments/Index', '/shipments' => 'Shipping/Shipments', '/shipping' => 'Shipping/Settings',
        '/customers' => 'Customers/Index',
        '/promotions' => 'Marketing/Promotions', '/campaigns' => 'Marketing/Campaigns', '/segments' => 'Marketing/Segments',
        '/content/pages' => 'Content/Pages', '/content/redirects' => 'Content/Redirects', '/content/seo' => 'Content/Seo',
        '/storefront/theme' => 'Storefront/Theme', '/domains' => 'Storefront/Domains',
        '/storefront/app' => 'Storefront/App', // Phase B55: the store's installable app
        '/apps' => 'Apps/Index', // Phase B56: the app marketplace
        '/affiliates' => 'Marketing/Affiliates', // Phase B57: the store's affiliate program
        '/loyalty' => 'Marketing/Loyalty', // Phase P2: loyalty points
        '/partner' => 'Partner/Portal', // Phase B58: a reseller's partner portal (its API checks the reseller and two-step sign-in)
        '/reports' => 'Analytics/Reports',
        '/analytics' => 'Analytics/Advanced', // Phase P3: Premium analytics
        '/notifications' => 'Communication/Index',
        '/team/roles' => 'Team/Roles',
        '/backups' => 'Backups/Index',
        '/settings' => 'Settings/Hub', '/settings/general' => 'Settings/Index', '/settings/audit-log' => 'Settings/AuditLog', '/settings/developer' => 'Settings/Developer', '/settings/tax' => 'Settings/Tax', // tax: Phase B46
        '/settings/payments' => 'Settings/Payments', // Phase B48
        '/settings/close-store' => 'Settings/CloseStore', // Phase B51
    ] as $uri => $page) {
        Route::get($uri, fn () => Inertia::render($page));
    }
    // 'new' is registered before the id; ids are the public ULIDs the API returns.
    Route::get('/products/new', fn () => Inertia::render('Catalog/ProductEdit', ['productId' => null]));
    Route::get('/products/{product}', fn (string $product) => Inertia::render('Catalog/ProductEdit', ['productId' => $product]))->where('product', '[0-9A-Za-z]{26}');
    Route::get('/orders/new', fn () => Inertia::render('Orders/Create'));
    // Phase B32 (gap G7, Module 10): customer groups and tags, and one customer.
    Route::get('/customers/groups', fn () => Inertia::render('Customers/Groups'));
    Route::get('/customers/{customer}', fn (string $customer) => Inertia::render('Customers/Show', ['customerId' => $customer]))->where('customer', '[0-9A-Za-z]{26}');
    Route::get('/orders/{order}', fn (string $order) => Inertia::render('Orders/Show', ['orderId' => $order]))->where('order', '[0-9A-Za-z]{26}');
    // UI-15: a printable packing slip (the data comes from /api/v1/orders/{id}, with its permission).
    Route::get('/orders/{order}/print', fn (string $order) => Inertia::render('Orders/Print', ['orderId' => $order]))->where('order', '[0-9A-Za-z]{26}');
    // Phase B33 (gap G8): returns. Data and permissions come from /api/v1/returns.
    Route::get('/returns', fn () => Inertia::render('Returns/Index'));
    Route::get('/returns/{return}', fn (string $return) => Inertia::render('Returns/Show', ['returnId' => $return]))->where('return', '[0-9A-Za-z]{26}');

    // Umar Techy Super Admin (Module 30): platform staff only. The gate
    // here keeps the pages themselves away from store users; every
    // /api/v1/super-admin call is checked again, with MFA and step-up.
    Route::middleware('can:super-admin.platform')->prefix('super-admin')->group(function () {
        Route::get('/support', fn () => Inertia::render('SuperAdmin/Support'));
        // Module 23 (Phase B30): platform backups, rehearsals and restore requests.
        Route::get('/backups', fn () => Inertia::render('SuperAdmin/Backups'));
        // Phase B31 (gap G6).
        foreach ([
            '/' => 'SuperAdmin/Dashboard', '/stores' => 'SuperAdmin/Stores', '/users' => 'SuperAdmin/Users',
            '/packages' => 'SuperAdmin/Packages', '/billing' => 'SuperAdmin/Billing', '/payments' => 'SuperAdmin/Payments', '/settings' => 'SuperAdmin/Settings',
            '/monitoring' => 'SuperAdmin/Monitoring', '/audit-log' => 'SuperAdmin/AuditLog', '/catalog' => 'SuperAdmin/Platform',
            '/starter-templates' => 'SuperAdmin/StarterTemplates', // Phase B45 follow-up
            '/messaging' => 'SuperAdmin/Messaging', // Phase B50
            '/security' => 'SuperAdmin/Security', // Phase B52
            '/help' => 'SuperAdmin/Help', // Phase B54
            '/marketplace' => 'SuperAdmin/Marketplace', // Phase B56
            '/resellers' => 'SuperAdmin/Resellers', // Phase B58
        ] as $uri => $page) {
            Route::get($uri, fn () => Inertia::render($page));
        }
        Route::get('/stores/{store}', fn (string $store) => Inertia::render('SuperAdmin/Store', ['storeId' => $store]))->whereNumber('store');
    });
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

// Phase G1: opened from the invitation email, signed in or not. The token
// is in the #fragment, so it never reaches this route or its logs.
Route::get('/invitations/{invitation}', fn (string $invitation) => Inertia::render('Auth/AcceptInvitation', ['invitationId' => $invitation]))
    ->where('invitation', '[0-9A-Za-z]{26}');

// Phase B58 (Module 28 §92): the public partner page, and the invitation to
// the partner portal (signed in or not; the token is in the #fragment).
Route::get('/partners', fn () => Inertia::render('Partners/Apply'));
Route::get('/partner/join', fn () => Inertia::render('Partner/Join'));

Route::middleware('guest')->group(function () use ($intendedPath) {
    // Named: the auth middleware sends signed-out visitors of admin pages
    // here (without the name they got a 500, "Route [login] not defined").
    Route::get('/login', fn (Request $request) => Inertia::render('Auth/Login', ['intended' => $intendedPath($request)]))->name('login');
    // Phase B52 (Module 32 §7.5): staff "forgot password"; the reset link carries the token in its #fragment.
    Route::get('/forgot-password', fn () => Inertia::render('Auth/ForgotPassword'));
    Route::get('/reset-password', fn () => Inertia::render('Auth/ResetPassword'));
    // Phase B44 (owner decision 13): sign-up may be closed; the page then says whom to contact.
    // Phase B58: `?partner=CODE` — a reseller's sign-up link; remembered in this session until the store is created.
    Route::get('/register', fn (Request $request) => Inertia::render('Auth/Register', [
        'partner' => app(\App\Domain\Resellers\Services\ResellerAttribution::class)->rememberFromSignup($request)?->business_name,
        'signupOpen' => app(\App\Domain\Settings\Services\ConfigService::class)->get('platform.self_signup_enabled') !== false,
        'businessCategories' => collect(\App\Domain\Tenancy\Support\BusinessCategories::ALL)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
    ]));
});
