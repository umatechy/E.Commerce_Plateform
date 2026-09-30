<?php

declare(strict_types=1);

namespace App\Domain\Domains\Http\Controllers;

use App\Domain\Domains\Exceptions\DomainNotEligibleForPrimaryException;
use App\Domain\Domains\Exceptions\DomainVerificationFailedException;
use App\Domain\Domains\Exceptions\InvalidDomainStateTransitionException;
use App\Domain\Domains\Exceptions\InvalidHostnameException;
use App\Domain\Domains\Http\Requests\AddDomainRequest;
use App\Domain\Domains\Http\Resources\DomainResource;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Policies\DomainPolicy;
use App\Domain\Domains\Services\DomainService;
use App\Domain\Domains\Services\DomainVerificationService;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff-facing Domain API (Module 19 §30-32). Every mutating method:
 * authenticated (staff.principal, via route group), authorized
 * (DomainPolicy, direct calls — same precedent as every other
 * multi-method policy in this codebase), tenant-scoped, validated.
 */
final class DomainController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(app(DomainPolicy::class)->viewAny($request->user()), 403);

        return DomainResource::collection(Domain::query()->get());
    }

    public function store(AddDomainRequest $request, DomainService $domains, EntitlementService $entitlements): JsonResponse
    {
        abort_unless(app(DomainPolicy::class)->manage($request->user()), 403);
        $entitlements->assertFeatureEntitled('domains.custom_domain');

        try {
            $store = \App\Domain\Tenancy\Models\Store::query()->findOrFail(app(TenantContext::class)->storeId());
            $domain = $domains->addCustomDomain($store, $request->string('hostname')->toString());
        } catch (InvalidHostnameException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_hostname'], 422);
        }

        return (new DomainResource($domain))->response()->setStatusCode(201);
    }

    public function initiateVerification(Request $request, Domain $domain, DomainVerificationService $verification): JsonResponse
    {
        abort_unless(app(DomainPolicy::class)->manage($request->user(), $domain), 403);

        $domain = $verification->initiate($domain);

        return response()->json(['data' => new DomainResource($domain), 'instructions' => $verification->verificationInstructions($domain)]);
    }

    public function verify(Request $request, Domain $domain, DomainVerificationService $verification): JsonResponse
    {
        abort_unless(app(DomainPolicy::class)->manage($request->user(), $domain), 403);

        try {
            $verified = $verification->attemptVerification($domain);
        } catch (DomainVerificationFailedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'verification_failed'], 422);
        }

        return (new DomainResource($verified))->response();
    }

    public function setPrimary(Request $request, Domain $domain, DomainService $domains): JsonResponse
    {
        abort_unless(app(DomainPolicy::class)->manage($request->user(), $domain), 403);

        try {
            $updated = $domains->setPrimary($domain);
        } catch (DomainNotEligibleForPrimaryException|InvalidDomainStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_eligible'], 422);
        }

        return (new DomainResource($updated))->response();
    }

    public function destroy(Request $request, Domain $domain, DomainService $domains): JsonResponse
    {
        abort_unless(app(DomainPolicy::class)->manage($request->user(), $domain), 403);

        try {
            $removed = $domains->remove($domain);
        } catch (InvalidDomainStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition'], 422);
        }

        return (new DomainResource($removed))->response();
    }
}
