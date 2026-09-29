<?php

declare(strict_types=1);

namespace App\Domain\Theme\Services;

use App\Domain\Theme\Exceptions\InvalidThemeConfigException;
use App\Domain\Theme\Models\SectionType;

/**
 * Module 17 §13/§39 "Theme Configuration Schema" — Non-Negotiable: "do
 * not accept arbitrary JSON and blindly render it." Every field is
 * checked against a fixed whitelist; an unrecognized key ANYWHERE is
 * rejected outright (the stricter of Module 17's own two offered
 * options), never silently dropped or stored as-is.
 */
final class ThemeConfigValidator
{
    private const COLOR_TOKEN_KEYS = ['primary', 'secondary', 'accent', 'background', 'surface', 'text', 'muted', 'border', 'success', 'warning', 'error'];
    private const ALLOWED_RADIUS = ['sm', 'md', 'lg'];
    // Module 17 §32 "Typography Security" — no arbitrary external font
    // URL is ever accepted in B15's scope (see inspection findings);
    // only these system-safe, pre-approved font identifiers.
    private const ALLOWED_FONTS = ['system-ui', 'Inter', 'Roboto', 'Georgia', 'Playfair Display', 'Merriweather'];
    private const ALLOWED_SOCIAL_KEYS = ['facebook', 'instagram', 'twitter', 'tiktok', 'youtube'];
    private const ALLOWED_TOP_LEVEL_KEYS = ['tokens', 'branding', 'sections'];

    /**
     * @throws InvalidThemeConfigException
     */
    public function validate(array $config): array
    {
        $this->assertNoUnknownKeys($config, self::ALLOWED_TOP_LEVEL_KEYS, 'config');

        return [
            'tokens' => $this->validateTokens($config['tokens'] ?? []),
            'branding' => $this->validateBranding($config['branding'] ?? []),
            'sections' => $this->validateSections($config['sections'] ?? []),
        ];
    }

    private function validateTokens(array $tokens): array
    {
        $allowedKeys = [...self::COLOR_TOKEN_KEYS, 'radius', 'font_family'];
        $this->assertNoUnknownKeys($tokens, $allowedKeys, 'tokens');

        $clean = [];

        foreach (self::COLOR_TOKEN_KEYS as $key) {
            if (isset($tokens[$key])) {
                $clean[$key] = $this->validateHexColor($tokens[$key], $key);
            }
        }

        if (isset($tokens['radius'])) {
            if (! in_array($tokens['radius'], self::ALLOWED_RADIUS, true)) {
                throw new InvalidThemeConfigException("Unsupported radius value: {$tokens['radius']}");
            }
            $clean['radius'] = $tokens['radius'];
        }

        if (isset($tokens['font_family'])) {
            if (! in_array($tokens['font_family'], self::ALLOWED_FONTS, true)) {
                throw new InvalidThemeConfigException("Unsupported font family: {$tokens['font_family']}");
            }
            $clean['font_family'] = $tokens['font_family'];
        }

        return $clean;
    }

    private function validateBranding(array $branding): array
    {
        $this->assertNoUnknownKeys($branding, ['logo_url', 'favicon_url', 'tagline', 'social_links'], 'branding');

        $clean = [];

        foreach (['logo_url', 'favicon_url'] as $key) {
            if (isset($branding[$key])) {
                $clean[$key] = $this->validateSafeUrl($branding[$key], $key);
            }
        }

        if (isset($branding['tagline'])) {
            if (! is_string($branding['tagline']) || mb_strlen($branding['tagline']) > 255) {
                throw new InvalidThemeConfigException('Tagline must be a string of 255 characters or fewer.');
            }
            $clean['tagline'] = $branding['tagline'];
        }

        if (isset($branding['social_links'])) {
            $this->assertNoUnknownKeys($branding['social_links'], self::ALLOWED_SOCIAL_KEYS, 'social_links');
            $clean['social_links'] = [];
            foreach ($branding['social_links'] as $platform => $url) {
                $clean['social_links'][$platform] = $this->validateSafeUrl($url, "social_links.{$platform}");
            }
        }

        return $clean;
    }

    private function validateSections(array $sections): array
    {
        $clean = [];

        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                throw new InvalidThemeConfigException("Section at index {$index} must be an object.");
            }

            $this->assertNoUnknownKeys($section, ['type', 'position', 'is_visible', 'config'], "sections.{$index}");

            $type = SectionType::tryFrom($section['type'] ?? '');
            if ($type === null) {
                throw new InvalidThemeConfigException("Unsupported section type at index {$index}: ".($section['type'] ?? '(none)'));
            }

            $clean[] = [
                'type' => $type->value,
                'position' => (int) ($section['position'] ?? $index),
                'is_visible' => (bool) ($section['is_visible'] ?? true),
                'config' => $this->validateSectionConfig($type, $section['config'] ?? []),
            ];
        }

        return $clean;
    }

    private function validateSectionConfig(SectionType $type, array $config): array
    {
        // A small, per-type whitelist — every section type accepts only
        // plain, length-capped strings for its own known fields; never
        // an arbitrary key, never HTML (this is DATA for a future
        // renderer to escape/render safely, not markup itself).
        $allowedByType = [
            SectionType::AnnouncementBar->value => ['message'],
            SectionType::Hero->value => ['heading', 'subheading', 'image_url', 'cta_url'],
            SectionType::PromotionalBanner->value => ['heading', 'image_url', 'cta_url'],
            SectionType::Newsletter->value => ['heading', 'subheading'],
            SectionType::FeaturedProducts->value => ['heading', 'limit'],
            SectionType::FeaturedCategories->value => ['heading', 'limit'],
            SectionType::Header->value => [],
            SectionType::Footer->value => [],
        ];

        $allowedKeys = $allowedByType[$type->value]; // every SectionType has an entry
        $this->assertNoUnknownKeys($config, $allowedKeys, "sections.{$type->value}.config");

        $clean = [];
        foreach ($allowedKeys as $key) {
            if (! isset($config[$key])) {
                continue;
            }
            if (in_array($key, ['image_url', 'cta_url'], true)) {
                $clean[$key] = $this->validateSafeUrl($config[$key], $key);
            } elseif ($key === 'limit') {
                $clean[$key] = max(1, min(50, (int) $config[$key]));
            } else {
                if (! is_string($config[$key]) || mb_strlen($config[$key]) > 500) {
                    throw new InvalidThemeConfigException("{$key} must be a string of 500 characters or fewer.");
                }
                $clean[$key] = $config[$key];
            }
        }

        return $clean;
    }

    private function validateHexColor(mixed $value, string $key): string
    {
        if (! is_string($value) || ! preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            throw new InvalidThemeConfigException("Token \"{$key}\" must be a hex color (e.g. #FF0000).");
        }

        return $value;
    }

    private function validateSafeUrl(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('#^https://[^\s<>"\']+$#i', $value)) {
            throw new InvalidThemeConfigException("\"{$field}\" must be a valid https:// URL.");
        }

        return $value;
    }

    /**
     * @throws InvalidThemeConfigException
     */
    private function assertNoUnknownKeys(array $data, array $allowedKeys, string $context): void
    {
        $unknown = array_diff(array_keys($data), $allowedKeys);

        if ($unknown !== []) {
            throw new InvalidThemeConfigException("Unrecognized field(s) in {$context}: ".implode(', ', $unknown));
        }
    }
}
