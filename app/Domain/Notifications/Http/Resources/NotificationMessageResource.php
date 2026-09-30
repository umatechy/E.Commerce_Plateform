<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes the raw `destination` value in the CUSTOMER-facing
 * in-app listing (own email/phone is not sensitive to oneself, but
 * this Resource is shared with the staff view too — see Module 21
 * §24 "Sensitive PII Leakage" — so `destination` is included only for
 * staff via a separate allow-list decision, kept out here entirely to
 * keep one Resource safe for both audiences).
 *
 * @mixin \App\Domain\Notifications\Models\NotificationMessage
 */
final class NotificationMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'message_type' => $this->message_type->value,
            'channel' => $this->channel->value,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => $this->status->value,
            'is_read' => $this->isRead(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
