<?php

use App\Http\Middleware\AttemptCustomerAuthentication;
use App\Http\Middleware\EnsureCustomerPrincipal;
use App\Http\Middleware\EnsureStaffPrincipal;
use App\Http\Middleware\EnsureSuperAdminImpersonation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Laravel 12 bootstrap-file style middleware/route registration.
return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        // Phase B10 correctness fix: Laravel's default command auto-
        // discovery only scans app/Console/Commands. This codebase
        // places console commands inside their owning domain (Phase
        // B4's ExpireStaleReservations, Phase B10's
        // DetectAbandonedCarts) — neither was actually discoverable by
        // Artisan without this explicit registration, a latent gap
        // since Phase B4 that went unnoticed until this milestone's
        // own command needed it. Both are registered here now.
        app_path('Domain/Inventory/Console'),
        app_path('Domain/Marketing/Console'),
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_v1.php'));

            // Phase B6: storefront customer-facing routes — separate
            // file for a separate authentication boundary (Module 10
            // §3), same /api/v1/... prefix (ADR-005 — this is not a new
            // API version, just a different principal type).
            \Illuminate\Support\Facades\Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_v1_customer.php'));

            \Illuminate\Support\Facades\Route::middleware('api')
                ->prefix('api/v1/public')
                ->group(base_path('routes/api_v1_public.php'));

            // Phase B7: payment gateway webhooks — deliberately
            // registered with NO auth middleware at all (Module 12
            // Non-Negotiable Rule #13: "Webhooks must not use Sanctum
            // as authentication"). Trust comes entirely from
            // PaymentService's own signature verification.
            \Illuminate\Support\Facades\Route::prefix('api/v1')
                ->group(base_path('routes/api_v1_webhooks.php'));

            // Developer API prefix reserved (ADR-005) but not wired with
            // middleware yet — see routes/api_dev_v1.php docblock.
            \Illuminate\Support\Facades\Route::prefix('api/dev/v1')
                ->group(base_path('routes/api_dev_v1.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ADR-002 Surface A: Sanctum's SPA stateful-request middleware MUST
        // run before ResolveTenantContext on the api group — without it,
        // the Inertia SPA's cookie-session requests would not be
        // recognized as "stateful" by Sanctum and auth:sanctum would
        // silently fall back to token-only mode. Fixed in Phase B1 (see
        // docs/security/b1-security-review.md — flagged, then fixed).
        $middleware->prependToGroup('api', EnsureFrontendRequestsAreStateful::class);

        // ADR-001: tenant resolution runs on every web + api request,
        // AFTER auth (so it can read the authenticated user's store
        // membership) and BEFORE any controller/policy/model code runs.
        $middleware->appendToGroup('web', ResolveTenantContext::class);
        $middleware->appendToGroup('web', HandleInertiaRequests::class);
        $middleware->appendToGroup('api', ResolveTenantContext::class);

        $middleware->alias([
            'super_admin.impersonate' => EnsureSuperAdminImpersonation::class,
            'super_admin.platform' => \App\Http\Middleware\EnsureSuperAdminPlatformAction::class,
            'api_key.authenticate' => \App\Http\Middleware\EnsureApiKeyAuthenticated::class,
            'api_key.scope' => \App\Http\Middleware\EnsureApiScope::class,
            'api_key.log' => \App\Http\Middleware\LogApiRequest::class,
            'staff.principal' => EnsureStaffPrincipal::class,
            'customer.principal' => EnsureCustomerPrincipal::class,
            'customer.optional' => AttemptCustomerAuthentication::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Module 31 §2.23-consistent standard error envelope for API
        // responses is added when the first real API endpoints ship
        // (Phase B3+) — Phase B0 establishes the registration point only.
    })
    ->create();
