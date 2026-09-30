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
use App\Domain\Domains\Http\Controllers\DomainController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminDomainController;
use App\Domain\Theme\Http\Controllers\StoreThemeController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminThemeController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminDashboardController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminUserController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminPaymentController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminNotificationController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminSettingController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminDeveloperPlatformController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminBackupController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminInfrastructureController;
use App\Domain\DataProtection\Http\Controllers\BackupController;
use App\Domain\Settings\Http\Controllers\StoreSettingController;
use App\Domain\DeveloperPlatform\Http\Controllers\DeveloperApplicationController;
use App\Domain\DeveloperPlatform\Http\Controllers\ApiKeyController;
use App\Domain\DeveloperPlatform\Http\Controllers\WebhookSubscriptionController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminAuditController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminMonitoringController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminPackageController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminStoreController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminSubscriptionController;
use App\Domain\Tenancy\Http\Controllers\StoreSwitchController;
use App\Domain\Compliance\Http\Controllers\AuditLogController;
use App\Domain\Compliance\Http\Controllers\CustomerPrivacyController;
use App\Domain\Billing\Http\Controllers\BillingController;
use App\Domain\SuperAdmin\Http\Controllers\SuperAdminBillingController;
use App\Domain\Monitoring\Http\Controllers\StoreHealthController;
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

    // --- Billing, Invoices & Renewals (Module 29, Phase B23) — the store's own only ---
    Route::get('/billing', [BillingController::class, 'show']);
    Route::get('/billing/invoices', [BillingController::class, 'invoices']);
    Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice']);
    Route::post('/billing/cancel', [BillingController::class, 'cancel']);
    Route::post('/billing/resume', [BillingController::class, 'resume']);
    Route::put('/billing/interval', [BillingController::class, 'changeInterval']);

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

    // --- Domain Management (Module 19, Phase B14) ---
    Route::get('/domains', [DomainController::class, 'index']);
    Route::post('/domains', [DomainController::class, 'store']);
    Route::post('/domains/{domain}/verification', [DomainController::class, 'initiateVerification']);
    Route::post('/domains/{domain}/verify', [DomainController::class, 'verify']);
    Route::post('/domains/{domain}/primary', [DomainController::class, 'setPrimary']);
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy']);

    // --- Theme, Branding & Design System (Module 17, Phase B15) ---
    // Module 24 "Store Health, Monitoring & Resource Usage" (Phase B21)
    // — always the current tenant's own store (ADR-001).
    // Module 32 "Security, Audit & Compliance" (Phase B22) — the store's
    // own tamper-evident audit trail and data-subject requests.
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/integrity', [AuditLogController::class, 'integrity']);
    Route::get('/customers/{customer}/personal-data', [CustomerPrivacyController::class, 'export']);
    Route::post('/customers/{customer}/erase', [CustomerPrivacyController::class, 'erase']);

    Route::get('/store/health', [StoreHealthController::class, 'show']);
    Route::get('/store/health/history', [StoreHealthController::class, 'history']);

    Route::get('/store/theme', [StoreThemeController::class, 'show']);
    Route::put('/store/theme/draft', [StoreThemeController::class, 'updateDraft']);
    Route::post('/store/theme/publish', [StoreThemeController::class, 'publish']);
    Route::get('/store/theme/publications', [StoreThemeController::class, 'publications']);
    Route::post('/store/theme/publications/{publicationId}/rollback', [StoreThemeController::class, 'rollback']);

    // --- System Settings & Configuration, store scope (Module 33, Phase B17) ---
    Route::get('/store/settings', [StoreSettingController::class, 'index']);
    Route::get('/store/settings/{key}', [StoreSettingController::class, 'show']);
    Route::put('/store/settings/{key}', [StoreSettingController::class, 'update']);
    Route::get('/store/settings/{key}/history', [StoreSettingController::class, 'history']);
    Route::post('/store/settings/revisions/{revisionId}/rollback', [StoreSettingController::class, 'rollback']);

    // --- Developer Platform, staff-facing management (Module 31, Phase B18) ---
    Route::get('/developer/applications', [DeveloperApplicationController::class, 'index']);
    Route::post('/developer/applications', [DeveloperApplicationController::class, 'store']);
    Route::post('/developer/applications/{developerApplication}/suspend', [DeveloperApplicationController::class, 'suspend']);
    Route::post('/developer/applications/{developerApplication}/reactivate', [DeveloperApplicationController::class, 'reactivate']);
    Route::delete('/developer/applications/{developerApplication}', [DeveloperApplicationController::class, 'revoke']);

    Route::get('/developer/applications/{application}/keys', [ApiKeyController::class, 'index']);
    Route::post('/developer/applications/{application}/keys', [ApiKeyController::class, 'store']);
    Route::delete('/developer/applications/{application}/keys/{apiKey}', [ApiKeyController::class, 'revoke']);
    Route::post('/developer/applications/{application}/keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate']);

    Route::get('/developer/applications/{application}/webhooks', [WebhookSubscriptionController::class, 'index']);
    Route::post('/developer/applications/{application}/webhooks', [WebhookSubscriptionController::class, 'store']);
    Route::post('/developer/applications/{application}/webhooks/{webhookSubscription}/disable', [WebhookSubscriptionController::class, 'disable']);

    // --- Backup, Restore & Data Protection, staff-facing (Module 23, Phase B19) ---
    Route::get('/backups', [BackupController::class, 'index']);
    Route::post('/backups', [BackupController::class, 'store']);
    Route::post('/backups/{backup}/restore-request', [BackupController::class, 'requestRestore']);

    // --- Super Admin: platform-global actions (no target store — Phase
    // B16 fix, see docs/development/b16-inspection-findings.md
    // "Critical Bug Found") ---
    Route::middleware(['can:super-admin.platform', 'super_admin.platform'])
        ->prefix('super-admin')
        ->group(function () {
            Route::get('/packages', [SuperAdminPackageController::class, 'index']);
            Route::post('/packages', [SuperAdminPackageController::class, 'store']);
            Route::put('/packages/{package}', [SuperAdminPackageController::class, 'update']);

            Route::get('/themes', [SuperAdminThemeController::class, 'index']);
            Route::post('/themes', [SuperAdminThemeController::class, 'store']);
            Route::put('/themes/{theme}', [SuperAdminThemeController::class, 'update']);

            Route::get('/dashboard', [SuperAdminDashboardController::class, 'show']);
            Route::get('/stores', [SuperAdminStoreController::class, 'index']);
            Route::get('/users', [SuperAdminUserController::class, 'index']);
            Route::get('/users/{user}', [SuperAdminUserController::class, 'show']);
            Route::post('/users/{user}/deactivate', [SuperAdminUserController::class, 'deactivate']);
            Route::post('/users/{user}/reactivate', [SuperAdminUserController::class, 'reactivate']);
            Route::get('/payments/failures', [SuperAdminPaymentController::class, 'failures']);
            Route::get('/notifications/failures', [SuperAdminNotificationController::class, 'failures']);
            Route::get('/domains', [SuperAdminDomainController::class, 'indexAll']);

            Route::get('/settings', [SuperAdminSettingController::class, 'index']);
            Route::put('/settings/{key}', [SuperAdminSettingController::class, 'update']);

            Route::get('/developer/applications', [SuperAdminDeveloperPlatformController::class, 'index']);
            Route::post('/developer/applications/{application}/suspend', [SuperAdminDeveloperPlatformController::class, 'suspend']);

            Route::get('/backups', [SuperAdminBackupController::class, 'index']);
            Route::post('/backups', [SuperAdminBackupController::class, 'storePlatformBackup']);
            Route::get('/restore-jobs', [SuperAdminBackupController::class, 'restoreJobs']);
            Route::post('/restore-jobs/{backupRestoreJob}/authorize', [SuperAdminBackupController::class, 'authorizeRestore']);

            Route::get('/infrastructure/health', [SuperAdminInfrastructureController::class, 'health']);

            // Module 24 (Phase B21): platform-wide store health and the
            // ADR-004 §17 / ADR-005 §17 operational signals.
            Route::get('/store-health', [SuperAdminMonitoringController::class, 'storeHealthOverview']);

            // Module 32 (Phase B22): the platform-wide audit trail.
            Route::get('/audit-logs', [SuperAdminAuditController::class, 'index']);
            Route::get('/audit-logs/integrity', [SuperAdminAuditController::class, 'integrity']);
            Route::get('/monitoring/outbox', [SuperAdminMonitoringController::class, 'outbox']);

            // Module 29 (Phase B23): platform billing.
            Route::get('/billing/summary', [SuperAdminBillingController::class, 'summary']);
            Route::get('/billing/prices', [SuperAdminBillingController::class, 'prices']);
            Route::post('/billing/prices', [SuperAdminBillingController::class, 'upsertPrice']);
            Route::patch('/billing/prices/{price}', [SuperAdminBillingController::class, 'updatePrice']);
            Route::get('/billing/invoices', [SuperAdminBillingController::class, 'invoices']);
            Route::get('/billing/invoices/{invoice}', [SuperAdminBillingController::class, 'invoice']);
            Route::post('/billing/invoices/{invoice}/payments', [SuperAdminBillingController::class, 'recordPayment']);
            Route::post('/billing/invoices/{invoice}/void', [SuperAdminBillingController::class, 'void']);
            Route::post('/billing/invoices/{invoice}/extend-due-date', [SuperAdminBillingController::class, 'extendDueDate']);
            Route::get('/monitoring/api-usage', [SuperAdminMonitoringController::class, 'apiUsage']);
        });

    // --- Super Admin cross-tenant (ADR-001 Layer 7) ---
    Route::middleware(['can:super-admin.impersonate', 'super_admin.impersonate'])
        ->prefix('super-admin')
        ->group(function () {
            Route::get('/stores/{store}/impersonate', [SuperAdminStoreController::class, 'impersonate']);
            Route::get('/stores/{store}', [SuperAdminStoreController::class, 'show']);
            Route::get('/stores/{store}/health', [SuperAdminMonitoringController::class, 'storeHealth']);

            // Package/subscription platform administration (Module 04
            // "Package Administration" / "Subscription Administration").
            Route::post('/stores/{store}/subscription/change-package', [SuperAdminSubscriptionController::class, 'changePackage']);
            Route::post('/stores/{store}/subscription/suspend', [SuperAdminSubscriptionController::class, 'suspend']);
            Route::post('/stores/{store}/subscription/reactivate', [SuperAdminSubscriptionController::class, 'reactivate']);

            // Module 19 §31 "Super Admin Domain Management" (Phase B14).
            Route::get('/stores/{store}/domains', [SuperAdminDomainController::class, 'index']);
            Route::post('/stores/{store}/domains/{domain}/suspend', [SuperAdminDomainController::class, 'suspend']);
            Route::post('/stores/{store}/domains/{domain}/reactivate', [SuperAdminDomainController::class, 'reactivate']);
        });
});
