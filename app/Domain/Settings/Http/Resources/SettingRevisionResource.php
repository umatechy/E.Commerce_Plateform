<?php

declare(strict_types=1);

namespace App\Domain\Settings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SettingRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $definition = \App\Domain\Settings\Services\SettingRegistry::find($this->key);
        $isSecret = $definition?->type === \App\Domain\Settings\Models\SettingType::Secret;

        return [
            'id' => $this->id,
            // Secret masking uses the SAME registry lookup SettingResource
            // does — never a fragile heuristic on the key's own text.
            'value' => $isSecret ? null : ($this->value[0] ?? null),
            'changed_by_user_id' => $this->changed_by_user_id,
            'reason' => $this->reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
