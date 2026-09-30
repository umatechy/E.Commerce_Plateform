<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-001 Layer 7 — explicit, permissioned, audited Super Admin
 * cross-tenant access. Applied ONLY to Super Admin (Module 30) routes
 * that legitimately act "as" or "on" a specific store on behalf of
 * support/operations. This is never a general-purpose bypass — routes
 * outside the Super Admin surface never use this middleware.
 */
final class EnsureSuperAdminImpersonation
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->isPlatformStaff(), 403, 'Platform staff access required.');

        // Module 30 authorization (permission to impersonate this
        // specific store) is enforced by a dedicated Policy — this
        // middleware only establishes context + audit trail once that
        // authorization has already passed via route-level `can:` checks
        // registered alongside this middleware, never in place of them.
        $targetStoreId = (int) $request->route('store');

        $this->context->markImpersonation($user->id, $targetStoreId);

        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('super_admin.impersonation.started', [
            'acting_super_admin_id' => $user->id,
            'target_store_id' => $targetStoreId,
            'route' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }
}
