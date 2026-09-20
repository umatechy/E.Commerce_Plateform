<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class NotificationTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'channel' => $this->channel->value,
            'locale' => $this->locale,
            'subject' => $this->subject,
            'body' => $this->body,
            'is_published' => $this->is_published,
        ];
    }
}
