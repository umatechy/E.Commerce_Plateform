<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class NotificationDeliveryAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'attempt_number' => $this->attempt_number,
            'provider' => $this->provider,
            'result' => $this->result->value,
            'failure_code' => $this->failure_code,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
