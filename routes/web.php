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
Route::get('/', function (Request $request) {
    return Inertia::render($request->user() ? 'Dashboard' : 'Welcome');
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

    // Phase B31 (gap G6): the Store Admin pages. Each one only renders the
    // page; its data comes from /api/v1, where the policy for that data
    // decides. A page a user may not use shows the API's refusal.
    foreach ([
        '/products' => 'Catalog/Products', '/collections' => 'Catalog/Collections', '/badges' => 'Catalog/Badges', '/categories' => 'Catalog/Categories', '/brands' => 'Catalog/Brands', '/attributes' => 'Catalog/Attributes',
        '/warehouses' => 'Inventory/Warehouses',
        '/payments' => 'Payments/Index', '/shipments' => 'Shipping/Shipments', '/shipping' => 'Shipping/Settings',
        '/customers' => 'Customers/Index',
        '/promotions' => 'Marketing/Promotions', '/campaigns' => 'Marketing/Campaigns', '/segments' => 'Marketing/Segments',
        '/content/pages' => 'Content/Pages', '/content/redirects' => 'Content/Redirects', '/content/seo' => 'Content/Seo',
        '/storefront/theme' => 'Storefront/Theme', '/domains' => 'Storefront/Domains',
        '/reports' => 'Analytics/Reports',
        '/notifications' => 'Communication/Index',
        '/team/roles' => 'Team/Roles',
        '/backups' => 'Backups/Index',
        '/settings' => 'Settings/Index', '/settings/audit-log' => 'Settings/AuditLog', '/settings/developer' => 'Settings/Developer',
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
            '/packages' => 'SuperAdmin/Packages', '/billing' => 'SuperAdmin/Billing', '/settings' => 'SuperAdmin/Settings',
            '/monitoring' => 'SuperAdmin/Monitoring', '/audit-log' => 'SuperAdmin/AuditLog', '/catalog' => 'SuperAdmin/Platform',
            '/starter-templates' => 'SuperAdmin/StarterTemplates', // Phase B45 follow-up
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

Route::middleware('guest')->group(function () use ($intendedPath) {
    // Named: the auth middleware sends signed-out visitors of admin pages
    // here (without the name they got a 500, "Route [login] not defined").
    Route::get('/login', fn (Request $request) => Inertia::render('Auth/Login', ['intended' => $intendedPath($request)]))->name('login');
    // Phase B44 (owner decision 13): sign-up may be closed; the page then says whom to contact.
    Route::get('/register', fn () => Inertia::render('Auth/Register', [
        'signupOpen' => app(\App\Domain\Settings\Services\ConfigService::class)->get('platform.self_signup_enabled') !== false,
        'businessCategories' => collect(\App\Domain\Tenancy\Support\BusinessCategories::ALL)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
    ]));
});
