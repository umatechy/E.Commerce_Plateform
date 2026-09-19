<?php

use App\Http\Middleware\EnsureSuperAdminImpersonation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Laravel 12 bootstrap-file style middleware/route registration.
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_v1.php'));

            \Illuminate\Support\Facades\Route::middleware('api')
                ->prefix('api/v1/public')
                ->group(base_path('routes/api_v1_public.php'));

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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Module 31 §2.23-consistent standard error envelope for API
        // responses is added when the first real API endpoints ship
        // (Phase B3+) — Phase B0 establishes the registration point only.
    })
    ->create();
