<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Marketing\Models\CampaignRecipient */
final class CampaignRecipientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'customer_id' => $this->customer?->public_id,
            'status' => $this->status->value,
            'queued_at' => $this->queued_at?->toIso8601String(),
        ];
    }
}
