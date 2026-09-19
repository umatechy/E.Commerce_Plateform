<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;

/**
 * ADR-002 Surface A authentication configuration.
 *
 * Phase B6 CRITICAL ADDITION: the `customer` guard + `customers`
 * provider, alongside the original `sanctum` guard + `users` provider
 * (staff). Module 10 §3: "Customer authentication and staff
 * authentication should remain logically separated even if they share
 * infrastructure" — both guards share Sanctum's underlying polymorphic
 * token table (the "shared infrastructure"), but resolve to entirely
 * distinct Eloquent models. See
 * docs/development/b6-inspection-findings.md "Critical Architectural
 * Decision" for why an explicit type-check middleware
 * (EnsureCustomerPrincipal / EnsureStaffPrincipal) is ALSO required on
 * top of this config — Sanctum's guard resolves a token's `tokenable`
 * polymorphically regardless of which named guard checked it, so this
 * config alone does not prevent a Customer-issued token from being
 * accepted by a route guarded with `auth:sanctum` (or vice versa)
 * unless that route also checks the resolved principal's actual class.
 */
return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Staff/admin (Modules 01-09 store-side users).
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],

        // Storefront customers (Phase B6, Module 10/11) — logically
        // separate identity, sharing only the Sanctum token mechanism.
        'customer' => [
            'driver' => 'sanctum',
            'provider' => 'customers',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],

        'customers' => [
            'driver' => 'eloquent',
            'model' => Customer::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
        'customers' => [
            'provider' => 'customers',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
