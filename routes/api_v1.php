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
// The second step of a sign-in for accounts with MFA (Module 32 §8 — Phase B29).
Route::post('/auth/login/mfa', [AuthController::class, 'loginMfa'])->middleware('throttle:10,1');

// 'required.mfa': a Store Owner without MFA reaches only its own /auth/*
// endpoints here, so it can enrol (owner decision 2026-10-01).
Route::middleware(['auth:sanctum', 'staff.principal', 'required.mfa'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // --- The signed-in user's own MFA and step-up (Module 32 §8, Module 30 §6 — Phase B29) ---
    Route::get('/auth/mfa', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'show']);
    Route::post('/auth/mfa/setup', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'setup'])->middleware('throttle:10,1');
    Route::post('/auth/mfa/confirm', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'confirm'])->middleware('throttle:10,1');
    Route::post('/auth/mfa/recovery-codes', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'regenerateRecoveryCodes'])->middleware('throttle:10,1');
    Route::delete('/auth/mfa', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'destroy'])->middleware('throttle:10,1');
    Route::post('/auth/step-up', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'stepUp'])->middleware('throttle:10,1');

    // --- Tenant switching ---
    Route::post('/store/switch', StoreSwitchController::class);

    // --- Roles (first concrete tenant-owned resource CRUD — B1 scope) ---
    Route::get('/permissions', [RoleController::class, 'permissions']); // Phase B31: the catalog for the role editor
    Route::apiResource('roles', RoleController::class);

    // --- Team: invitations and members (Module 02 §18–19 — Phase G1) ---
    Route::get('/team/summary', [\App\Domain\Identity\Http\Controllers\TeamController::class, 'summary']);
    Route::get('/team/members', [\App\Domain\Identity\Http\Controllers\TeamController::class, 'members']);
    Route::patch('/team/members/{member}', [\App\Domain\Identity\Http\Controllers\TeamController::class, 'changeRole'])->where('member', '[0-9A-Za-z]{26}');
    Route::post('/team/members/{member}/suspend', [\App\Domain\Identity\Http\Controllers\TeamController::class, 'suspend'])->where('member', '[0-9A-Za-z]{26}');
    Route::post('/team/members/{member}/reactivate', [\App\Domain\Identity\Http\Controllers\TeamController::class, 'reactivate'])->where('member', '[0-9A-Za-z]{26}');
    Route::delete('/team/members/{member}', [\App\Domain\Identity\Http\Controllers\TeamController::class, 'remove'])->where('member', '[0-9A-Za-z]{26}');
    Route::get('/team/invitations', [\App\Domain\Identity\Http\Controllers\TeamController::class, 'invitations']);
    Route::post('/team/invitations', [\App\Domain\Identity\Http\Controllers\TeamController::class, 'invite'])->middleware('throttle:30,1');
    Route::post('/team/invitations/{invitation}/resend', [\App\Domain\Identity\Http\Controllers\TeamController::class, 'resend'])->middleware('throttle:30,1');
    Route::delete('/team/invitations/{invitation}', [\App\Domain\Identity\Http\Controllers\TeamController::class, 'revoke']);

    // --- Subscription / Usage (Module 04 §40-41 — the store's own only) ---
    Route::get('/subscription', [SubscriptionController::class, 'show']);
    Route::get('/subscription/usage', [SubscriptionController::class, 'usage']);

    // --- Support (Module 34, Phase B26) ---
    // The store's inbox for its shoppers' requests...
    Route::get('/support/tickets', [\App\Domain\Support\Http\Controllers\StoreSupportController::class, 'index']);
    Route::get('/support/summary', [\App\Domain\Support\Http\Controllers\StoreSupportController::class, 'summary']);
    Route::get('/support/agents', [\App\Domain\Support\Http\Controllers\StoreSupportController::class, 'agentsList']);
    Route::get('/support/tickets/{ticket}', [\App\Domain\Support\Http\Controllers\StoreSupportController::class, 'show']);
    Route::patch('/support/tickets/{ticket}', [\App\Domain\Support\Http\Controllers\StoreSupportController::class, 'update']);
    Route::post('/support/tickets/{ticket}/messages', [\App\Domain\Support\Http\Controllers\StoreSupportController::class, 'reply']);
    // ...and the store's own requests to the platform's support team.
    Route::get('/platform-support/tickets', [\App\Domain\Support\Http\Controllers\MerchantPlatformSupportController::class, 'index']);
    Route::post('/platform-support/tickets', [\App\Domain\Support\Http\Controllers\MerchantPlatformSupportController::class, 'store'])->middleware('throttle:10,1,platform-support-open');
    Route::get('/platform-support/tickets/{ticket}', [\App\Domain\Support\Http\Controllers\MerchantPlatformSupportController::class, 'show']);
    Route::post('/platform-support/tickets/{ticket}/messages', [\App\Domain\Support\Http\Controllers\MerchantPlatformSupportController::class, 'reply']);
    Route::post('/platform-support/tickets/{ticket}/resolve', [\App\Domain\Support\Http\Controllers\MerchantPlatformSupportController::class, 'resolve']);
    Route::post('/platform-support/tickets/{ticket}/rating', [\App\Domain\Support\Http\Controllers\MerchantPlatformSupportController::class, 'rate']);

    // --- Storefront setup & launch (Module 05, Phase B24) ---
    Route::get('/storefront/setup', [\App\Domain\Storefront\Http\Controllers\StorefrontSetupController::class, 'show']);
    Route::post('/storefront/launch', [\App\Domain\Storefront\Http\Controllers\StorefrontSetupController::class, 'launch']);

    // --- Billing, Invoices & Renewals (Module 29, Phase B23) — the store's own only ---
    Route::get('/billing', [BillingController::class, 'show']);
    Route::get('/billing/invoices', [BillingController::class, 'invoices']);
    Route::post('/billing/first-invoice', [BillingController::class, 'firstInvoice'])->middleware('throttle:10,10,first-invoice'); // Phase B44
    Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice']);
    Route::post('/billing/cancel', [BillingController::class, 'cancel']);
    Route::post('/billing/resume', [BillingController::class, 'resume']);
    Route::put('/billing/interval', [BillingController::class, 'changeInterval']);

    // --- Catalog (Modules 06-07, Phase B3) ---
    // Phase B40: before the resource, so "export" is not read as a product id.
    Route::get('/products/export', [\App\Domain\Catalog\Http\Controllers\ProductTransferController::class, 'export'])->middleware('throttle:10,10,products-export');
    Route::apiResource('products', ProductController::class);
    // Phase B38 (Module 06 §101, Module 07 §99): translations of catalog content.
    Route::get('/translations/{type}/{id}', [\App\Domain\Settings\Http\Controllers\TranslationController::class, 'show'])->whereIn('type', ['product', 'category', 'brand', 'collection', 'badge']);
    Route::put('/translations/{type}/{id}', [\App\Domain\Settings\Http\Controllers\TranslationController::class, 'update'])->whereIn('type', ['product', 'category', 'brand', 'collection', 'badge'])->middleware('throttle:60,1,translations');
    // Phase B24: storefront images of a product.
    Route::get('/products/{product}/images', [\App\Domain\Catalog\Http\Controllers\ProductImageController::class, 'index']);
    Route::post('/products/{product}/images', [\App\Domain\Catalog\Http\Controllers\ProductImageController::class, 'store']);
    Route::put('/products/{product}/images/order', [\App\Domain\Catalog\Http\Controllers\ProductImageController::class, 'reorder']);
    Route::delete('/products/{product}/images/{image}', [\App\Domain\Catalog\Http\Controllers\ProductImageController::class, 'destroy']);
    Route::apiResource('products.variants', ProductVariantController::class)
        ->except(['show']);
    Route::apiResource('categories', CategoryController::class)->except(['show']);
    Route::apiResource('brands', BrandController::class)->except(['show']);
    Route::apiResource('attributes', AttributeController::class)->only(['index', 'store', 'destroy']);
    // Phase B41 (Module 07 §18–19, §27–45): attributes, sets, category attributes, specifications.
    $taxonomy = \App\Domain\Catalog\Http\Controllers\TaxonomyController::class;
    Route::put('/attributes/{attribute}', [$taxonomy, 'updateAttribute']);
    // Phase B42 (Module 07 §38): an attribute's name and values in other languages, together.
    Route::get('/attributes/{attribute}/translations', [\App\Domain\Catalog\Http\Controllers\AttributeTranslationController::class, 'show']);
    Route::put('/attributes/{attribute}/translations', [\App\Domain\Catalog\Http\Controllers\AttributeTranslationController::class, 'update'])->middleware('throttle:60,1,translations');
    Route::get('/attribute-sets', [$taxonomy, 'sets']);
    Route::post('/attribute-sets', [$taxonomy, 'saveSet']);
    Route::put('/attribute-sets/{set}', [$taxonomy, 'updateSet']);
    Route::delete('/attribute-sets/{set}', [$taxonomy, 'destroySet']);
    Route::get('/categories/{category}/attributes', [$taxonomy, 'categoryAttributes']);
    Route::put('/categories/{category}/attributes', [$taxonomy, 'saveCategoryAttributes']);
    Route::get('/products/{product}/specifications', [$taxonomy, 'specifications']);
    Route::put('/products/{product}/specifications', [$taxonomy, 'saveSpecifications']);
    // Phase B39 (gap G15): collections, tags, relations, duplicate and bulk changes.
    Route::apiResource('collections', \App\Domain\Catalog\Http\Controllers\CollectionController::class);
    Route::put('/collections/{collection}/products', [\App\Domain\Catalog\Http\Controllers\CollectionController::class, 'products']);
    Route::get('/tags', [\App\Domain\Catalog\Http\Controllers\ProductToolsController::class, 'tags']);
    // Phase B43 (Module 06 §36): the store's own badges.
    Route::apiResource('badges', \App\Domain\Catalog\Http\Controllers\BadgeController::class)->except(['show']);
    // Phase B40 (Module 06 §48–51): CSV import (preview, then confirm) and export.
    Route::post('/products/import', [\App\Domain\Catalog\Http\Controllers\ProductTransferController::class, 'preview'])->middleware('throttle:10,10,products-import');
    Route::post('/products/import/{import}/confirm', [\App\Domain\Catalog\Http\Controllers\ProductTransferController::class, 'confirm'])->where('import', '[0-9A-Za-z]{26}')->middleware('throttle:10,10,products-import-confirm');
    Route::post('/products/bulk', [\App\Domain\Catalog\Http\Controllers\ProductToolsController::class, 'bulk'])->middleware('throttle:20,1,products-bulk');
    Route::post('/products/{product}/duplicate', [\App\Domain\Catalog\Http\Controllers\ProductToolsController::class, 'duplicate'])->middleware('throttle:30,1,products-duplicate');
    Route::get('/products/{product}/relations', [\App\Domain\Catalog\Http\Controllers\ProductToolsController::class, 'relations']);
    Route::put('/products/{product}/relations', [\App\Domain\Catalog\Http\Controllers\ProductToolsController::class, 'saveRelations']);

    // --- Inventory & Warehouses (Module 08, Phase B4) ---
    Route::apiResource('warehouses', WarehouseController::class)->only(['index', 'store', 'update']);
    Route::apiResource('inventory', InventoryController::class)->only(['index', 'show', 'store']);
    Route::post('/inventory/{inventory}/adjust', [InventoryController::class, 'adjust']);
    Route::post('/inventory/{inventory}/opening-stock', [InventoryController::class, 'openingStock']);
    // Module 08 §47 (Phase B34): the damaged balance.
    Route::post('/inventory/{inventory}/damaged', [InventoryController::class, 'markDamaged']);
    Route::post('/inventory/{inventory}/damaged/write-off', [InventoryController::class, 'writeOffDamaged']);
    Route::get('/inventory/{inventory}/movements', [InventoryController::class, 'movements']);
    Route::post('/inventory/{inventory}/reservations', [ReservationController::class, 'store']);
    Route::post('/reservations/{reservation}/release', [ReservationController::class, 'release']);

    // --- Orders (Module 09, Phase B5) ---
    Route::apiResource('orders', OrderController::class)->only(['index', 'show', 'store']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::get('/orders/{order}/timeline', [OrderController::class, 'timeline']);

    // --- Returns, inspection, refunds, replacement orders (Module 09 §45–54, Phase B33 — gap G8) ---
    Route::get('/orders/{order}/returnable', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'returnable']);
    Route::post('/orders/{order}/returns', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'store'])->middleware('throttle:30,1,returns-create');
    Route::get('/returns', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'index']);
    Route::get('/returns/{return}', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'show']);
    Route::post('/returns/{return}/review', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'review']);
    Route::post('/returns/{return}/approve', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'approve']);
    Route::post('/returns/{return}/reject', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'reject']);
    Route::post('/returns/{return}/cancel', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'cancel']);
    Route::post('/returns/{return}/in-transit', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'inTransit']);
    Route::post('/returns/{return}/receive', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'receive']);
    Route::post('/returns/{return}/inspect', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'inspect']);
    Route::post('/returns/{return}/approve-refund', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'approveRefund']);
    Route::post('/returns/{return}/refund', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'refund']);
    Route::post('/returns/{return}/replacement', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'replacement']);
    // Photos (Phase B34): private files, served only through these routes.
    Route::post('/returns/{return}/photos', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'addPhoto'])->middleware('throttle:30,1,returns-photos');
    Route::get('/returns/{return}/photos/{photo}', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'photo'])->where('photo', '[0-9A-Za-z]{26}');
    Route::delete('/returns/{return}/photos/{photo}', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'deletePhoto'])->where('photo', '[0-9A-Za-z]{26}');

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
    Route::get('/shipping/rates', [ShippingConfigController::class, 'rates']); // Phase B31
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
    // --- Customers (Module 10, Phase B32 — gap G7). Fixed paths before {customer}. ---
    Route::get('/customers', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'index'])->middleware('throttle:120,1,customers-list');
    Route::post('/customers', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'store']);
    Route::get('/customers/export', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'export'])->middleware('throttle:5,10,customers-export');
    Route::post('/customers/import', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'previewImport'])->middleware('throttle:10,10,customers-import');
    Route::post('/customers/import/{import}/confirm', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'confirmImport'])->where('import', '[0-9A-Za-z]{26}')->middleware('throttle:10,10,customers-import-confirm');
    Route::get('/customer-groups', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'groups']);
    Route::post('/customer-groups', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'storeGroup']);
    Route::patch('/customer-groups/{group}', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'updateGroup']);
    Route::delete('/customer-groups/{group}', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'deleteGroup']);
    Route::get('/customer-tags', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'tags']);
    Route::patch('/customer-tags/{tag}', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'renameTag']);
    Route::delete('/customer-tags/{tag}', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'deleteTag']);
    Route::get('/customers/{customer}', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'show']);
    Route::patch('/customers/{customer}', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'update']);
    Route::put('/customers/{customer}/tags', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'tags']);
    Route::post('/customers/{customer}/block', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'block']);
    Route::post('/customers/{customer}/archive', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'archive']);
    Route::post('/customers/{customer}/reactivate', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'reactivate']);
    // Module 10 §56: explicit, audited, the password asked again.
    // Module 09 §52 (Phase B34): store credit. Giving or taking it by hand asks for the password again.
    Route::get('/customers/{customer}/store-credit', [\App\Domain\StoreCredit\Http\Controllers\StoreCreditController::class, 'show']);
    Route::post('/customers/{customer}/store-credit/adjust', [\App\Domain\StoreCredit\Http\Controllers\StoreCreditController::class, 'adjust'])->middleware(['step_up', 'throttle:20,10,store-credit-adjust']);
    Route::post('/customers/{customer}/merge', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'merge'])->middleware(['step_up', 'throttle:10,10,customers-merge']);
    Route::get('/customers/{customer}/activity', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'activity']);
    Route::get('/customers/{customer}/notes', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'notes']);
    Route::post('/customers/{customer}/notes', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'addNote']);
    Route::delete('/customers/{customer}/notes/{note}', [\App\Domain\Customers\Http\Controllers\CustomerRecordsController::class, 'deleteNote']);

    Route::get('/customers/{customer}/personal-data', [CustomerPrivacyController::class, 'export']);
    // Irreversible: the password again (step-up), Phase B32.
    Route::post('/customers/{customer}/erase', [CustomerPrivacyController::class, 'erase'])->middleware('step_up');

    Route::get('/store/health', [StoreHealthController::class, 'show']);
    Route::get('/store/health/history', [StoreHealthController::class, 'history']);

    Route::get('/store/theme', [StoreThemeController::class, 'show']);
    // Module 17 §18 (Phase B36): the theme library and choosing a theme for the draft.
    Route::get('/store/themes', [StoreThemeController::class, 'library']);
    Route::post('/store/theme/select', [StoreThemeController::class, 'select'])->middleware('throttle:30,1,theme-select');
    // Phase B37 (Module 17 §6–7): logo, favicon, banner and sharing images as JPG/PNG uploads.
    Route::post('/store/media', [\App\Domain\Theme\Http\Controllers\StoreMediaController::class, 'store'])->middleware('throttle:30,1,store-media');
    Route::put('/store/theme/draft', [StoreThemeController::class, 'updateDraft']);
    Route::post('/store/theme/publish', [StoreThemeController::class, 'publish']);
    Route::post('/store/theme/preview', [StoreThemeController::class, 'previewLink'])->middleware('throttle:20,1,theme-preview'); // Phase B32
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
    Route::post('/backups', [BackupController::class, 'store'])->middleware('throttle:6,60'); // a full dump each time
    Route::post('/backups/{backup}/restore-request', [BackupController::class, 'requestRestore'])->middleware('step_up');

    // --- Super Admin: platform-global actions (no target store — Phase
    // B16 fix, see docs/development/b16-inspection-findings.md
    // "Critical Bug Found") ---
    Route::middleware(['can:super-admin.platform', 'privileged.mfa', 'super_admin.platform'])
        ->prefix('super-admin')
        ->group(function () {
            Route::get('/packages', [SuperAdminPackageController::class, 'index']);
            Route::post('/packages', [SuperAdminPackageController::class, 'store'])->middleware('step_up');
            Route::put('/packages/{package}', [SuperAdminPackageController::class, 'update'])->middleware('step_up');
            // Phase B32 (Module 04 §63): what a package includes.
            Route::put('/packages/{package}/entitlements', [\App\Domain\SuperAdmin\Http\Controllers\SuperAdminPackageEntitlementController::class, 'update'])->middleware('step_up');

            Route::get('/themes', [SuperAdminThemeController::class, 'index']);
            Route::post('/themes', [SuperAdminThemeController::class, 'store']);
            Route::put('/themes/{theme}', [SuperAdminThemeController::class, 'update']);

            Route::get('/dashboard', [SuperAdminDashboardController::class, 'show']);
            Route::get('/stores', [SuperAdminStoreController::class, 'index']);
            // Phase B44 (owner decision 13): Umar Techy creates a store for a customer.
            Route::post('/stores', [SuperAdminStoreController::class, 'store'])->middleware(['step_up', 'throttle:30,10,super-admin-store-create']);
            Route::get('/business-categories', [SuperAdminStoreController::class, 'businessCategories']);
            Route::get('/users', [SuperAdminUserController::class, 'index']);
            Route::get('/users/{user}', [SuperAdminUserController::class, 'show']);
            Route::post('/users/{user}/deactivate', [SuperAdminUserController::class, 'deactivate'])->middleware('step_up');
            Route::post('/users/{user}/reactivate', [SuperAdminUserController::class, 'reactivate'])->middleware('step_up');
            Route::get('/payments/failures', [SuperAdminPaymentController::class, 'failures']);
            Route::get('/notifications/failures', [SuperAdminNotificationController::class, 'failures']);
            Route::get('/domains', [SuperAdminDomainController::class, 'indexAll']);

            Route::get('/settings', [SuperAdminSettingController::class, 'index']);
            Route::put('/settings/{key}', [SuperAdminSettingController::class, 'update'])->middleware('step_up');
            Route::get('/settings/{key}/history', [SuperAdminSettingController::class, 'history']); // Phase B32
            Route::post('/settings/revisions/{revisionId}/rollback', [SuperAdminSettingController::class, 'rollback'])->whereNumber('revisionId')->middleware('step_up');

            Route::get('/developer/applications', [SuperAdminDeveloperPlatformController::class, 'index']);
            Route::post('/developer/applications/{application}/suspend', [SuperAdminDeveloperPlatformController::class, 'suspend'])->middleware('step_up');

            Route::get('/backups', [SuperAdminBackupController::class, 'index']);
            Route::post('/backups', [SuperAdminBackupController::class, 'storePlatformBackup']);
            Route::get('/restore-jobs', [SuperAdminBackupController::class, 'restoreJobs']);
            Route::post('/restore-jobs/{backupRestoreJob}/authorize', [SuperAdminBackupController::class, 'authorizeRestore'])->middleware('step_up');
            // Phase B30 (gap G4): backup status, integrity re-check, restore rehearsal.
            Route::get('/backups/summary', [SuperAdminBackupController::class, 'summary']);
            Route::post('/backups/{backup}/verify', [SuperAdminBackupController::class, 'verify'])->middleware('throttle:10,1');
            Route::post('/backups/{backup}/rehearse', [SuperAdminBackupController::class, 'rehearse'])->middleware('throttle:6,1');

            Route::get('/infrastructure/health', [SuperAdminInfrastructureController::class, 'health']);

            // Module 24 (Phase B21): platform-wide store health and the
            // ADR-004 §17 / ADR-005 §17 operational signals.
            Route::get('/store-health', [SuperAdminMonitoringController::class, 'storeHealthOverview']);

            // Module 32 (Phase B22): the platform-wide audit trail.
            Route::get('/audit-logs', [SuperAdminAuditController::class, 'index']);
            Route::get('/audit-logs/integrity', [SuperAdminAuditController::class, 'integrity']);
            Route::get('/monitoring/outbox', [SuperAdminMonitoringController::class, 'outbox']);

            // Module 34 (Phase B26): the platform's support inbox.
            Route::get('/support/tickets', [\App\Domain\SuperAdmin\Http\Controllers\SuperAdminSupportController::class, 'index']);
            Route::get('/support/summary', [\App\Domain\SuperAdmin\Http\Controllers\SuperAdminSupportController::class, 'summary']);
            Route::get('/support/agents', [\App\Domain\SuperAdmin\Http\Controllers\SuperAdminSupportController::class, 'agentsList']);
            Route::get('/support/tickets/{ticket}', [\App\Domain\SuperAdmin\Http\Controllers\SuperAdminSupportController::class, 'show']);
            Route::patch('/support/tickets/{ticket}', [\App\Domain\SuperAdmin\Http\Controllers\SuperAdminSupportController::class, 'update']);
            Route::post('/support/tickets/{ticket}/messages', [\App\Domain\SuperAdmin\Http\Controllers\SuperAdminSupportController::class, 'reply']);

            // Module 29 (Phase B23): platform billing.
            Route::get('/billing/summary', [SuperAdminBillingController::class, 'summary']);
            Route::get('/billing/prices', [SuperAdminBillingController::class, 'prices']);
            Route::post('/billing/prices', [SuperAdminBillingController::class, 'upsertPrice'])->middleware('step_up');
            Route::patch('/billing/prices/{price}', [SuperAdminBillingController::class, 'updatePrice'])->middleware('step_up');
            Route::get('/billing/invoices', [SuperAdminBillingController::class, 'invoices']);
            Route::get('/billing/invoices/{invoice}', [SuperAdminBillingController::class, 'invoice']);
            Route::post('/billing/invoices/{invoice}/payments', [SuperAdminBillingController::class, 'recordPayment'])->middleware('step_up');
            Route::post('/billing/invoices/{invoice}/void', [SuperAdminBillingController::class, 'void'])->middleware('step_up');
            Route::post('/billing/invoices/{invoice}/extend-due-date', [SuperAdminBillingController::class, 'extendDueDate'])->middleware('step_up');
            Route::get('/monitoring/api-usage', [SuperAdminMonitoringController::class, 'apiUsage']);
        });

    // --- Super Admin cross-tenant (ADR-001 Layer 7) ---
    Route::middleware(['can:super-admin.impersonate', 'privileged.mfa', 'super_admin.impersonate'])
        ->prefix('super-admin')
        ->group(function () {
            Route::get('/stores/{store}/impersonate', [SuperAdminStoreController::class, 'impersonate'])->middleware('step_up'); // SRS SA-004
            Route::get('/stores/{store}', [SuperAdminStoreController::class, 'show']);
            Route::post('/stores/{store}/owner-invitation', [SuperAdminStoreController::class, 'inviteOwner'])->middleware('step_up'); // Phase B44
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
