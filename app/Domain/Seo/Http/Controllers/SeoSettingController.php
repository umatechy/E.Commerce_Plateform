<?php

declare(strict_types=1);

namespace App\Domain\Seo\Http\Controllers;

use App\Domain\Seo\Http\Requests\SaveSeoSettingRequest;
use App\Domain\Seo\Http\Resources\SeoSettingResource;
use App\Domain\Seo\Models\SeoSetting;
use App\Domain\Seo\Policies\SeoPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Staff-facing SEO override API (Module 16 §6-7). */
final class SeoSettingController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(app(SeoPolicy::class)->view($request->user()), 403);

        return SeoSettingResource::collection(SeoSetting::query()->get());
    }

    public function store(SaveSeoSettingRequest $request): JsonResponse
    {
        abort_unless(app(SeoPolicy::class)->manage($request->user()), 403);

        $setting = SeoSetting::query()->updateOrCreate(
            ['seoable_type' => $request->input('seoable_type'), 'seoable_id' => $request->input('seoable_id')],
            $request->except(['seoable_type', 'seoable_id']),
        );

        return (new SeoSettingResource($setting))->response()->setStatusCode(201);
    }
}
