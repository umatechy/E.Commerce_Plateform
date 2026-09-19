<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Tenancy\Http\Resources\StoreResource;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\Request;

/**
 * Super Admin cross-tenant entry point (ADR-001 Layer 7). Reached only
 * via the 'super_admin.impersonate' middleware alias (registered in
 * bootstrap/app.php), which itself requires isPlatformStaff() AND writes
 * the audit log entry BEFORE this controller runs — this controller
 * trusts that its middleware chain already did both the permission
 * check and the audit write; it does not repeat either, but it also
 * performs no query without the TenantContext the middleware resolved.
 */
final class SuperAdminStoreController
{
    public function impersonate(Request $request, Store $store): StoreResource
    {
        // TenantContext is already resolved to $store (impersonation
        // mode) by EnsureSuperAdminImpersonation — this line exists only
        // to make that reliance explicit and reviewable, not to redo it.
        return new StoreResource($store);
    }
}
