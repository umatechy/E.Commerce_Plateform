<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Http\Controllers;

use App\Domain\Notifications\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Domain\Notifications\Http\Resources\NotificationMessageResource;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Orders\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Module 21 §21 "In-App Notifications" + §11 "Customer Preferences" —
 * authenticated-customer-only, ownership resolved by direct identity
 * match (same pattern as Cart/Wishlist, Phase B6), never a staff
 * Policy. Paginated (Module 21's own "do not return unlimited
 * notification history" requirement).
 */
final class CustomerNotificationController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return NotificationMessageResource::collection(
            NotificationMessage::query()
                ->where('recipient_type', RecipientType::Customer)
                ->where('recipient_id', $customer->id)
                ->where('channel', 'in_app')
                ->orderByDesc('created_at')
                ->paginate(25)
        );
    }

    public function markRead(Request $request, NotificationMessage $message): NotificationMessageResource
    {
        /** @var Customer $customer */
        $customer = $request->user();
        abort_unless($message->recipient_type === RecipientType::Customer && $message->recipient_id === $customer->id, 404);

        if ($message->read_at === null) {
            $message->update(['read_at' => now()]);
        }

        return new NotificationMessageResource($message->fresh());
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        NotificationMessage::query()
            ->where('recipient_type', RecipientType::Customer)
            ->where('recipient_id', $customer->id)
            ->where('channel', 'in_app')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /** Module 21 §11 — reuses Customer.marketing_email_opt_in (Phase B10) directly, never a duplicate preference field. */
    public function updatePreferences(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $customer->update(['marketing_email_opt_in' => $request->boolean('marketing_email_opt_in')]);

        return response()->json(['marketing_email_opt_in' => $customer->fresh()->marketing_email_opt_in]);
    }
}
