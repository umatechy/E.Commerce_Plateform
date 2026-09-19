<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http\Controllers;

use App\Domain\Tenancy\Exceptions\UnauthorizedStoreSwitchException;
use App\Domain\Tenancy\Support\StoreSwitcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Tenant Switching" (this milestone's dedicated section). The client
 * MAY request a store context by ID — but the server independently
 * verifies authenticated user + active membership + membership status
 * before accepting it (never accepts a tenant ID without verification).
 */
final class StoreSwitchController
{
    public function __invoke(Request $request, StoreSwitcher $switcher): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer'],
        ]);

        try {
            $switcher->switchTo($request->user(), (int) $validated['store_id']);
        } catch (UnauthorizedStoreSwitchException) {
            throw ValidationException::withMessages([
                'store_id' => 'You do not have access to that store.',
            ]);
        }

        return response()->json(['data' => ['active_store_id' => $validated['store_id']]]);
    }
}
