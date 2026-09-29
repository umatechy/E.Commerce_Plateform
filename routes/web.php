<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Page (GET) routes only — actual mutations (login/register/logout) go
// through /api/v1/auth/* (ADR-005), called via Inertia's useForm from
// these pages. This keeps the "web" vs "api" boundary consistent with
// ADR-005 rather than mixing session-mutating POSTs into routes/web.php.

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/billing', fn () => Inertia::render('Billing/Overview'));
    Route::get('/inventory', fn () => Inertia::render('Inventory/Index'));
    Route::get('/orders', fn () => Inertia::render('Orders/Index'));
    Route::get('/store-health', fn () => Inertia::render('StoreHealth/Index')); // Module 24 (Phase B21)
});

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => Inertia::render('Auth/Login'));
    Route::get('/register', fn () => Inertia::render('Auth/Register'));
});
