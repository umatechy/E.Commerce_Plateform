<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Module 31 §37 "Webhook Signing" — the signing secret is NEVER returned here (only in the one-time creation response); signing_secret is also model-$hidden as defense-in-depth. */
final class WebhookSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'subscribed_events' => $this->subscribed_events,
            'status' => $this->status->value,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
