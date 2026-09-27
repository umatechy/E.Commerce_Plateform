<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Domain\DeveloperPlatform\Http\Requests\CreateApplicationRequest;
use App\Domain\DeveloperPlatform\Http\Resources\DeveloperApplicationResource;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Policies\DeveloperPlatformPolicy;
use App\Domain\DeveloperPlatform\Services\ApplicationService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Staff-facing, store-scoped (Module 31 §39/§52 "Applications / Tenant Admin Developer Management"). */
final class DeveloperApplicationController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->view($request->user()), 403);

        return DeveloperApplicationResource::collection(
            DeveloperApplication::query()->where('store_id', app(TenantContext::class)->storeId())->get()
        );
    }

    public function store(CreateApplicationRequest $request, ApplicationService $applications): DeveloperApplicationResource
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->manage($request->user()), 403);

        $store = \App\Domain\Tenancy\Models\Store::query()->findOrFail(app(TenantContext::class)->storeId());
        $application = $applications->create($store, $request->string('name'), $request->user()->id);

        return new DeveloperApplicationResource($application);
    }

    public function suspend(Request $request, DeveloperApplication $developerApplication, ApplicationService $applications): DeveloperApplicationResource
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->manage($request->user()), 403);

        return new DeveloperApplicationResource($applications->suspend($developerApplication));
    }

    public function reactivate(Request $request, DeveloperApplication $developerApplication, ApplicationService $applications): DeveloperApplicationResource
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->manage($request->user()), 403);

        return new DeveloperApplicationResource($applications->reactivate($developerApplication));
    }

    public function revoke(Request $request, DeveloperApplication $developerApplication, ApplicationService $applications): JsonResponse
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->manage($request->user()), 403);

        $applications->revoke($developerApplication);

        return response()->json(status: 204);
    }
}
