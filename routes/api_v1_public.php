<?php

use App\Domain\Packages\Http\Controllers\PackageController;
use App\Domain\Notifications\Http\Controllers\UnsubscribeController;
use App\Domain\Analytics\Http\Controllers\ReportExportController;
use Illuminate\Support\Facades\Route;

// ADR-005: /api/v1/public/... — unauthenticated storefront-data
// endpoints. Module 04 §40 requires the package catalog to be visible
// to a not-yet-registered visitor comparing plans — no tenant
// resolution is needed here (this is platform-level catalog data, not
// any store's tenant-owned data).
Route::get('/packages', [PackageController::class, 'index']);

// Module 21 §85-86 "Link Security / Unsubscribe Security" (Phase B11)
// — deliberately public (no Sanctum), trust comes entirely from
// UnsubscribeController's own HMAC signature verification against the
// resolved store's notification_signing_secret.
Route::get('/notifications/unsubscribe', UnsubscribeController::class);

// Module 22 §16 "Export Security" (Phase B12) — reached ONLY via a
// Laravel-signed, time-limited URL (the `signed` middleware verifies
// the signature/expiry before ReportExportController::download() ever
// runs); no Sanctum session required, matching how a real download
// link is opened/shared. NEVER a predictable public path — the
// underlying file lives under storage/app/private, reachable only
// through this one verified route.
Route::get('/report-exports/{exportPublicId}/download', [ReportExportController::class, 'download'])
    ->middleware('signed')
    ->name('report-exports.download');
