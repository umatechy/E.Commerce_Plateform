<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Module 30 §12 "User & Staff Oversight". Critical rule (Non-
 * Negotiable): this controller NEVER converts a Customer into a User
 * or vice versa — it only reads/toggles the existing `is_active` flag
 * on the `User` (staff) model, never touches `App\Domain\Orders\Models\Customer`
 * at all, and never mutates a store-membership pivot row's own
 * `status` (a separate, per-store concept — Phase B1 — left
 * completely untouched).
 */
final class SuperAdminUserController
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('stores');

        if ($search = $request->string('search')->toString()) {
            $query->where(fn ($q) => $q->where('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }

        return response()->json(['data' => $query->paginate(25)]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $user->load('stores')]);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $user->update(['is_active' => false]);

        Log::channel('audit')->info('super_admin.user.deactivated', [
            'acting_super_admin_id' => $request->user()->id, 'target_user_id' => $user->id, 'reason' => $request->input('reason'),
        ]);

        return response()->json(status: 204);
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        Log::channel('audit')->info('super_admin.user.reactivated', [
            'acting_super_admin_id' => $request->user()->id, 'target_user_id' => $user->id,
        ]);

        return response()->json(status: 204);
    }
}
