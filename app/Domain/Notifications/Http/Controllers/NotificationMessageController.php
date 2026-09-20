<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Http\Controllers;

use App\Domain\Notifications\Http\Resources\NotificationDeliveryAttemptResource;
use App\Domain\Notifications\Http\Resources\NotificationMessageResource;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Policies\NotificationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Staff-facing read-only view of sent notifications (Module 21 §42 "API Requirements"). */
final class NotificationMessageController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(app(NotificationPolicy::class)->view($request->user()), 403);

        return NotificationMessageResource::collection(
            NotificationMessage::query()->orderByDesc('created_at')->paginate(25)
        );
    }

    public function attempts(Request $request, NotificationMessage $message): AnonymousResourceCollection
    {
        abort_unless(app(NotificationPolicy::class)->view($request->user()), 403);

        return NotificationDeliveryAttemptResource::collection(
            $message->attempts()->orderBy('attempt_number')->get()
        );
    }
}
