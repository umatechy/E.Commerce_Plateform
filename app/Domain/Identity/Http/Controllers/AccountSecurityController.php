<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Models\UserSession;
use App\Domain\Identity\Services\StaffPasswordService;
use App\Domain\Identity\Services\UserSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Phase B52 (SRS AUTH-005; Module 32 §7, §9) — the signed-in person's own
 * sessions and password, on the Security page. Each person sees and ends
 * only their own sessions.
 */
final class AccountSecurityController
{
    public function __construct(private readonly UserSessions $sessions) {}

    public function sessions(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sessions->listFor($request->user(), $request)]);
    }

    public function revoke(Request $request, string $session): JsonResponse
    {
        $row = UserSession::query()->where('public_id', $session)->where('user_id', $request->user()->id)->firstOrFail();
        $this->sessions->revokeOne($request->user(), $row);

        return response()->json(['data' => $this->sessions->listFor($request->user(), $request)]);
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        $count = $this->sessions->revokeAll($request->user(), 'revoked', $request->user(), keep: $request);

        return response()->json(['data' => $this->sessions->listFor($request->user(), $request), 'ended' => $count]);
    }

    public function changePassword(Request $request, StaffPasswordService $passwords): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);
        $passwords->change($request->user(), $data['current_password'], $data['password'], $request);

        return response()->json(['message' => 'Your password was changed. Your other devices were signed out.']);
    }
}
