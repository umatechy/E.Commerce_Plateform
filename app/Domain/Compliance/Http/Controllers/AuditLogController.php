<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Http\Controllers;

use App\Domain\Compliance\Http\Resources\AuditLogResource;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Policies\CompliancePolicy;
use App\Domain\Compliance\Services\AuditChainVerifier;
use App\Domain\Compliance\Services\AuditLogQuery;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 32 — a store's own audit trail. AuditLog has no global tenant
 * scope, so the store filter here is explicit and comes only from the
 * server-resolved TenantContext (ADR-001), never from input.
 */
final class AuditLogController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless(app(CompliancePolicy::class)->viewAuditLog($request->user()), 403);

        $filters = $request->validate(AuditLogQuery::rules());
        $logs = AuditLogQuery::apply(AuditLog::query()->where('store_id', $context->storeId()), $filters)
            ->paginate(AuditLogQuery::perPage($request));

        return response()->json(['data' => $logs->through(fn (AuditLog $log) => (new AuditLogResource($log))->resolve($request))]);
    }

    /** Recomputes this store's hash chain end to end. */
    public function integrity(Request $request, TenantContext $context, AuditChainVerifier $verifier): JsonResponse
    {
        abort_unless(app(CompliancePolicy::class)->viewAuditLog($request->user()), 403);

        return response()->json(['data' => $verifier->verify('store:'.$context->storeId())]);
    }
}
