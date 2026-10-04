<?php

declare(strict_types=1);

namespace App\Domain\Theme\Services;

use App\Domain\Theme\Exceptions\InvalidThemeConfigException;
use App\Domain\Theme\Models\SectionType;
use App\Domain\Theme\Support\ThemeCatalog;
use App\Domain\Theme\Support\ThemeOptions;

/**
 * Module 17 §13/§39 "Theme Configuration Schema" — Non-Negotiable: "do
 * not accept arbitrary JSON and blindly render it." Every field is
 * checked against a fixed whitelist; an unrecognized key ANYWHERE is
 * rejected outright (the stricter of Module 17's own two offered
 * options), never silently dropped or stored as-is.
 *
 * Phase B36 (Module 17 §15, §21–26, §33, §40; Module 18 §8, §41): the
 * selected theme, layout and motion blocks, more tokens, and the item lists
 * of the new sections. Values come from ThemeOptions only. This class checks
 * the shape; what the store's package allows is ThemeEntitlements' job.
 */
final class ThemeConfigValidator
{
    private const ALLOWED_SOCIAL_KEYS = ['facebook', 'instagram', 'twitter', 'tiktok', 'youtube'];
    private const ALLOWED_TOP_LEVEL_KEYS = ['theme', 'tokens', 'layout', 'motion', 'branding', 'sections'];
    private const MAX_SECTIONS = 30;

    /**
     * @throws InvalidThemeConfigException
     */
    public function validate(array $config): array
    {
        $this->assertNoUnknownKeys($config, self::ALLOWED_TOP_LEVEL_KEYS, 'config');

        $clean = [];
        if (isset($config['theme'])) {
            $clean['theme'] = $this->validateThemeKey($config['theme']);
        }

        return [
            ...$clean,
            'tokens' => $this->validateTokens($this->object($config['tokens'] ?? [], 'tokens')),
            ...(isset($config['layout']) ? ['layout' => $this->validateLayout($this->object($config['layout'], 'layout'))] : []),
            ...(isset($config['motion']) ? ['motion' => $this->validateMotion($this->object($config['motion'], 'motion'))] : []),
            'branding' => $this->validateBranding($this->object($config['branding'] ?? [], 'branding')),
            'sections' => $this->validateSections($this->object($config['sections'] ?? [], 'sections')),
        ];
    }

    private function validateThemeKey(mixed $key): string
    {
        if (! is_string($key) || ! (ThemeCatalog::has($key) || \App\Domain\Theme\Models\Theme::query()->where('key', $key)->where('status', 'active')->exists())) {
            throw new InvalidThemeConfigException('Unknown theme: '.(is_string($key) ? $key : '(not a name)'));
        }

        return $key;
    }

    private function validateTokens(array $tokens): array
    {
        $allowedKeys = [...ThemeOptions::COLOR_TOKENS, 'radius', 'font_family', 'heading_font', 'shadow', 'density'];
        $this->assertNoUnknownKeys($tokens, $allowedKeys, 'tokens');

        $clean = [];

        foreach (ThemeOptions::COLOR_TOKENS as $key) {
            if (isset($tokens[$key])) {
                $clean[$key] = $this->validateHexColor($tokens[$key], $key);
            }
        }

        foreach (['radius' => ThemeOptions::RADIUS, 'shadow' => ThemeOptions::SHADOW, 'density' => ThemeOptions::DENSITY] as $key => $allowed) {
            if (isset($tokens[$key])) {
                $clean[$key] = $this->oneOf($tokens[$key], $allowed, $key === 'radius' ? 'radius value' : $key);
            }
        }

        // Module 17 §32 "Typography Security" — no arbitrary external font
        // URL is ever accepted; only pre-approved, self-hosted fonts.
        foreach (['font_family', 'heading_font'] as $key) {
            if (isset($tokens[$key])) {
                if (! in_array($tokens[$key], ThemeOptions::FONTS, true)) {
                    throw new InvalidThemeConfigException('Unsupported font family: '.(is_string($tokens[$key]) ? $tokens[$key] : '(not a name)'));
                }
                $clean[$key] = $tokens[$key];
            }
        }

        return $clean;
    }

    private function validateLayout(array $layout): array
    {
        $this->assertNoUnknownKeys($layout, [...array_keys(ThemeOptions::LAYOUT), ...ThemeOptions::LAYOUT_BOOLEANS], 'layout');

        $clean = [];
        foreach (ThemeOptions::LAYOUT as $key => $allowed) {
            if (array_key_exists($key, $layout)) {
                $value = $key === 'grid_columns' && is_numeric($layout[$key]) ? (int) $layout[$key] : $layout[$key];
                $clean[$key] = $this->oneOf($value, $allowed, "layout.{$key}");
            }
        }
        foreach (ThemeOptions::LAYOUT_BOOLEANS as $key) {
            if (array_key_exists($key, $layout)) {
                $clean[$key] = $this->boolean($layout[$key], "layout.{$key}");
            }
        }

        return $clean;
    }

    private function validateMotion(array $motion): array
    {
        $this->assertNoUnknownKeys($motion, ['profile', 'intensity', ...ThemeOptions::MOTION_BOOLEANS], 'motion');

        $clean = [];
        if (array_key_exists('profile', $motion)) {
            $clean['profile'] = $this->oneOf($motion['profile'], ThemeOptions::MOTION_PROFILES, 'motion.profile');
        }
        if (array_key_exists('intensity', $motion)) {
            $clean['intensity'] = $this->oneOf($motion['intensity'], ThemeOptions::MOTION_INTENSITY, 'motion.intensity');
        }
        foreach (ThemeOptions::MOTION_BOOLEANS as $key) {
            if (array_key_exists($key, $motion)) {
                $clean[$key] = $this->boolean($motion[$key], "motion.{$key}");
            }
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
            $this->assertNoUnknownKeys($this->object($branding['social_links'], 'social_links'), self::ALLOWED_SOCIAL_KEYS, 'social_links');
            $clean['social_links'] = [];
            foreach ($branding['social_links'] as $platform => $url) {
                $clean['social_links'][$platform] = $this->validateSafeUrl($url, "social_links.{$platform}");
            }
        }

        return $clean;
    }

    private function validateSections(array $sections): array
    {
        if (count($sections) > self::MAX_SECTIONS) {
            throw new InvalidThemeConfigException('A home page has at most '.self::MAX_SECTIONS.' sections.');
        }

        $clean = [];

        foreach (array_values($sections) as $index => $section) {
            if (! is_array($section)) {
                throw new InvalidThemeConfigException("Section at index {$index} must be an object.");
            }

            $this->assertNoUnknownKeys($section, ['type', 'position', 'is_visible', 'config'], "sections.{$index}");

            $type = SectionType::tryFrom(is_string($section['type'] ?? null) ? $section['type'] : '');
            if ($type === null) {
                throw new InvalidThemeConfigException("Unsupported section type at index {$index}: ".(is_string($section['type'] ?? null) ? $section['type'] : '(none)'));
            }

            $clean[] = [
                'type' => $type->value,
                'position' => (int) ($section['position'] ?? $index),
                'is_visible' => (bool) ($section['is_visible'] ?? true),
                'config' => $this->validateSectionConfig($type, $this->object($section['config'] ?? [], "sections.{$index}.config")),
            ];
        }

        return $clean;
    }

    private function validateSectionConfig(SectionType $type, array $config): array
    {
        // A small, per-type whitelist — every section type accepts only
        // plain, length-capped strings for its own known fields; never
        // an arbitrary key, never HTML (this is DATA the storefront
        // escapes when it renders it, not markup itself).
        $allowedByType = [
            SectionType::AnnouncementBar->value => ['message'],
            SectionType::Hero->value => ['heading', 'subheading', 'image_url', 'cta_url', 'cta_label'],
            SectionType::PromotionalBanner->value => ['heading', 'subheading', 'image_url', 'cta_url', 'cta_label'],
            SectionType::Newsletter->value => ['heading', 'subheading'],
            SectionType::FeaturedProducts->value => ['heading', 'limit'],
            SectionType::FeaturedCategories->value => ['heading', 'limit'],
            SectionType::Header->value => [],
            SectionType::Footer->value => [],
            SectionType::BestSellers->value => ['heading', 'limit'],
            SectionType::SaleProducts->value => ['heading', 'limit'],
            SectionType::FeaturedBrands->value => ['heading', 'limit'],
            SectionType::Testimonials->value => ['heading', 'items'],
            SectionType::Faq->value => ['heading', 'items'],
            SectionType::RichText->value => ['heading', 'text'],
            SectionType::TrustBadges->value => ['items'],
        ];

        $allowedKeys = $allowedByType[$type->value]; // every SectionType has an entry
        $this->assertNoUnknownKeys($config, $allowedKeys, "sections.{$type->value}.config");

        $clean = [];
        foreach ($allowedKeys as $key) {
            if (! isset($config[$key])) {
                continue;
            }
            $clean[$key] = match (true) {
                in_array($key, ['image_url', 'cta_url'], true) => $this->validateSafeUrl($config[$key], $key),
                $key === 'limit' => max(1, min(50, (int) $config[$key])),
                $key === 'items' => $this->validateItems($type, $config[$key]),
                $key === 'text' => $this->text($config[$key], 'text', 2000),
                $key === 'cta_label' => $this->text($config[$key], 'cta_label', 40),
                default => $this->text($config[$key], $key, 500),
            };
        }

        return $clean;
    }

    /** @return list<array<string, string>> */
    private function validateItems(SectionType $type, mixed $items): array
    {
        [$fields, $max] = match ($type) {
            SectionType::Testimonials => [['quote' => 500, 'name' => 80, 'detail' => 80], 6],
            SectionType::Faq => [['question' => 200, 'answer' => 1000], 12],
            SectionType::TrustBadges => [['icon' => 0, 'title' => 60, 'text' => 120], 4],
            default => [[], 0],
        };
        if (! is_array($items) || ! array_is_list($items) || count($items) > $max) {
            throw new InvalidThemeConfigException("{$type->value} takes a list of at most {$max} items.");
        }

        $clean = [];
        foreach ($items as $i => $item) {
            if (! is_array($item)) {
                throw new InvalidThemeConfigException("{$type->value} item {$i} must be an object.");
            }
            $this->assertNoUnknownKeys($item, array_keys($fields), "{$type->value}.items.{$i}");
            $row = [];
            foreach ($fields as $field => $length) {
                if (! isset($item[$field]) || $item[$field] === '') {
                    continue;
                }
                $row[$field] = $field === 'icon'
                    ? $this->oneOf($item[$field], ThemeOptions::BADGE_ICONS, "{$type->value}.items.{$i}.icon")
                    : $this->text($item[$field], "{$type->value}.items.{$i}.{$field}", $length);
            }
            $required = match ($type) {
                SectionType::Testimonials => ['quote', 'name'],
                SectionType::Faq => ['question', 'answer'],
                default => ['icon', 'title'],
            };
            foreach ($required as $field) {
                if (! isset($row[$field])) {
                    throw new InvalidThemeConfigException("{$type->value} item {$i} needs a {$field}.");
                }
            }
            $clean[] = $row;
        }

        return $clean;
    }

    private function text(mixed $value, string $field, int $max): string
    {
        if (! is_string($value) || mb_strlen($value) > $max) {
            throw new InvalidThemeConfigException("{$field} must be a string of {$max} characters or fewer.");
        }

        return $value;
    }

    /** @param list<string|int> $allowed */
    private function oneOf(mixed $value, array $allowed, string $field): string|int
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidThemeConfigException("Unsupported {$field}: ".(is_scalar($value) ? (string) $value : '(not a value)'));
        }

        return $value;
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new InvalidThemeConfigException("{$field} must be true or false.");
        }

        return $value;
    }

    /** @return array<mixed> */
    private function object(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new InvalidThemeConfigException("{$field} must be an object.");
        }

        return $value;
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
