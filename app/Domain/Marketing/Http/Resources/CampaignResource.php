<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'objective' => $this->objective->value,
            'channel' => $this->channel,
            'status' => $this->status->value,
            'audience_type' => $this->audience_type->value,
            'marketing_segment_id' => $this->marketing_segment_id,
            'promotion_id' => $this->promotion?->public_id,
            'subject' => $this->subject,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'recipient_count' => $this->whenCounted('recipients'),
        ];
    }
}
