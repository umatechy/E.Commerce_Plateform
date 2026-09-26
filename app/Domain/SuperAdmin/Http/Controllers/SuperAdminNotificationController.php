<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationStatus;
use Illuminate\Http\JsonResponse;

/**
 * Module 30 §19 "Notifications/Communication" — read-only, cross-
 * store. Never sends a message itself (B11's NotificationService/
 * DeliverNotificationJob remain fully authoritative) — this is
 * strictly a failure-visibility endpoint.
 */
final class SuperAdminNotificationController
{
    public function failures(): JsonResponse
    {
        $failures = NotificationMessage::query()->withoutTenantScope()
            ->where('status', NotificationStatus::Failed)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json(['data' => $failures]);
    }
}
