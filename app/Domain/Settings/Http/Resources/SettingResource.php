<?php

declare(strict_types=1);

namespace App\Domain\Settings\Http\Resources;

use App\Domain\Settings\Models\SettingType;
use App\Domain\Settings\Services\SettingDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 33 §36 "API Response Security" — Non-Negotiable: a secret-
 * typed setting NEVER returns its actual value here, regardless of who
 * is asking — only `{configured, masked}`. Wraps a plain
 * [SettingDefinition, mixed $effectiveValue] pair, not an Eloquent
 * model (there is no single "the" row for an effective value — it may
 * be a code default or a platform fallback).
 */
final class SettingResource extends JsonResource
{
    public function __construct(private readonly SettingDefinition $definition, private readonly mixed $value)
    {
        parent::__construct(null);
    }

    public function toArray(Request $request): array
    {
        $isSecret = $this->definition->type === SettingType::Secret;

        return [
            'key' => $this->definition->key,
            'scope' => $this->definition->scope->value,
            'type' => $this->definition->type->value,
            'value' => $isSecret ? null : $this->value,
            'configured' => $isSecret ? ($this->value !== null) : null,
            'masked' => $isSecret ? '••••••••' : null,
        ];
    }
}
