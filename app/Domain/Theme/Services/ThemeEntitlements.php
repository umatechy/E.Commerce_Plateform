<?php

declare(strict_types=1);

namespace App\Domain\Theme\Services;

use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Theme\Support\ThemeCatalog;
use App\Domain\Theme\Support\ThemeOptions;

/**
 * Module 17 §41, Module 18 §40, Module 04 §32–33 (Phase B36): what a store's
 * package allows in its theme.
 *
 * - violations(): what a configuration uses that the package does not
 *   include — saving or publishing it is refused (the server decides; the
 *   admin page only mirrors it).
 * - normalizeOrder(): a store without `homepage.reorder` has its sections
 *   in the fixed order (ThemeOptions::CANONICAL_ORDER).
 * - allows(): one feature, for the resolver's fallback when a package was
 *   downgraded after the theme was published. The stored configuration is
 *   never changed (Module 17 §2.17 "preserve theme configuration during
 *   package changes"); only what is rendered falls back.
 */
final class ThemeEntitlements
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function allows(?string $feature): bool
    {
        return $feature === null || $this->entitlements->hasFeature($feature);
    }

    /**
     * @param array<string, mixed> $config a validated configuration
     * @return list<string> one sentence per thing the package does not include
     */
    public function violations(array $config): array
    {
        $problems = [];

        $theme = $config['theme'] ?? null;
        if (is_string($theme) && ! $this->allows(ThemeCatalog::requiredFeature($theme))) {
            $problems[] = 'The theme "'.ThemeCatalog::get($theme)['name'].'" is not included in your package.';
        }

        if (! $this->allows('layout.advanced')) {
            foreach (ThemeOptions::PREMIUM_LAYOUT as $field => $values) {
                if (in_array($config['layout'][$field] ?? null, $values, true)) {
                    $problems[] = "The {$this->label($field)} \"{$config['layout'][$field]}\" is a premium layout.";
                }
            }
        }

        $profile = $config['motion']['profile'] ?? null;
        if (is_string($profile) && ! $this->allows(ThemeOptions::MOTION_PROFILE_FEATURE[$profile] ?? null)) {
            $problems[] = "The animation profile \"{$profile}\" is not included in your package.";
        }
        if (($config['motion']['intensity'] ?? null) === 'high' && ! $this->allows('animation.premium')) {
            $problems[] = 'High animation intensity is a premium animation setting.';
        }
        if (($config['motion']['reveal_on_scroll'] ?? false) === true && ! $this->allows('animation.advanced')) {
            $problems[] = 'Reveal-on-scroll animation is not included in your package.';
        }

        if (! $this->allows('homepage.advanced_sections')) {
            foreach ($config['sections'] ?? [] as $section) {
                if (! ThemeOptions::isBasicSection($section['type'])) {
                    $problems[] = 'The home page section "'.str_replace('_', ' ', $section['type']).'" is not included in your package.';
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return list<array<string, mixed>> positions renumbered; the fixed order unless the store may reorder
     */
    public function normalizeOrder(array $sections): array
    {
        if (! $this->allows('homepage.reorder')) {
            $sections = self::canonical($sections);
        } else {
            usort($sections, fn (array $a, array $b) => $a['position'] <=> $b['position']);
        }

        return array_map(fn (array $section, int $i) => [...$section, 'position' => $i], $sections, array_keys($sections));
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return list<array<string, mixed>>
     */
    public static function canonical(array $sections): array
    {
        $rank = array_flip(ThemeOptions::CANONICAL_ORDER);
        $indexed = array_map(fn (array $s, int $i) => [$s, $i], $sections, array_keys($sections));
        usort($indexed, fn (array $a, array $b) => [$rank[$a[0]['type']] ?? 99, $a[0]['position'] ?? $a[1], $a[1]] <=> [$rank[$b[0]['type']] ?? 99, $b[0]['position'] ?? $b[1], $b[1]]);

        return array_map(fn (array $pair) => $pair[0], $indexed);
    }

    private function label(string $field): string
    {
        return ['header_style' => 'header style', 'product_card' => 'product card style', 'hero_style' => 'hero style'][$field] ?? $field;
    }
}
