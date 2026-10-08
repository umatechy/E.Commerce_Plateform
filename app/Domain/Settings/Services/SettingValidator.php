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
            SettingType::Integer => $this->validateInteger($value, $definition),
            SettingType::String => $this->validateString($value, $definition),
            SettingType::StringArray => $this->validateRecipients($definition, $this->validateStringArray($value)),
            SettingType::Secret => $this->validateString($value, $definition),
        };
    }

    /** Phase B47: settings where 0 means "none" (no tax, no approval step). */
    private const ZERO_ALLOWED = ['billing.tax_rate_bps', 'billing.approval_threshold_minor'];

    private function validateInteger(mixed $value, SettingDefinition $definition): int
    {
        $min = in_array($definition->key, self::ZERO_ALLOWED, true) ? 0 : 1;
        if (! is_int($value) || $value < $min) {
            throw new InvalidSettingValueException($min === 0 ? 'Value must be zero or a positive integer.' : 'Value must be a positive integer.');
        }
        if ($definition->key === 'billing.tax_rate_bps' && $value > 10000) {
            throw new InvalidSettingValueException('A tax rate is at most 10000 basis points (100 %).');
        }

        return $value;
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
        if ($definition->key === 'store.default_currency') {
            $normalized = strtoupper($normalized);
        }

        if ($definition->allowedValues !== null && ! in_array($normalized, $definition->allowedValues, true)) {
            throw new InvalidSettingValueException('Value must be one of: '.implode(', ', $definition->allowedValues));
        }

        if ($definition->key === 'store.timezone' && ! in_array($normalized, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidSettingValueException('Value must be a valid IANA timezone identifier (e.g. "America/New_York").');
        }

        // Phase B44: business information and the trial package.
        $max = ['store.legal_name' => 200, 'store.contact_email' => 191, 'store.contact_phone' => 32, 'tax.label' => 40, 'billing.tax_label' => 40, 'billing.issuer_name' => 120, 'billing.issuer_details' => 500][$definition->key] ?? null; // tax.label: Phase B46
        if ($max !== null && mb_strlen($normalized) > $max) {
            throw new InvalidSettingValueException("At most {$max} characters.");
        }
        if ($definition->key === 'store.contact_email' && filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidSettingValueException('Value must be an email address.');
        }
        if ($definition->key === 'store.contact_phone' && preg_match('/^\+?[0-9][0-9 ()\-]{6,30}$/', $normalized) !== 1) {
            throw new InvalidSettingValueException('Value must be a phone number such as +92 300 1234567.');
        }
        if ($definition->key === 'store.country') {
            $normalized = strtoupper($normalized);
            if (preg_match('/^[A-Z]{2}$/', $normalized) !== 1) {
                throw new InvalidSettingValueException('Value must be a two-letter country code such as PK.');
            }
        }
        if ($definition->key === 'platform.trial_package'
            && ! \App\Domain\Packages\Models\Package::query()->where('code', $normalized)->where('is_active', true)->exists()) {
            throw new InvalidSettingValueException('Value must be the code of an active package.');
        }

        return $normalized;
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private function validateRecipients(SettingDefinition $definition, array $items): array
    {
        // Alert recipients: a typo here means an alert that nobody receives.
        foreach ($definition->key === 'alerts.critical_email_recipients' ? $items : [] as $item) {
            if (filter_var($item, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidSettingValueException("\"{$item}\" is not a valid email address.");
            }
        }

        foreach ($definition->key === 'alerts.whatsapp_recipients' ? $items : [] as $item) {
            if (preg_match('/^\+[1-9]\d{7,14}$/', $item) !== 1) {
                throw new InvalidSettingValueException("\"{$item}\" is not a phone number in international format (+923001234567).");
            }
        }

        // Phase B38: storefront languages — offered codes only, each once.
        if ($definition->key === 'store.languages') {
            foreach ($items as $item) {
                if (! Locales::isSupported($item)) {
                    throw new InvalidSettingValueException("\"{$item}\" is not a language the platform offers (".implode(', ', array_keys(Locales::SUPPORTED)).").");
                }
            }
            $items = array_values(array_unique($items));
        }

        // Currencies: three-letter ISO codes, upper case, each once (owner decision 2026-10-03).
        if ($definition->key === 'platform.supported_currencies') {
            $items = array_map('strtoupper', $items);
            foreach ($items as $item) {
                if (! Currencies::isCode($item)) {
                    throw new InvalidSettingValueException("\"{$item}\" is not a three-letter currency code such as PKR or USD.");
                }
            }
            $items = array_values(array_unique($items));
        }

        return $items;
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

        return array_map('trim', $value);
    }
}
