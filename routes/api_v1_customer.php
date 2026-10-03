<?php

use App\Domain\Cart\Http\Controllers\CartController;
use App\Domain\Cart\Http\Controllers\CheckoutController;
use App\Domain\Cart\Http\Controllers\WishlistController;
use App\Domain\Orders\Http\Controllers\CustomerAuthController;
use App\Domain\Shipping\Http\Controllers\CustomerShipmentController;
use App\Domain\Notifications\Http\Controllers\CustomerNotificationController;
use App\Domain\Shipping\Http\Controllers\ShippingQuoteController;
use Illuminate\Support\Facades\Route;

// ADR-005: same /api/v1/... prefix as staff routes — this is a
// different PRINCIPAL TYPE (storefront Customer), not a new API
// version (Module 10 §3's "logically separated" boundary, Phase B6).
//
// Tenant resolution for these routes (including anonymous/guest ones)
// comes from ResolveTenantContext's X-Store-Slug interim mechanism
// (see that middleware's docblock) until Module 19 (Domain Management)
// provides real domain-based resolution.

// --- Customer authentication (ADR-002 Surface A — `customer` Sanctum guard) ---
Route::post('/customer/register', [CustomerAuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/customer/login', [CustomerAuthController::class, 'login'])->middleware('throttle:10,1');
// Module 10 §9/§13 (Phase B32): the link from the email confirms the address, signed in or not.
Route::post('/customer/email/verify', [\App\Domain\CustomerAccount\Http\Controllers\CustomerEmailVerificationController::class, 'verify'])->middleware('throttle:10,1,customer-email-verify');

Route::middleware(['auth:customer', 'customer.principal'])->group(function () {
    Route::post('/customer/logout', [CustomerAuthController::class, 'logout']);
    Route::get('/customer/me', [CustomerAuthController::class, 'me']);
    // Module 32 (Phase B22): right of access, self-service.
    Route::get('/customer/personal-data', [\App\Domain\Compliance\Http\Controllers\CustomerPrivacyController::class, 'exportOwn']);

    // --- Customer account (Phase B25) ---
    Route::get('/customer/profile', [\App\Domain\CustomerAccount\Http\Controllers\CustomerProfileController::class, 'show']);
    Route::patch('/customer/profile', [\App\Domain\CustomerAccount\Http\Controllers\CustomerProfileController::class, 'update']);
    Route::post('/customer/email/verification', [\App\Domain\CustomerAccount\Http\Controllers\CustomerEmailVerificationController::class, 'send'])->middleware('throttle:3,10,customer-email-send');
    Route::put('/customer/email', [\App\Domain\CustomerAccount\Http\Controllers\CustomerProfileController::class, 'changeEmail'])->middleware('throttle:6,1');
    Route::put('/customer/password', [\App\Domain\CustomerAccount\Http\Controllers\CustomerProfileController::class, 'changePassword'])->middleware('throttle:6,1');
    Route::get('/customer/addresses', [\App\Domain\CustomerAccount\Http\Controllers\CustomerAddressController::class, 'index']);
    Route::post('/customer/addresses', [\App\Domain\CustomerAccount\Http\Controllers\CustomerAddressController::class, 'store']);
    Route::patch('/customer/addresses/{address}', [\App\Domain\CustomerAccount\Http\Controllers\CustomerAddressController::class, 'update']);
    Route::delete('/customer/addresses/{address}', [\App\Domain\CustomerAccount\Http\Controllers\CustomerAddressController::class, 'destroy']);
    Route::post('/customer/addresses/{address}/default', [\App\Domain\CustomerAccount\Http\Controllers\CustomerAddressController::class, 'makeDefault']);
    Route::get('/customer/orders', [\App\Domain\CustomerAccount\Http\Controllers\CustomerOrderController::class, 'index']);
    Route::get('/customer/orders/{orderPublicId}', [\App\Domain\CustomerAccount\Http\Controllers\CustomerOrderController::class, 'show']);
    // Module 09 §45 (Phase B33): the customer's own returns.
    Route::get('/customer/orders/{orderPublicId}/returnable', [\App\Domain\Returns\Http\Controllers\CustomerReturnController::class, 'returnable']);
    Route::post('/customer/orders/{orderPublicId}/returns', [\App\Domain\Returns\Http\Controllers\CustomerReturnController::class, 'store'])->middleware('throttle:10,10,customer-returns-create');
    Route::get('/customer/returns/{returnPublicId}', [\App\Domain\Returns\Http\Controllers\CustomerReturnController::class, 'show']);
    Route::post('/customer/returns/{returnPublicId}/cancel', [\App\Domain\Returns\Http\Controllers\CustomerReturnController::class, 'cancel']);
    Route::post('/customer/returns/{returnPublicId}/shipped', [\App\Domain\Returns\Http\Controllers\CustomerReturnController::class, 'shipped']);

    // --- Support (Module 34, Phase B26) — the customer's own requests ---
    Route::get('/customer/support/tickets', [\App\Domain\Support\Http\Controllers\CustomerSupportController::class, 'index']);
    Route::post('/customer/support/tickets', [\App\Domain\Support\Http\Controllers\CustomerSupportController::class, 'store'])->middleware('throttle:10,1,support-open');
    Route::get('/customer/support/tickets/{ticket}', [\App\Domain\Support\Http\Controllers\CustomerSupportController::class, 'show']);
    Route::post('/customer/support/tickets/{ticket}/messages', [\App\Domain\Support\Http\Controllers\CustomerSupportController::class, 'reply'])->middleware('throttle:30,1,support-reply');
    Route::post('/customer/support/tickets/{ticket}/resolve', [\App\Domain\Support\Http\Controllers\CustomerSupportController::class, 'resolve']);
    Route::post('/customer/support/tickets/{ticket}/rating', [\App\Domain\Support\Http\Controllers\CustomerSupportController::class, 'rate']);

    // --- Wishlist (Module 11 §64-71 — authenticated customers only) ---
    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{item}', [WishlistController::class, 'destroy']);
    Route::post('/wishlist/{item}/move-to-cart', [WishlistController::class, 'moveToCart']);

    // --- Customer order tracking (Module 13 §74, Phase B8) ---
    Route::get('/customer/orders/{orderPublicId}/shipments', [CustomerShipmentController::class, 'index']);

    // --- Notifications (Module 21, Phase B11) ---
    Route::get('/customer/notifications', [CustomerNotificationController::class, 'index']);
    Route::post('/customer/notifications/{message}/read', [CustomerNotificationController::class, 'markRead']);
    Route::post('/customer/notifications/read-all', [CustomerNotificationController::class, 'markAllRead']);
    Route::patch('/customer/notification-preferences', [CustomerNotificationController::class, 'updatePreferences']);
});

// --- Cart & Checkout (Module 11 §6/§60-61 — guest AND authenticated
// customer both allowed; ownership resolved per-request, see
// CartService::resolveForRequest()). `customer.optional` resolves a
// valid Customer bearer token WITHOUT aborting when none is present —
// unlike `auth:customer`/`customer.principal`, which correctly enforce
// authentication on the customer-only Wishlist group above, but would
// incorrectly reject every guest here. ---
Route::middleware(['customer.optional'])->group(function () {
    Route::get('/cart', [CartController::class, 'show']);
    Route::post('/cart/items', [CartController::class, 'addItem']);
    Route::put('/cart/items/{item}', [CartController::class, 'updateItem']);
    Route::delete('/cart/items/{item}', [CartController::class, 'removeItem']);
    Route::post('/cart/coupon', [CartController::class, 'applyCoupon']);
    Route::delete('/cart/coupon', [CartController::class, 'removeCoupon']);

    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1');

    // --- Shipping quote (Module 13 §81-82 — guest AND authenticated
    // customer both allowed, same as Cart/Checkout) ---
    Route::get('/shipping/quote', [ShippingQuoteController::class, 'index']);
});

// --- Storefront API (Module 05, Phase B24) ---
// Public catalog for headless/mobile storefronts. The store comes from
// the verified Host domain, X-Store-Slug, or the signed-in customer; a
// closed store answers 503 (ResolveStorefrontStore).
Route::middleware(['customer.optional', 'storefront.store:api', 'throttle:120,1'])
    ->prefix('storefront')
    ->group(function () {
        $controller = \App\Domain\Storefront\Http\Controllers\StorefrontApiController::class;
        Route::get('/', [$controller, 'show']);
        Route::get('/products', [$controller, 'products']);
        Route::get('/products/{slug}', [$controller, 'product']);
        Route::get('/categories', [$controller, 'categories']);
        Route::get('/categories/{slug}', [$controller, 'category']);
        Route::get('/brands', [$controller, 'brands']);
        Route::get('/brands/{slug}', [$controller, 'brand']);
        Route::get('/pages/{slug}', [$controller, 'page']);
        Route::get('/search/suggest', [$controller, 'suggest']);

        // Phase B25: web storefront sign-in (HttpOnly cookie session) and password reset.
        // Each limiter has its own key prefix: nested unprefixed throttles
        // share one counter per IP, so the group's 120/min would also be
        // spent (and doubled) against these stricter limits.
        $session = \App\Domain\CustomerAccount\Http\Controllers\StorefrontSessionController::class;
        Route::post('/session', [$session, 'login'])->middleware('throttle:10,1,sf-login');
        Route::post('/session/register', [$session, 'register'])->middleware('throttle:5,1,sf-register');
        Route::delete('/session', [$session, 'logout'])->middleware(['auth:customer', 'customer.principal']);
        $reset = \App\Domain\CustomerAccount\Http\Controllers\CustomerPasswordResetController::class;
        Route::post('/password/forgot', [$reset, 'forgot'])->middleware('throttle:5,1,sf-forgot');
        Route::post('/password/reset', [$reset, 'reset'])->middleware('throttle:10,1,sf-reset');

        // Module 34 (Phase B26): contact form and a guest's private ticket link.
        $support = \App\Domain\Support\Http\Controllers\GuestSupportController::class;
        Route::post('/support/contact', [$support, 'contact'])->middleware('throttle:3,1,sf-contact');
        Route::get('/support/tickets/{ticket}', [$support, 'show'])->middleware('throttle:30,1,sf-ticket');
        Route::post('/support/tickets/{ticket}/messages', [$support, 'reply'])->middleware('throttle:10,1,sf-ticket-reply');
        Route::post('/support/tickets/{ticket}/resolve', [$support, 'resolve'])->middleware('throttle:10,1,sf-ticket-reply');
        Route::post('/support/tickets/{ticket}/rating', [$support, 'rate'])->middleware('throttle:10,1,sf-ticket-reply');
    });
