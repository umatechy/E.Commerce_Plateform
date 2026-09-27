<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Models\SettingType;

/**
 * Module 33 §5 "Configuration Registry" / Non-Negotiable §12 "No
 * Arbitrary Key/Value Admin" — the ONE fixed, server-authoritative
 * list of every setting key this platform recognizes. There is no
 * code path anywhere in this domain that accepts a key not present
 * here — ConfigService::get()/set() both reject an unknown key before
 * touching the database at all.
 *
 * Deliberately minimal — see docs/development/b17-inspection-findings.md
 * "Architectural Decision — Minimal, Named-Example-Only Seeded
 * Settings": every key below is drawn directly from Module 33's own
 * named examples, nothing invented.
 */
final class SettingRegistry
{
    /** @return array<string, SettingDefinition> */
    public static function all(): array
    {
        return [
            'platform.supported_currencies' => new SettingDefinition(
                key: 'platform.supported_currencies', scope: SettingScope::Platform, type: SettingType::StringArray,
                default: ['USD'],
            ),
            'platform.default_locale' => new SettingDefinition(
                key: 'platform.default_locale', scope: SettingScope::Platform, type: SettingType::String,
                default: 'en',
            ),
            'platform.maintenance_mode' => new SettingDefinition(
                key: 'platform.maintenance_mode', scope: SettingScope::Platform, type: SettingType::Boolean,
                default: false,
            ),
            'store.default_currency' => new SettingDefinition(
                key: 'store.default_currency', scope: SettingScope::Store, type: SettingType::String,
                default: 'USD', // Module 33 §4 hierarchy: cross-validated against platform.supported_currencies at write time, see SettingValidator
            ),
            'store.timezone' => new SettingDefinition(
                key: 'store.timezone', scope: SettingScope::Store, type: SettingType::String,
                default: 'UTC', // closes the multiply-documented gap from B10/B12/B13 — see inspection findings
            ),
            'store.default_locale' => new SettingDefinition(
                key: 'store.default_locale', scope: SettingScope::Store, type: SettingType::String,
                default: null, fallsBackToPlatformKey: 'platform.default_locale',
            ),
            'api.default_rate_limit_per_minute' => new SettingDefinition(
                key: 'api.default_rate_limit_per_minute', scope: SettingScope::Platform, type: SettingType::Integer,
                default: 60, // Module 31 §17/§43 "Rate Limiting / Configuration" — Phase B18's own integration point
            ),
        ];
    }

    public static function find(string $key): ?SettingDefinition
    {
        return self::all()[$key] ?? null;
    }
}
