<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Compliance\Http\Resources\AuditLogResource;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Services\AuditChainVerifier;
use App\Domain\Compliance\Services\AuditLogQuery;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 32 platform-wide audit access (super_admin.platform group).
 * Reading the audit trail is itself an audited platform action (the
 * group's middleware records super_admin.platform_action).
 */
final class SuperAdminAuditController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            ...AuditLogQuery::rules(),
            'store' => ['nullable', 'string'], // a store public_id, or "platform" for platform-level entries
        ]);

        $query = AuditLog::query()->with(['store' => fn ($q) => $q->withTrashed()->select(['id', 'public_id', 'name'])]);

        if (($filters['store'] ?? null) === 'platform') {
            $query->whereNull('store_id');
        } elseif (isset($filters['store'])) {
            $query->where('store_id', Store::query()->withTrashed()->where('public_id', $filters['store'])->value('id') ?? 0);
        }

        $logs = AuditLogQuery::apply($query, $filters)->paginate(AuditLogQuery::perPage($request));

        return response()->json(['data' => $logs->through(fn (AuditLog $log) => (new AuditLogResource($log))->resolve($request))]);
    }

    /** Verifies every chain (platform + each store). */
    public function integrity(AuditChainVerifier $verifier): JsonResponse
    {
        $chains = $verifier->verifyAll();

        return response()->json(['data' => [
            'status' => collect($chains)->contains(fn (array $c) => $c['status'] === 'broken') ? 'broken' : 'ok',
            'chains' => $chains,
        ]]);
    }
}
