<?php

declare(strict_types=1);

namespace App\Domain\Theme\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Seo\Policies\SeoPolicy;
use App\Domain\Theme\Policies\ThemePolicy;
use App\Domain\Theme\Services\StoreMediaService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B37 — POST /api/v1/store/media: upload a JPG or PNG for the logo,
 * favicon, a banner (theme editors) or the social sharing image (SEO
 * editors). Answers the site path the theme stores and a full address for
 * places that need one.
 */
final class StoreMediaController
{
    public function store(Request $request, StoreMediaService $media, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'in:'.implode(',', array_keys(StoreMediaService::PURPOSES))],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:'.StoreMediaService::MAX_KILOBYTES],
        ]);
        $user = $request->user();
        $allowed = $data['purpose'] === 'social'
            ? app(SeoPolicy::class)->manage($user) || app(ThemePolicy::class)->manage($user)
            : app(ThemePolicy::class)->manage($user);
        abort_unless($allowed, 403);

        $store = Store::query()->findOrFail(app(TenantContext::class)->storeId());
        $item = $media->upload($store, $data['file'], $data['purpose'], $user->id);
        $audit->record('store_media.uploaded', ['purpose' => $item->purpose, 'bytes' => $item->size_bytes], $item, $store->id, $user);

        return response()->json(['data' => [
            'id' => $item->public_id, 'purpose' => $item->purpose,
            'path' => $item->sitePath(), 'url' => $item->url(),
            'width' => $item->width, 'height' => $item->height,
        ]], 201);
    }
}
