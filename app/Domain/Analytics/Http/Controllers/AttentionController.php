<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Http\Controllers;

use App\Domain\Analytics\Services\AttentionService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** UI-17: GET /api/v1/attention — what the active store should look at now (the header bell). */
final class AttentionController
{
    public function __invoke(Request $request, AttentionService $attention): JsonResponse
    {
        $user = $request->user();
        $storeId = $user->activeStoreId();
        if ($storeId === null) {
            return response()->json(['data' => []]);
        }
        $store = Store::query()->findOrFail($storeId);

        return response()->json(['data' => $attention->for($user, $store)]);
    }
}
