<?php

use App\Domain\Packages\Http\Controllers\PackageController;
use App\Domain\Notifications\Http\Controllers\UnsubscribeController;
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
