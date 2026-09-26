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
use App\Domain\Payments\Http\Controllers\PaymentController;
use App\Domain\Shipping\Http\Controllers\ShipmentController;
use App\Domain\Shipping\Http\Controllers\ShippingConfigController;
use App\Domain\Promotions\Http\Controllers\PromotionController;
use App\Domain\Promotions\Http\Controllers\CouponController;
use App\Domain\Marketing\Http\Controllers\SegmentController;
use App\Domain\Marketing\Http\Controllers\CampaignController;
use App\Domain\Notifications\Http\Controllers\NotificationTemplateController;
use App\Domain\Notifications\Http\Controllers\NotificationMessageController;
use App\Domain\Analytics\Http\Controllers\DashboardController;
use App\Domain\Analytics\Http\Controllers\ReportController;
use App\Domain\Analytics\Http\Controllers\ReportExportController;
use App\Domain\Seo\Http\Controllers\SeoSettingController;
use App\Domain\Seo\Http\Controllers\ContentPageController;
use App\Domain\Seo\Http\Controllers\RedirectController;
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

    // --- Payments (Module 12, Phase B7) ---
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::get('/payments/{payment}/transactions', [PaymentController::class, 'transactions']);
    Route::post('/payments/{payment}/manual-confirm', [PaymentController::class, 'manualConfirm']);
    Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund']);

    // --- Shipments (Module 13, Phase B8) ---
    Route::get('/shipments', [ShipmentController::class, 'index']);
    Route::get('/shipments/{shipment}', [ShipmentController::class, 'show']);
    Route::post('/shipments', [ShipmentController::class, 'store']);
    Route::post('/shipments/{shipment}/status', [ShipmentController::class, 'updateStatus']);
    Route::get('/shipments/{shipment}/tracking-events', [ShipmentController::class, 'trackingEvents']);

    // --- Shipping configuration (Module 13 §5/§22, Phase B8) ---
    Route::get('/shipping/zones', [ShippingConfigController::class, 'zones']);
    Route::post('/shipping/zones', [ShippingConfigController::class, 'storeZone']);
    Route::get('/shipping/methods', [ShippingConfigController::class, 'methods']);
    Route::post('/shipping/methods', [ShippingConfigController::class, 'storeMethod']);
    Route::post('/shipping/rates', [ShippingConfigController::class, 'storeRate']);

    // --- Promotions & Coupons (Module 14, Phase B9) ---
    Route::get('/promotions', [PromotionController::class, 'index']);
    Route::get('/promotions/{promotion}', [PromotionController::class, 'show']);
    Route::post('/promotions', [PromotionController::class, 'store']);
    Route::put('/promotions/{promotion}', [PromotionController::class, 'update']);
    Route::get('/promotions/{promotion}/coupons', [CouponController::class, 'index']);
    Route::post('/coupons', [CouponController::class, 'store']);
    Route::delete('/coupons/{coupon}', [CouponController::class, 'destroy']);

    // --- Marketing & Customer Engagement (Module 15, Phase B10) ---
    Route::get('/marketing/segments', [SegmentController::class, 'index']);
    Route::post('/marketing/segments', [SegmentController::class, 'store']);
    Route::get('/marketing/segments/{segment}/preview', [SegmentController::class, 'preview']);
    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
    Route::post('/campaigns', [CampaignController::class, 'store']);
    Route::post('/campaigns/{campaign}/activate', [CampaignController::class, 'activate']);
    Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause']);
    Route::post('/campaigns/{campaign}/resume', [CampaignController::class, 'resume']);
    Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);
    Route::get('/campaigns/{campaign}/recipients', [CampaignController::class, 'recipients']);

    // --- Notifications & Communication (Module 21, Phase B11) ---
    Route::get('/notification-templates', [NotificationTemplateController::class, 'index']);
    Route::post('/notification-templates', [NotificationTemplateController::class, 'store']);
    Route::put('/notification-templates/{template}', [NotificationTemplateController::class, 'update']);
    Route::get('/notification-messages', [NotificationMessageController::class, 'index']);
    Route::get('/notification-messages/{message}/attempts', [NotificationMessageController::class, 'attempts']);

    // --- Reports, Analytics & Dashboard (Module 22, Phase B12) ---
    Route::get('/dashboard', [DashboardController::class, 'summary']);
    Route::get('/reports/sales', [ReportController::class, 'sales']);
    Route::get('/reports/products', [ReportController::class, 'products']);
    Route::get('/reports/customers', [ReportController::class, 'customers']);
    Route::get('/reports/payments', [ReportController::class, 'payments']);
    Route::get('/reports/shipping', [ReportController::class, 'shipping']);
    Route::get('/reports/promotions', [ReportController::class, 'promotions']);
    Route::get('/reports/marketing', [ReportController::class, 'marketing']);
    Route::get('/reports/notifications', [ReportController::class, 'notifications']);
    Route::get('/reports/inventory', [ReportController::class, 'inventory']);
    Route::post('/exports', [ReportExportController::class, 'store']);
    Route::get('/exports/{export}', [ReportExportController::class, 'show']);

    // --- SEO & Content Management (Module 16, Phase B13) ---
    Route::get('/seo-settings', [SeoSettingController::class, 'index']);
    Route::post('/seo-settings', [SeoSettingController::class, 'store']);
    Route::get('/content-pages', [ContentPageController::class, 'index']);
    Route::post('/content-pages', [ContentPageController::class, 'store']);
    Route::put('/content-pages/{page}', [ContentPageController::class, 'update']);
    Route::post('/content-pages/{page}/transition', [ContentPageController::class, 'transition']);
    Route::get('/redirects', [RedirectController::class, 'index']);
    Route::post('/redirects', [RedirectController::class, 'store']);
    Route::delete('/redirects/{redirect}', [RedirectController::class, 'destroy']);

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
