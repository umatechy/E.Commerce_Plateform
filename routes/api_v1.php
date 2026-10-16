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
// Phase B58 (Module 28 §92): becoming an Umar Techy partner — apply, look up an invitation, join with a new account.
$partnerJoin = \App\Domain\Resellers\Http\Controllers\PartnerJoinController::class;
Route::post('/partner/applications', [$partnerJoin, 'apply'])->middleware('throttle:3,10,partner-apply');
Route::post('/partner/invitations/lookup', [$partnerJoin, 'invitation'])->middleware('throttle:20,1,partner-invitation');
Route::post('/partner/invitations/register', [$partnerJoin, 'register'])->middleware('throttle:5,1,partner-register');
// The second step of a sign-in for accounts with MFA (Module 32 §8 — Phase B29).
Route::post('/auth/login/mfa', [AuthController::class, 'loginMfa'])->middleware('throttle:10,1');
// Phase B52 (Module 32 §7.5–7.6): "forgot password" for staff accounts.
Route::post('/auth/password/forgot', [\App\Domain\Identity\Http\Controllers\StaffPasswordResetController::class, 'forgot'])->middleware('throttle:5,1,staff-forgot');
Route::post('/auth/password/reset', [\App\Domain\Identity\Http\Controllers\StaffPasswordResetController::class, 'reset'])->middleware('throttle:10,1,staff-reset');

// 'required.mfa': a Store Owner without MFA reaches only its own /auth/*
// endpoints here, so it can enrol (owner decision 2026-10-01).
// 'store.operational' (Phase B51): a suspended, closed or archived store is read-only.
// 'session.track' (Phase B52): a session ended elsewhere signs out here too.
Route::middleware(['auth:sanctum', 'session.track', 'staff.principal', 'required.mfa', 'store.operational'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // --- The signed-in user's own MFA and step-up (Module 32 §8, Module 30 §6 — Phase B29) ---
    Route::get('/auth/mfa', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'show']);
    Route::post('/auth/mfa/setup', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'setup'])->middleware('throttle:10,1');
    Route::post('/auth/mfa/confirm', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'confirm'])->middleware('throttle:10,1');
    Route::post('/auth/mfa/recovery-codes', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'regenerateRecoveryCodes'])->middleware('throttle:10,1');
    Route::delete('/auth/mfa', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'destroy'])->middleware('throttle:10,1');
    Route::post('/auth/step-up', [\App\Domain\Identity\Http\Controllers\MfaController::class, 'stepUp'])->middleware('throttle:10,1');
    // Phase B52 (SRS AUTH-005; Module 32 §7, §9): the person's own sessions and password.
    $accountSecurity = \App\Domain\Identity\Http\Controllers\AccountSecurityController::class;
    Route::get('/auth/sessions', [$accountSecurity, 'sessions']);
    Route::delete('/auth/sessions/{session}', [$accountSecurity, 'revoke'])->where('session', '[0-9A-Za-z]{26}');
    Route::post('/auth/sessions/revoke-others', [$accountSecurity, 'revokeOthers'])->middleware('throttle:10,1,sessions-revoke');
    Route::put('/auth/password', [$accountSecurity, 'changePassword'])->middleware('throttle:10,10,password-change');

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

    // --- Affiliate program (Module 28, Phase B57): affiliates.view / .manage / .payouts (AffiliatePolicy) ---
    $aff = \App\Domain\Affiliates\Http\Controllers\AffiliateAdminController::class;
    Route::get('/affiliates/overview', [$aff, 'overview']);
    Route::put('/affiliates/program', [$aff, 'saveProgram'])->middleware('throttle:30,1,affiliate-program');
    Route::post('/affiliates/program/activate', [$aff, 'activate']);
    Route::post('/affiliates/program/pause', [$aff, 'pause']);
    Route::get('/affiliates/commissions', [$aff, 'commissions']);
    Route::post('/affiliates/commissions/{commission}/decide', [$aff, 'decide']);
    Route::get('/affiliates/payouts', [$aff, 'payouts']);
    Route::post('/affiliates/payouts/{payout}/paid', [$aff, 'markPaid'])->middleware(['step_up', 'idempotent']);
    Route::post('/affiliates/payouts/{payout}/pay-to', [$aff, 'payTo'])->middleware('step_up');
    Route::post('/affiliates/payouts/{payout}/cancel', [$aff, 'cancelPayout']);
    Route::get('/affiliates', [$aff, 'affiliates']);
    Route::post('/affiliates', [$aff, 'store'])->middleware('throttle:60,10,affiliate-add');
    Route::put('/affiliates/{affiliate}', [$aff, 'update']);
    Route::post('/affiliates/{affiliate}/approve', [$aff, 'approve']);
    Route::post('/affiliates/{affiliate}/reject', [$aff, 'reject']);
    Route::post('/affiliates/{affiliate}/pause', [$aff, 'pauseAffiliate']);
    Route::post('/affiliates/{affiliate}/resume', [$aff, 'resumeAffiliate']);
    Route::post('/affiliates/{affiliate}/portal-link', [$aff, 'portalLink']);
    Route::put('/affiliates/{affiliate}/payout-details', [$aff, 'payoutDetails'])->middleware('step_up');
    Route::post('/affiliates/{affiliate}/payouts', [$aff, 'createPayout'])->middleware(['step_up', 'idempotent']);
    Route::get('/affiliates/{affiliate}/adjustments', [$aff, 'adjustments']);
    Route::post('/affiliates/{affiliate}/adjustments', [$aff, 'adjust'])->middleware(['step_up', 'idempotent']);

    // --- The partner portal (Module 28, Phase B58): the reseller of the signed-in account, with two-step sign-in ---
    Route::post('/partner/invitations/accept', [\App\Domain\Resellers\Http\Controllers\PartnerJoinController::class, 'accept'])->middleware('throttle:10,1,partner-accept');
    Route::middleware('reseller')->prefix('partner')->group(function () {
        $partner = \App\Domain\Resellers\Http\Controllers\PartnerController::class;
        Route::get('/overview', [$partner, 'overview']);
        Route::get('/stores', [$partner, 'stores']);
        Route::post('/stores', [$partner, 'provision'])->middleware('throttle:20,10,partner-provision');
        Route::get('/commissions', [$partner, 'commissions']);
        Route::get('/payouts', [$partner, 'payouts']);
        Route::get('/packages', [$partner, 'packages']);
        Route::put('/payout-details', [$partner, 'payoutDetails'])->middleware('step_up');
    });

    // --- Apps from the marketplace (Module 27, Phase B56): developer_platform.view to look, the Owner to change ---
    $apps = \App\Domain\Marketplace\Http\Controllers\StoreAppsController::class;
    Route::get('/apps', [$apps, 'index']);
    Route::get('/apps/{slug}', [$apps, 'show'])->where('slug', '[a-z0-9][a-z0-9\-]{2,59}');
    Route::post('/apps/{slug}/install', [$apps, 'install'])->where('slug', '[a-z0-9][a-z0-9\-]{2,59}')->middleware(['step_up', 'throttle:10,10,app-install']);
    Route::post('/apps/{slug}/connect', [$apps, 'connect'])->where('slug', '[a-z0-9][a-z0-9\-]{2,59}')->middleware(['step_up', 'throttle:10,10,app-connect']);
    Route::put('/apps/{slug}/settings', [$apps, 'settings'])->where('slug', '[a-z0-9][a-z0-9\-]{2,59}')->middleware('throttle:30,1,app-settings');
    Route::post('/apps/{slug}/disable', [$apps, 'disable'])->where('slug', '[a-z0-9][a-z0-9\-]{2,59}');
    Route::post('/apps/{slug}/enable', [$apps, 'enable'])->where('slug', '[a-z0-9][a-z0-9\-]{2,59}');
    Route::post('/apps/{slug}/update', [$apps, 'update'])->where('slug', '[a-z0-9][a-z0-9\-]{2,59}')->middleware('step_up');
    Route::delete('/apps/{slug}', [$apps, 'uninstall'])->where('slug', '[a-z0-9][a-z0-9\-]{2,59}')->middleware('step_up');

    // --- The store's installable app (Module 25, Phase B55): theme.view to read, theme.manage to change ---
    Route::get('/pwa/settings', [\App\Domain\Pwa\Http\Controllers\PwaSettingsController::class, 'show']);
    Route::put('/pwa/settings', [\App\Domain\Pwa\Http\Controllers\PwaSettingsController::class, 'update'])->middleware('throttle:30,1,pwa-settings');
    Route::post('/pwa/icon', [\App\Domain\Pwa\Http\Controllers\PwaSettingsController::class, 'icon'])->middleware('throttle:10,1,pwa-icon');

    // --- Help center (Module 34, Phase B54): what each reader may open is decided by HelpAudiencePolicy ---
    $help = \App\Domain\Help\Http\Controllers\HelpController::class;
    Route::get('/help', [$help, 'home']);
    Route::get('/help/search', [$help, 'search'])->middleware('throttle:60,1,help-search');
    Route::get('/help/page', [$help, 'page']);
    Route::get('/help/categories/{category}', [$help, 'category'])->where('category', '[a-z_]{2,30}');
    Route::get('/help/articles/{key}', [$help, 'show'])->where('key', '[a-z0-9][a-z0-9\-]{2,79}');
    Route::post('/help/articles/{key}/feedback', [$help, 'feedback'])->where('key', '[a-z0-9][a-z0-9\-]{2,79}')->middleware('throttle:20,10,help-feedback');
    Route::get('/help/releases', [$help, 'releases']);
    Route::post('/help/releases/seen', [$help, 'seen']);

    // --- Storefront setup & launch (Module 05, Phase B24) ---
    Route::get('/storefront/setup', [\App\Domain\Storefront\Http\Controllers\StorefrontSetupController::class, 'show']);
    Route::post('/storefront/launch', [\App\Domain\Storefront\Http\Controllers\StorefrontSetupController::class, 'launch']);

    // --- Phase B51 (Module 03 §35, §40; SRS STORE-009): Settings → Close store ---
    $closure = \App\Domain\Tenancy\Http\Controllers\StoreClosureController::class;
    Route::get('/store-lifecycle', [$closure, 'show']);
    Route::post('/store-lifecycle/close', [$closure, 'close'])->middleware(['step_up', 'throttle:5,10,store-close']);
    Route::post('/store-lifecycle/reopen', [$closure, 'reopen'])->middleware(['step_up', 'throttle:5,10,store-reopen']);
    Route::get('/store-lifecycle/export/{dataset}', [$closure, 'export'])->where('dataset', '[a-z-]{3,20}')->middleware('throttle:20,10,store-data-export');

    // --- Billing, Invoices & Renewals (Module 29, Phase B23) — the store's own only ---
    Route::get('/billing', [BillingController::class, 'show']);
    Route::get('/billing/invoices', [BillingController::class, 'invoices']);
    Route::post('/billing/first-invoice', [BillingController::class, 'firstInvoice'])->middleware('throttle:10,10,first-invoice'); // Phase B44
    Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice']);
    Route::post('/billing/cancel', [BillingController::class, 'cancel']);
    Route::post('/billing/resume', [BillingController::class, 'resume']);
    Route::put('/billing/interval', [BillingController::class, 'changeInterval']);
    // Phase B47 (Module 29 §43–49, §73–75): plan changes, account credit, credit notes, PDFs, payment notices.
    $account = \App\Domain\Billing\Http\Controllers\BillingAccountController::class;
    Route::get('/billing/plans', [$account, 'plans']);
    Route::get('/billing/plan-change/preview', [$account, 'preview']);
    Route::post('/billing/plan-change', [$account, 'change'])->middleware('throttle:10,10,plan-change');
    Route::delete('/billing/plan-change', [$account, 'cancelChange']);
    Route::get('/billing/credit', [$account, 'credit']);
    Route::get('/billing/credit-notes', [$account, 'creditNotes']);
    Route::get('/billing/credit-notes/{creditNote}/pdf', [$account, 'creditNotePdf'])->middleware('throttle:30,1,billing-pdf');
    Route::get('/billing/invoices/{invoice}/pdf', [$account, 'invoicePdf'])->middleware('throttle:30,1,billing-pdf');
    Route::get('/billing/payment-notices', [$account, 'notices']);
    Route::post('/billing/invoices/{invoice}/payment-notices', [$account, 'submitNotice'])->middleware(['idempotent', 'throttle:10,10,payment-notice']);
    // Phase B48: pay an invoice from JazzCash or Easypaisa into Umar Techy's account.
    Route::get('/billing/invoices/{invoice}/online-payment', [\App\Domain\Billing\Http\Controllers\BillingOnlinePaymentController::class, 'show'])->middleware('throttle:120,1,billing-online-status');
    Route::post('/billing/invoices/{invoice}/online-payment', [\App\Domain\Billing\Http\Controllers\BillingOnlinePaymentController::class, 'start'])->middleware('throttle:10,10,billing-online-pay');

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
    // Owner decision 15 (Module 05 §27): moderating product reviews (Business, Premium).
    $reviews = \App\Domain\Catalog\Http\Controllers\ReviewController::class;
    Route::get('/reviews', [$reviews, 'index']);
    Route::put('/reviews/{review}/status', [$reviews, 'status']);
    Route::put('/reviews/{review}/reply', [$reviews, 'reply']);
    Route::delete('/reviews/{review}', [$reviews, 'destroy']);
    // Phase B46 (gap G3, owner decision 1; Module 33 §29): the store's tax set-up — no rate is built in.
    $tax = \App\Domain\Tax\Http\Controllers\TaxController::class;
    Route::get('/tax', [$tax, 'show']);
    Route::put('/tax/settings', [$tax, 'updateSettings']);
    Route::post('/tax/classes', [$tax, 'storeClass']);
    Route::put('/tax/classes/{class}', [$tax, 'updateClass']);
    Route::delete('/tax/classes/{class}', [$tax, 'destroyClass']);
    Route::post('/tax/rates', [$tax, 'storeRate']);
    Route::put('/tax/rates/{rate}', [$tax, 'updateRate']);
    Route::delete('/tax/rates/{rate}', [$tax, 'destroyRate']);
    Route::post('/tax/preview', [$tax, 'preview'])->middleware('throttle:60,1,tax-preview');
    // Phase B45 (Module 07 §20, §105–106): starter templates for the store's business category.
    $starter = \App\Domain\Catalog\Http\Controllers\StarterTemplateController::class;
    Route::get('/starter-templates', [$starter, 'index']);
    Route::get('/starter-templates/{key}', [$starter, 'show'])->where('key', '[a-z0-9_]{1,40}');
    Route::post('/starter-templates/{key}/apply', [$starter, 'apply'])->where('key', '[a-z0-9_]{1,40}')->middleware('throttle:10,1,starter-template');
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
    Route::apiResource('orders', OrderController::class)->only(['index', 'show']);
    // Phase B53 (SRS API-007): 'idempotent' honours the Idempotency-Key header on retry-sensitive writes.
    Route::post('/orders', [OrderController::class, 'store'])->middleware('idempotent')->name('orders.store');
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
    Route::post('/returns/{return}/approve-refund', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'approveRefund'])->middleware('idempotent');
    Route::post('/returns/{return}/refund', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'refund'])->middleware('idempotent');
    Route::post('/returns/{return}/replacement', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'replacement']);
    // Photos (Phase B34): private files, served only through these routes.
    Route::post('/returns/{return}/photos', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'addPhoto'])->middleware('throttle:30,1,returns-photos');
    Route::get('/returns/{return}/photos/{photo}', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'photo'])->where('photo', '[0-9A-Za-z]{26}');
    Route::delete('/returns/{return}/photos/{photo}', [\App\Domain\Returns\Http\Controllers\ReturnController::class, 'deletePhoto'])->where('photo', '[0-9A-Za-z]{26}');

    // --- Payments (Module 12, Phase B7) ---
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::get('/payments/{payment}/transactions', [PaymentController::class, 'transactions']);
    Route::post('/payments/{payment}/manual-confirm', [PaymentController::class, 'manualConfirm'])->middleware('idempotent');
    Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])->middleware('idempotent');
    // Phase B48 (gap G9): reconciliation findings, and the store's payment settings. Bank details and
    // merchant accounts decide where the money goes: those changes need a fresh password (step-up).
    Route::get('/payment-reconciliation', [\App\Domain\Payments\Http\Controllers\PaymentReconciliationController::class, 'index']);
    Route::post('/payment-reconciliation/{item}/resolve', [\App\Domain\Payments\Http\Controllers\PaymentReconciliationController::class, 'resolve'])->where('item', '[0-9A-Za-z]{26}');
    $paymentSettings = \App\Domain\Payments\Http\Controllers\PaymentSettingsController::class;
    Route::get('/payment-settings', [$paymentSettings, 'show']);
    Route::put('/payment-settings/cod', [$paymentSettings, 'updateCod']);
    Route::put('/payment-settings/bank-transfer', [$paymentSettings, 'updateBankTransfer'])->middleware('step_up');
    Route::put('/payment-settings/providers/{provider}', [$paymentSettings, 'saveProvider'])->where('provider', '[a-z]{3,20}')->middleware(['step_up', 'throttle:20,10,payment-provider-save']);
    Route::put('/payment-settings/providers/{provider}/enabled', [$paymentSettings, 'setEnabled'])->where('provider', '[a-z]{3,20}')->middleware('step_up');
    Route::delete('/payment-settings/providers/{provider}', [$paymentSettings, 'disconnect'])->where('provider', '[a-z]{3,20}')->middleware('step_up');
    Route::post('/payment-settings/providers/{provider}/test', [$paymentSettings, 'test'])->where('provider', '[a-z]{3,20}')->middleware('throttle:10,10,payment-provider-test');
    Route::get('/payment-attempts/{attempt}', [$paymentSettings, 'attempt'])->where('attempt', '[0-9A-Za-z]{26}')->middleware('throttle:120,1,payment-attempt-status');

    // --- Shipments (Module 13, Phase B8) ---
    Route::get('/shipments', [ShipmentController::class, 'index']);
    Route::get('/shipments/{shipment}', [ShipmentController::class, 'show']);
    Route::post('/shipments', [ShipmentController::class, 'store'])->middleware('idempotent');
    Route::post('/shipments/{shipment}/status', [ShipmentController::class, 'updateStatus']);
    Route::get('/shipments/{shipment}/tracking-events', [ShipmentController::class, 'trackingEvents']);
    // Phase B49 (gap G9 shipping side; Module 13 §42–56, §70–72): couriers, labels, cash on delivery, returns.
    Route::post('/shipments/cod-remittance', [ShipmentController::class, 'codRemittance'])->middleware('throttle:30,1,cod-remittance');
    Route::post('/shipments/{shipment}/book', [ShipmentController::class, 'book'])->middleware('throttle:20,1,shipment-book');
    Route::post('/shipments/{shipment}/cancel', [ShipmentController::class, 'cancel']);
    Route::post('/shipments/{shipment}/refresh', [ShipmentController::class, 'refresh'])->middleware('throttle:30,1,shipment-refresh');
    Route::post('/shipments/{shipment}/restock', [ShipmentController::class, 'restock']);
    Route::put('/shipments/{shipment}/tracking', [ShipmentController::class, 'setTracking']);
    Route::get('/shipments/{shipment}/label', [ShipmentController::class, 'label'])->middleware('throttle:60,1,shipment-label');
    // Phase B50 (gap G10; Module 21): the store's SMS and WhatsApp.
    $messaging = \App\Domain\Notifications\Http\Controllers\MessagingSettingsController::class;
    Route::get('/messaging-settings', [$messaging, 'show']);
    Route::put('/messaging-settings', [$messaging, 'update']);
    Route::put('/messaging-settings/accounts/{provider}', [$messaging, 'saveAccount'])->where('provider', '[a-z_]{3,32}')->middleware(['step_up', 'throttle:20,10,messaging-account']);
    Route::put('/messaging-settings/channels/{channel}/enabled', [$messaging, 'setEnabled'])->where('channel', 'sms|whatsapp');
    Route::delete('/messaging-settings/channels/{channel}', [$messaging, 'disconnect'])->where('channel', 'sms|whatsapp')->middleware('step_up');
    Route::post('/messaging-settings/channels/{channel}/test', [$messaging, 'test'])->where('channel', 'sms|whatsapp')->middleware('throttle:10,10,messaging-test');
    Route::put('/messaging-settings/templates/{event}', [$messaging, 'saveTemplate'])->where('event', '[a-z_]{3,40}');
    Route::post('/messaging-settings/templates/{event}/check', [$messaging, 'checkTemplate'])->where('event', '[a-z_]{3,40}')->middleware('throttle:30,10,messaging-template-check');
    $couriers = \App\Domain\Shipping\Http\Controllers\CourierSettingsController::class;
    Route::get('/courier-settings', [$couriers, 'show']);
    Route::put('/courier-settings', [$couriers, 'update']);
    Route::put('/courier-settings/couriers/{provider}', [$couriers, 'saveCourier'])->where('provider', '[a-z_]{3,32}')->middleware(['step_up', 'throttle:20,10,courier-save']);
    Route::put('/courier-settings/couriers/{provider}/enabled', [$couriers, 'setEnabled'])->where('provider', '[a-z_]{3,32}');
    Route::delete('/courier-settings/couriers/{provider}', [$couriers, 'disconnect'])->where('provider', '[a-z_]{3,32}')->middleware('step_up');
    Route::get('/returns/{return}/shipments', [\App\Domain\Shipping\Http\Controllers\ReturnShipmentController::class, 'index']);
    Route::post('/returns/{return}/shipments', [\App\Domain\Shipping\Http\Controllers\ReturnShipmentController::class, 'store'])->middleware('throttle:20,1,return-shipment');

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
    Route::put('/customers/{customer}/tax-exemption', [\App\Domain\Tax\Http\Controllers\TaxController::class, 'updateExemption']); // Phase B46
    Route::post('/customers/{customer}/archive', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'archive']);
    Route::post('/customers/{customer}/reactivate', [\App\Domain\Customers\Http\Controllers\CustomerController::class, 'reactivate']);
    // Module 10 §56: explicit, audited, the password asked again.
    // Module 09 §52 (Phase B34): store credit. Giving or taking it by hand asks for the password again.
    Route::get('/customers/{customer}/store-credit', [\App\Domain\StoreCredit\Http\Controllers\StoreCreditController::class, 'show']);
    Route::post('/customers/{customer}/store-credit/adjust', [\App\Domain\StoreCredit\Http\Controllers\StoreCreditController::class, 'adjust'])->middleware(['idempotent', 'step_up', 'throttle:20,10,store-credit-adjust']);
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
    Route::middleware(['can:super-admin.platform', 'privileged.mfa', 'super_admin.platform', 'platform.capability'])
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
            // Phase B45 follow-up (Module 07 §101, Module 03 §52): platform-managed starter templates.
            $templates = \App\Domain\SuperAdmin\Http\Controllers\SuperAdminStarterTemplateController::class;
            Route::get('/starter-templates', [$templates, 'index']);
            Route::get('/starter-templates/{key}', [$templates, 'show'])->where('key', '[a-z0-9_]{1,40}');
            Route::put('/starter-templates/{key}', [$templates, 'update'])->where('key', '[a-z0-9_]{1,40}')->middleware('step_up');
            Route::delete('/starter-templates/{key}', [$templates, 'destroy'])->where('key', '[a-z0-9_]{1,40}')->middleware('step_up');
            Route::post('/stores/{store}/starter-template', [$templates, 'saveFromStore'])->middleware(['step_up', 'throttle:20,10,starter-template-save']);
            // Phase B56 (Module 27): the app marketplace — registry, versions, review, kill switch.
            $market = \App\Domain\Marketplace\Http\Controllers\SuperAdminMarketplaceController::class;
            Route::get('/marketplace/apps', [$market, 'index']);
            Route::post('/marketplace/apps', [$market, 'store']);
            Route::get('/marketplace/apps/{app}', [$market, 'show']);
            Route::put('/marketplace/apps/{app}', [$market, 'update']);
            Route::post('/marketplace/apps/{app}/secret', [$market, 'rotateSecret'])->middleware('step_up');
            Route::put('/marketplace/apps/{app}/version', [$market, 'saveVersion']);
            Route::post('/marketplace/apps/{app}/submit', [$market, 'submit']);
            Route::post('/marketplace/apps/{app}/publish', [$market, 'publish']);
            Route::post('/marketplace/apps/{app}/suspend', [$market, 'suspend'])->middleware('step_up');
            Route::post('/marketplace/apps/{app}/resume', [$market, 'resume'])->middleware('step_up');
            Route::post('/marketplace/apps/{app}/deprecate', [$market, 'deprecate']);

            // Phase B58 (Module 28 §46): the reseller program — tiers, resellers, stores, commissions, payouts.
            $resellers = \App\Domain\Resellers\Http\Controllers\SuperAdminResellerController::class;
            Route::get('/resellers/overview', [$resellers, 'overview']);
            Route::post('/resellers/tiers', [$resellers, 'storeTier'])->middleware('step_up');
            Route::put('/resellers/tiers/{tier}', [$resellers, 'updateTier'])->middleware('step_up');
            Route::get('/resellers/commissions', [$resellers, 'commissions']);
            Route::post('/resellers/commissions/{commission}/reject', [$resellers, 'rejectCommission']);
            Route::get('/resellers/payouts', [$resellers, 'payouts']);
            Route::post('/resellers/payouts/{payout}/pay-to', [$resellers, 'payTo'])->middleware('step_up');
            Route::post('/resellers/payouts/{payout}/paid', [$resellers, 'markPaid'])->middleware(['step_up', 'idempotent']);
            Route::post('/resellers/payouts/{payout}/cancel', [$resellers, 'cancelPayout']);
            Route::post('/resellers/attributions/{attribution}/end', [$resellers, 'endAttribution'])->middleware('step_up');
            Route::get('/resellers', [$resellers, 'index']);
            Route::post('/resellers', [$resellers, 'store'])->middleware('throttle:60,10,reseller-invite');
            Route::get('/resellers/{reseller}', [$resellers, 'show']);
            Route::put('/resellers/{reseller}', [$resellers, 'update']);
            foreach (['approve', 'reject', 'pause', 'resume'] as $step) {
                Route::post("/resellers/{reseller}/{$step}", [$resellers, $step]);
            }
            Route::post('/resellers/{reseller}/invitation', [$resellers, 'reinvite']);
            Route::post('/resellers/{reseller}/terminate', [$resellers, 'terminate'])->middleware('step_up');
            Route::put('/resellers/{reseller}/payout-details', [$resellers, 'payoutDetails'])->middleware('step_up');
            Route::post('/resellers/{reseller}/payouts', [$resellers, 'createPayout'])->middleware(['step_up', 'idempotent']);
            Route::post('/resellers/{reseller}/adjustments', [$resellers, 'adjust'])->middleware(['step_up', 'idempotent']);
            Route::post('/resellers/{reseller}/stores', [$resellers, 'assignStore'])->middleware('step_up');

            // Phase B54 (Module 34): help articles, their review, feedback, searches and release notes.
            $helpAdmin = \App\Domain\Help\Http\Controllers\SuperAdminHelpController::class;
            Route::get('/help/articles', [$helpAdmin, 'index']);
            Route::post('/help/articles', [$helpAdmin, 'store']);
            Route::get('/help/articles/{article}', [$helpAdmin, 'show'])->whereNumber('article');
            Route::put('/help/articles/{article}/draft', [$helpAdmin, 'saveDraft'])->whereNumber('article');
            foreach (['submit', 'approve', 'reject', 'deprecate', 'archive', 'reinstate'] as $step) {
                Route::post("/help/articles/{article}/{$step}", [$helpAdmin, $step])->whereNumber('article');
            }
            Route::post('/help/articles/{article}/bundle-draft', [$helpAdmin, 'bundleDraft'])->whereNumber('article');
            Route::post('/help/preview', [$helpAdmin, 'preview'])->middleware('throttle:60,1,help-preview');
            Route::get('/help/insights', [$helpAdmin, 'insights']);
            Route::post('/help/feedback/{feedback}/resolve', [$helpAdmin, 'resolveFeedback'])->whereNumber('feedback');
            Route::get('/help/releases', [$helpAdmin, 'releases']);
            Route::post('/help/releases/{version}/publish', [$helpAdmin, 'publishRelease'])->where('version', '[0-9.]{1,20}');

            Route::get('/users', [SuperAdminUserController::class, 'index']);
            Route::get('/users/{user}', [SuperAdminUserController::class, 'show']);
            Route::post('/users/{user}/deactivate', [SuperAdminUserController::class, 'deactivate'])->middleware('step_up');
            Route::post('/users/{user}/reactivate', [SuperAdminUserController::class, 'reactivate'])->middleware('step_up');
            // Phase B52 (Module 32 §85, §58–64; Module 30 §3, §58–61, §87–88): Security.
            $security = \App\Domain\SuperAdmin\Http\Controllers\SuperAdminSecurityController::class;
            Route::put('/users/{user}/platform-role', [$security, 'changeRole'])->middleware('step_up');
            Route::post('/users/{user}/sessions/revoke', [$security, 'revokeSessions'])->middleware('step_up');
            Route::get('/security', [$security, 'overview']);
            Route::get('/security/events', [$security, 'events']);
            Route::get('/security/staff', [$security, 'staff']);
            Route::put('/security/emergency/{key}', [$security, 'setControl'])->where('key', '[a-z_.]{3,64}')->middleware('step_up');
            Route::get('/security/incidents', [$security, 'incidents']);
            Route::post('/security/incidents', [$security, 'openIncident']);
            Route::get('/security/incidents/{incident}', [$security, 'showIncident']);
            Route::patch('/security/incidents/{incident}', [$security, 'updateIncident']);
            Route::get('/payments/failures', [SuperAdminPaymentController::class, 'failures']);
            // Phase B48 (Module 30 §26–27): payment providers, Umar Techy's own merchant accounts, lookup, findings.
            $providers = \App\Domain\SuperAdmin\Http\Controllers\SuperAdminPaymentProviderController::class;
            Route::get('/payment-providers', [$providers, 'index']);
            Route::put('/payment-providers/{provider}/live', [$providers, 'setLive'])->where('provider', '[a-z]{3,20}')->middleware('step_up');
            Route::put('/payment-providers/{provider}/account', [$providers, 'saveAccount'])->where('provider', '[a-z]{3,20}')->middleware('step_up');
            Route::put('/payment-providers/{provider}/account/enabled', [$providers, 'setAccountEnabled'])->where('provider', '[a-z]{3,20}')->middleware('step_up');
            Route::delete('/payment-providers/{provider}/account', [$providers, 'disconnectAccount'])->where('provider', '[a-z]{3,20}')->middleware('step_up');
            Route::get('/payment-attempts', [$providers, 'attempts']);
            Route::get('/payment-findings', [$providers, 'findings']);
            // Phase B50: Umar Techy's own SMS and WhatsApp (critical alerts) and SMS providers' live access.
            $messagingAdmin = \App\Domain\SuperAdmin\Http\Controllers\SuperAdminMessagingController::class;
            Route::get('/messaging', [$messagingAdmin, 'index']);
            Route::put('/messaging/providers/{provider}/live', [$messagingAdmin, 'setLive'])->where('provider', '[a-z_]{3,32}')->middleware('step_up');
            Route::put('/messaging/accounts/{provider}', [$messagingAdmin, 'saveAccount'])->where('provider', '[a-z_]{3,32}')->middleware('step_up');
            Route::put('/messaging/channels/{channel}/enabled', [$messagingAdmin, 'setEnabled'])->where('channel', 'sms|whatsapp')->middleware('step_up');
            Route::delete('/messaging/channels/{channel}', [$messagingAdmin, 'disconnect'])->where('channel', 'sms|whatsapp')->middleware('step_up');
            Route::post('/messaging/channels/{channel}/test', [$messagingAdmin, 'test'])->where('channel', 'sms|whatsapp')->middleware('throttle:10,10,platform-messaging-test');
            Route::put('/messaging/alert-template', [$messagingAdmin, 'saveTemplate'])->middleware('step_up');
            Route::get('/notifications/failures', [SuperAdminNotificationController::class, 'failures']);
            // Phase B51: every store deletion, including stores already deleted.
            Route::get('/store-deletions', [\App\Domain\SuperAdmin\Http\Controllers\SuperAdminStoreLifecycleController::class, 'deletions']);
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
            Route::post('/billing/invoices/{invoice}/payments', [SuperAdminBillingController::class, 'recordPayment'])->middleware(['idempotent', 'step_up']);
            Route::post('/billing/invoices/{invoice}/void', [SuperAdminBillingController::class, 'void'])->middleware('step_up');
            Route::post('/billing/invoices/{invoice}/extend-due-date', [SuperAdminBillingController::class, 'extendDueDate'])->middleware('step_up');
            // Phase B47 (Module 29 §42–43, §73–75, §79, §92): payment notices, credit notes, approvals, PDFs.
            $ops = \App\Domain\SuperAdmin\Http\Controllers\SuperAdminBillingOperationsController::class;
            Route::get('/billing/payment-notices', [$ops, 'notices']);
            Route::post('/billing/payment-notices/{notice}/approve', [$ops, 'approveNotice'])->middleware('step_up');
            Route::post('/billing/payment-notices/{notice}/reject', [$ops, 'rejectNotice'])->middleware('step_up');
            Route::get('/billing/credit-notes', [$ops, 'creditNotes']);
            Route::post('/billing/invoices/{invoice}/credit-notes', [$ops, 'createCreditNote'])->middleware(['idempotent', 'step_up']);
            Route::post('/billing/credit-notes/{creditNote}/approve', [$ops, 'approveCreditNote'])->middleware('step_up');
            Route::post('/billing/credit-notes/{creditNote}/reject', [$ops, 'rejectCreditNote'])->middleware('step_up');
            Route::get('/billing/invoices/{invoice}/pdf', [$ops, 'invoicePdf']);
            Route::get('/billing/credit-notes/{creditNote}/pdf', [$ops, 'creditNotePdf']);
            Route::get('/monitoring/api-usage', [SuperAdminMonitoringController::class, 'apiUsage']);
        });

    // --- Super Admin cross-tenant (ADR-001 Layer 7) ---
    Route::middleware(['can:super-admin.impersonate', 'privileged.mfa', 'super_admin.impersonate', 'platform.capability'])
        ->prefix('super-admin')
        ->group(function () {
            Route::get('/stores/{store}/impersonate', [SuperAdminStoreController::class, 'impersonate'])->middleware('step_up'); // SRS SA-004
            Route::get('/stores/{store}', [SuperAdminStoreController::class, 'show'])->withTrashed(); // Phase B51: a deleted store's record stays readable
            Route::post('/stores/{store}/owner-invitation', [SuperAdminStoreController::class, 'inviteOwner'])->middleware('step_up'); // Phase B44
            Route::get('/stores/{store}/health', [SuperAdminMonitoringController::class, 'storeHealth']);

            // Phase B51 (Module 30 §10, §140; Module 03 §33–37): the store's life.
            $life = \App\Domain\SuperAdmin\Http\Controllers\SuperAdminStoreLifecycleController::class;
            Route::get('/stores/{store}/lifecycle', [$life, 'show'])->withTrashed();
            Route::get('/stores/{store}/lifecycle/deletion-plan', [$life, 'plan']);
            foreach (['suspend', 'reactivate', 'close', 'reopen', 'archive', 'restore'] as $action) {
                Route::post("/stores/{store}/lifecycle/{$action}", [$life, $action])->middleware('step_up');
            }
            Route::put('/stores/{store}/lifecycle/legal-hold', [$life, 'legalHold'])->middleware('step_up');
            Route::post('/stores/{store}/lifecycle/deletion', [$life, 'requestDeletion'])->middleware(['step_up', 'throttle:5,60,store-deletion']);
            Route::post('/stores/{store}/lifecycle/deletion/cancel', [$life, 'cancelDeletion'])->middleware('step_up');
            Route::post('/stores/{store}/lifecycle/deletion/retry', [$life, 'retryDeletion'])->middleware('step_up');

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
