<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

/**
 * SRS LOC-001/002/006, Module 05 §40–41, Module 17 §9 (Phase B38): the
 * languages a storefront can be shown in.
 *
 * English and Urdu (right to left) are offered now — the platform is built
 * for Pakistan first. The architecture takes more (Module 05 §41 names
 * Arabic for later); a language is added here together with its storefront
 * dictionary (resources/js/Storefront/i18n.ts) and its fonts.
 *
 * Each store chooses which of them its storefront offers
 * (`store.languages`) and which one is the default (`store.default_locale`).
 * Business identifiers — SKUs, order numbers, slugs, prices — are never
 * translated (LOC-003).
 */
final class Locales
{
    public const DEFAULT = 'en';

    /** code => [English name, own name, direction, BCP 47 tag for dates and numbers] */
    public const SUPPORTED = [
        'en' => ['English', 'English', 'ltr', 'en-PK'],
        'ur' => ['Urdu', 'اردو', 'rtl', 'ur-PK'],
    ];

    public static function isSupported(mixed $code): bool
    {
        return is_string($code) && isset(self::SUPPORTED[$code]);
    }

    public static function direction(string $code): string
    {
        return self::SUPPORTED[$code][2] ?? 'ltr';
    }

    /** @return list<array{code: string, name: string, native: string, dir: string, tag: string}> */
    public static function describe(array $codes): array
    {
        return array_values(array_map(fn (string $code) => [
            'code' => $code, 'name' => self::SUPPORTED[$code][0], 'native' => self::SUPPORTED[$code][1],
            'dir' => self::SUPPORTED[$code][2], 'tag' => self::SUPPORTED[$code][3],
        ], array_filter($codes, fn ($code) => self::isSupported($code))));
    }
}
