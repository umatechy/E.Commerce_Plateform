<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-001 Layer 7 — for genuinely PLATFORM-GLOBAL Super Admin routes
 * that have no target store at all (e.g. Package/Theme catalog
 * management). Phase B16 fix: EnsureSuperAdminImpersonation always
 * reads $request->route('store') and calls markImpersonation() —
 * correct for every {store}-scoped route, but for a route with NO
 * such parameter this previously evaluated to `(int) null` = 0,
 * corrupting TenantContext into a bogus "Store 0" impersonation and
 * writing a misleading audit entry (see
 * docs/development/b16-inspection-findings.md "Critical Bug Found").
 * This middleware is the correct alternative: it establishes PLATFORM
 * context (TenantContext::resolveToPlatform() — the existing, already-
 * correct method for this) and logs an accurate, action-named audit
 * entry, never a fabricated store id.
 */
final class EnsureSuperAdminPlatformAction
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->isPlatformStaff(), 403, 'Platform staff access required.');

        $this->context->resolveToPlatform();

        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('super_admin.platform_action', [
            'acting_super_admin_id' => $user->id,
            'route' => $request->path(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }
}
