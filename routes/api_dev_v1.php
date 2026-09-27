<?php

use App\Domain\DeveloperPlatform\Http\Controllers\DevCustomerController;
use App\Domain\DeveloperPlatform\Http\Controllers\DevInventoryController;
use App\Domain\DeveloperPlatform\Http\Controllers\DevOrderController;
use App\Domain\DeveloperPlatform\Http\Controllers\DevProductController;
use Illuminate\Support\Facades\Route;

// ADR-005 / ADR-002: /api/dev/v1/... — Developer API (Module 31, Phase
// B18). Every route: api_key.authenticate (the ONLY auth mechanism —
// never Sanctum, never OAuth/JWT — see
// docs/development/b18-inspection-findings.md "Critical Conflict"),
// throttle:developer_api (B17-configured, per-API-key rate limit),
// api_key.log (metadata-only request log), and a specific
// api_key.scope:<scope> per endpoint. Deliberately READ-ONLY this
// milestone — see inspection findings "Architectural Decision — Scope."
Route::middleware(['api_key.authenticate', 'throttle:developer_api', 'api_key.log'])->group(function () {
    Route::middleware('api_key.scope:products:read')->group(function () {
        Route::get('/products', [DevProductController::class, 'index']);
        Route::get('/products/{product}', [DevProductController::class, 'show']);
    });

    Route::middleware('api_key.scope:orders:read')->group(function () {
        Route::get('/orders', [DevOrderController::class, 'index']);
        Route::get('/orders/{order}', [DevOrderController::class, 'show']);
    });

    Route::middleware('api_key.scope:customers:read')->group(function () {
        Route::get('/customers', [DevCustomerController::class, 'index']);
        Route::get('/customers/{customer}', [DevCustomerController::class, 'show']);
    });

    Route::middleware('api_key.scope:inventory:read')->group(function () {
        Route::get('/inventory', [DevInventoryController::class, 'index']);
    });
});
