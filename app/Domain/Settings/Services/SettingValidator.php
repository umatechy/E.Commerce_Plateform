<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Exceptions\InvalidSettingValueException;
use App\Domain\Settings\Models\SettingType;

/**
 * Module 33 §13-14 "Validation / Normalization" — Non-Negotiable: all
 * settings are validated server-side before persistence, never trusting
 * frontend validation alone. Every SettingDefinition's type is checked
 * here; the ONE cross-field rule this milestone has
 * (store.default_currency must be one of platform.supported_currencies)
 * is checked by ConfigService itself, which has both values in hand.
 */
final class SettingValidator
{
    /**
     * @throws InvalidSettingValueException
     */
    public function validate(SettingDefinition $definition, mixed $value): mixed
    {
        return match ($definition->type) {
            SettingType::Boolean => $this->validateBoolean($value),
            SettingType::String => $this->validateString($value, $definition),
            SettingType::StringArray => $this->validateStringArray($value),
            SettingType::Secret => $this->validateString($value, $definition),
        };
    }

    private function validateBoolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new InvalidSettingValueException('Value must be a boolean.');
        }

        return $value;
    }

    private function validateString(mixed $value, SettingDefinition $definition): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidSettingValueException('Value must be a non-empty string.');
        }

        $normalized = trim($value);

        if ($definition->allowedValues !== null && ! in_array($normalized, $definition->allowedValues, true)) {
            throw new InvalidSettingValueException('Value must be one of: '.implode(', ', $definition->allowedValues));
        }

        if ($definition->key === 'store.timezone' && ! in_array($normalized, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidSettingValueException('Value must be a valid IANA timezone identifier (e.g. "America/New_York").');
        }

        return $normalized;
    }

    /** @return list<string> */
    private function validateStringArray(mixed $value): array
    {
        if (! is_array($value) || $value === [] || array_is_list($value) === false) {
            throw new InvalidSettingValueException('Value must be a non-empty list of strings.');
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidSettingValueException('Every item must be a non-empty string.');
            }
        }

        return array_map('trim', array_values($value));
    }
}
