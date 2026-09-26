<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Models\SettingType;

/**
 * A single, immutable value object describing one recognized setting
 * key — never persisted, never client-writable. See SettingRegistry
 * for the fixed list of every key that exists.
 */
final class SettingDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly SettingScope $scope,
        public readonly SettingType $type,
        public readonly mixed $default,
        /** @var list<string>|null null = no enum restriction */
        public readonly ?array $allowedValues = null,
        public readonly bool $sensitive = false,
        /** For a store-scope key: does it fall back to a platform key's EFFECTIVE value when unset? */
        public readonly ?string $fallsBackToPlatformKey = null,
    ) {}
}
