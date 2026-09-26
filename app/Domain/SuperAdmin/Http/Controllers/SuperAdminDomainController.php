<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Domains\Exceptions\InvalidDomainStateTransitionException;
use App\Domain\Domains\Http\Resources\DomainResource;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Services\DomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Module 19 §31 "Super Admin Domain Management". Reached only via the
 * exact same 'super-admin' route group (can:super-admin.impersonate +
 * super_admin.impersonate middleware) as every other Super Admin
 * cross-tenant endpoint (SuperAdminStoreController,
 * SuperAdminSubscriptionController) — TenantContext is already
 * resolved to the target {store} by that middleware before this
 * controller runs; this class performs no independent authorization
 * check of its own, matching that established precedent exactly.
 */
final class SuperAdminDomainController
{
    /** Module 19 §31/Module 30 §14 "Domain Oversight" — platform-wide, cross-store visibility. Sits under the 'super_admin.platform' group (no target store), distinct from suspend()/reactivate() below which are per-domain but still platform-global actions (a Domain's own store_id is read from the resolved model, never from an impersonated TenantContext). */
    public function indexAll(): AnonymousResourceCollection
    {
        return DomainResource::collection(Domain::query()->withoutTenantScope()->paginate(50));
    }

    public function index(): AnonymousResourceCollection
    {
        return DomainResource::collection(Domain::query()->get());
    }

    public function suspend(Domain $domain, DomainService $domains): JsonResponse
    {
        try {
            $updated = $domains->suspend($domain);
        } catch (InvalidDomainStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition'], 422);
        }

        return (new DomainResource($updated))->response();
    }

    public function reactivate(Domain $domain, DomainService $domains): JsonResponse
    {
        try {
            $updated = $domains->activate($domain);
        } catch (InvalidDomainStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition'], 422);
        }

        return (new DomainResource($updated))->response();
    }
}
