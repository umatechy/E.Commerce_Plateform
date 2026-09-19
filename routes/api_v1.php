<?php

use App\Domain\Identity\Http\Controllers\AuthController;
use App\Domain\Identity\Http\Controllers\RoleController;
use App\Domain\Catalog\Http\Controllers\AttributeController;
use App\Domain\Catalog\Http\Controllers\BrandController;
use App\Domain\Catalog\Http\Controllers\CategoryController;
use App\Domain\Catalog\Http\Controllers\ProductController;
use App\Domain\Catalog\Http\Controllers\ProductVariantController;
use App\Domain\Inventory\Http\Controllers\InventoryController;
use App\Domain\Inventory\Http\Controllers\ReservationController;
use App\Domain\Inventory\Http\Controllers\WarehouseController;
use App\Domain\Orders\Http\Controllers\OrderController;
use App\Domain\Packages\Http\Controllers\SubscriptionController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminPackageController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminStoreController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminSubscriptionController;
use App\Domain\Tenancy\Http\Controllers\StoreSwitchController;
use Illuminate\Support\Facades\Route;

// ADR-005: first-party API, /api/v1/... . Registered under the 'api'
// middleware group + ResolveTenantContext in bootstrap/app.php.

// --- Authentication (ADR-002 Surface A — Sanctum SPA session) ---
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'staff.principal'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // --- Tenant switching ---
    Route::post('/store/switch', StoreSwitchController::class);

    // --- Roles (first concrete tenant-owned resource CRUD — B1 scope) ---
    Route::apiResource('roles', RoleController::class);

    // --- Subscription / Usage (Module 04 §40-41 — the store's own only) ---
    Route::get('/subscription', [SubscriptionController::class, 'show']);
    Route::get('/subscription/usage', [SubscriptionController::class, 'usage']);

    // --- Catalog (Modules 06-07, Phase B3) ---
    Route::apiResource('products', ProductController::class);
    Route::apiResource('products.variants', ProductVariantController::class)
        ->except(['show']);
    Route::apiResource('categories', CategoryController::class)->except(['show']);
    Route::apiResource('brands', BrandController::class)->except(['show']);
    Route::apiResource('attributes', AttributeController::class)->only(['index', 'store', 'destroy']);

    // --- Inventory & Warehouses (Module 08, Phase B4) ---
    Route::apiResource('warehouses', WarehouseController::class)->only(['index', 'store', 'update']);
    Route::apiResource('inventory', InventoryController::class)->only(['index', 'show', 'store']);
    Route::post('/inventory/{inventory}/adjust', [InventoryController::class, 'adjust']);
    Route::post('/inventory/{inventory}/opening-stock', [InventoryController::class, 'openingStock']);
    Route::get('/inventory/{inventory}/movements', [InventoryController::class, 'movements']);
    Route::post('/inventory/{inventory}/reservations', [ReservationController::class, 'store']);
    Route::post('/reservations/{reservation}/release', [ReservationController::class, 'release']);

    // --- Orders (Module 09, Phase B5) ---
    Route::apiResource('orders', OrderController::class)->only(['index', 'show', 'store']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::get('/orders/{order}/timeline', [OrderController::class, 'timeline']);

    // --- Super Admin cross-tenant (ADR-001 Layer 7) ---
    Route::middleware(['can:super-admin.impersonate', 'super_admin.impersonate'])
        ->prefix('super-admin')
        ->group(function () {
            Route::get('/stores/{store}/impersonate', [SuperAdminStoreController::class, 'impersonate']);

            // Package/subscription platform administration (Module 04
            // "Package Administration" / "Subscription Administration").
            Route::get('/packages', [SuperAdminPackageController::class, 'index']);
            Route::post('/packages', [SuperAdminPackageController::class, 'store']);
            Route::put('/packages/{package}', [SuperAdminPackageController::class, 'update']);

            Route::post('/stores/{store}/subscription/change-package', [SuperAdminSubscriptionController::class, 'changePackage']);
            Route::post('/stores/{store}/subscription/suspend', [SuperAdminSubscriptionController::class, 'suspend']);
            Route::post('/stores/{store}/subscription/reactivate', [SuperAdminSubscriptionController::class, 'reactivate']);
        });
});
