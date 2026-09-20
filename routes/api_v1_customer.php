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

Route::middleware(['auth:customer', 'customer.principal'])->group(function () {
    Route::post('/customer/logout', [CustomerAuthController::class, 'logout']);
    Route::get('/customer/me', [CustomerAuthController::class, 'me']);

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
