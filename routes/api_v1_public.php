<?php

use App\Domain\Packages\Http\Controllers\PackageController;
use Illuminate\Support\Facades\Route;

// ADR-005: /api/v1/public/... — unauthenticated storefront-data
// endpoints. Module 04 §40 requires the package catalog to be visible
// to a not-yet-registered visitor comparing plans — no tenant
// resolution is needed here (this is platform-level catalog data, not
// any store's tenant-owned data).
Route::get('/packages', [PackageController::class, 'index']);
