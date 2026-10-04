<?php

declare(strict_types=1);

namespace App\Domain\Theme\Support;

/**
 * Module 17 §15 "Theme Architecture", §18 "Theme Selection", Module 04 §32
 * (Phase B36): the platform's system themes — GLOBAL platform data
 * (Module 17 §44). One storefront engine renders all of them from these
 * definitions; no theme has code of its own (§1 "no tenant-specific source
 * forks").
 *
 * Package tiers (owner decision 2026-10-04): Basic has Classic and Minimal,
 * Business adds Modern, Premium has every theme.
 *
 * The `themes` table holds one row per key (ThemeSeeder and migration
 * 2028_09_01_000001 keep it in step); the definition here is the source of
 * the design. The key `default` is the original B15 theme, kept as the key
 * of "Classic" so every existing store stays on it.
 *
 * Colours were chosen to pass WCAG AA for their use: primary carries white
 * button text, accent is used for links on the background.
 */
final class ThemeCatalog
{
    public const DEFAULT = 'default';

    /** @var array<string, array<string, mixed>> */
    private const THEMES = [
        'default' => [
            'name' => 'Classic',
            'description' => 'Clean and familiar: a full header with search, a calm palette and clear product cards.',
            'tier' => 'basic',
            'version' => '2.0.0',
            'performance' => 'light',
            'tokens' => [
                'primary' => '#111827', 'secondary' => '#6B7280', 'accent' => '#1D4ED8', 'background' => '#FFFFFF', 'surface' => '#F9FAFB',
                'text' => '#111827', 'muted' => '#4B5563', 'border' => '#E5E7EB', 'success' => '#15803D', 'warning' => '#B45309', 'error' => '#B91C1C',
                'radius' => 'md', 'font_family' => 'Inter', 'heading_font' => 'Inter', 'shadow' => 'subtle', 'density' => 'standard',
            ],
            'layout' => ['header_style' => 'classic', 'container' => 'standard', 'product_card' => 'standard', 'grid_columns' => 4, 'hero_style' => 'simple', 'sticky_header' => false, 'footer_style' => 'simple'],
            'motion' => ['profile' => 'minimal', 'intensity' => 'medium', 'reveal_on_scroll' => false, 'hover_effects' => true],
        ],
        'minimal' => [
            'name' => 'Minimal',
            'description' => 'Quiet and spacious: black and white, square corners, generous white space.',
            'tier' => 'basic',
            'version' => '1.0.0',
            'performance' => 'light',
            'tokens' => [
                'primary' => '#0A0A0A', 'secondary' => '#525252', 'accent' => '#262626', 'background' => '#FFFFFF', 'surface' => '#FAFAFA',
                'text' => '#0A0A0A', 'muted' => '#525252', 'border' => '#E5E5E5', 'success' => '#15803D', 'warning' => '#B45309', 'error' => '#B91C1C',
                'radius' => 'none', 'font_family' => 'DM Sans', 'heading_font' => 'DM Sans', 'shadow' => 'none', 'density' => 'comfortable',
            ],
            'layout' => ['header_style' => 'minimal', 'container' => 'standard', 'product_card' => 'minimal', 'grid_columns' => 3, 'hero_style' => 'centered', 'sticky_header' => false, 'footer_style' => 'simple'],
            'motion' => ['profile' => 'minimal', 'intensity' => 'low', 'reveal_on_scroll' => false, 'hover_effects' => true],
        ],
        'modern' => [
            'name' => 'Modern',
            'description' => 'Bright and confident: indigo accents, rounded cards, a sticky header and smooth motion.',
            'tier' => 'business',
            'version' => '1.0.0',
            'performance' => 'standard',
            'tokens' => [
                'primary' => '#4338CA', 'secondary' => '#64748B', 'accent' => '#0369A1', 'background' => '#FFFFFF', 'surface' => '#F8FAFC',
                'text' => '#0F172A', 'muted' => '#475569', 'border' => '#E2E8F0', 'success' => '#15803D', 'warning' => '#B45309', 'error' => '#B91C1C',
                'radius' => 'lg', 'font_family' => 'Inter', 'heading_font' => 'Poppins', 'shadow' => 'medium', 'density' => 'standard',
            ],
            'layout' => ['header_style' => 'classic', 'container' => 'wide', 'product_card' => 'standard', 'grid_columns' => 4, 'hero_style' => 'centered', 'sticky_header' => true, 'footer_style' => 'columns'],
            'motion' => ['profile' => 'standard', 'intensity' => 'medium', 'reveal_on_scroll' => true, 'hover_effects' => true],
        ],
        'boutique' => [
            'name' => 'Boutique',
            'description' => 'Elegant and warm: serif headings, a centred logo, gold details and refined motion. For fashion, fragrance and gifts.',
            'tier' => 'premium',
            'version' => '1.0.0',
            'performance' => 'rich',
            'tokens' => [
                'primary' => '#3F2A1D', 'secondary' => '#7A6656', 'accent' => '#8A6212', 'background' => '#FFFAF5', 'surface' => '#F7EFE6',
                'text' => '#2B1D14', 'muted' => '#6B5646', 'border' => '#EADCCC', 'success' => '#3F6212', 'warning' => '#92400E', 'error' => '#9F1239',
                'radius' => 'sm', 'font_family' => 'Lora', 'heading_font' => 'Playfair Display', 'shadow' => 'subtle', 'density' => 'comfortable',
            ],
            'layout' => ['header_style' => 'centered', 'container' => 'standard', 'product_card' => 'elevated', 'grid_columns' => 3, 'hero_style' => 'split', 'sticky_header' => true, 'footer_style' => 'columns'],
            'motion' => ['profile' => 'premium', 'intensity' => 'medium', 'reveal_on_scroll' => true, 'hover_effects' => true],
        ],
        'bold' => [
            'name' => 'Bold',
            'description' => 'Loud and lively: strong colour, big type, image-led cards and playful motion. For sales, gadgets and youth brands.',
            'tier' => 'premium',
            'version' => '1.0.0',
            'performance' => 'rich',
            'tokens' => [
                'primary' => '#BE123C', 'secondary' => '#57534E', 'accent' => '#B45309', 'background' => '#FFFFFF', 'surface' => '#FFF1F2',
                'text' => '#111111', 'muted' => '#57534E', 'border' => '#FECDD3', 'success' => '#15803D', 'warning' => '#B45309', 'error' => '#B91C1C',
                'radius' => 'lg', 'font_family' => 'Inter', 'heading_font' => 'Montserrat', 'shadow' => 'strong', 'density' => 'standard',
            ],
            'layout' => ['header_style' => 'split', 'container' => 'wide', 'product_card' => 'overlay', 'grid_columns' => 4, 'hero_style' => 'fullbleed', 'sticky_header' => true, 'footer_style' => 'columns'],
            'motion' => ['profile' => 'playful', 'intensity' => 'high', 'reveal_on_scroll' => true, 'hover_effects' => true],
        ],
    ];

    /** The entitlement a theme of each tier needs (Module 04 §32); Basic themes need none. */
    public const TIER_FEATURE = ['basic' => null, 'business' => 'themes.business', 'premium' => 'themes.premium'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::THEMES);
    }

    public static function has(string $key): bool
    {
        return isset(self::THEMES[$key]);
    }

    /** @return array<string, mixed> the definition, or Classic's for a theme without one (e.g. added by platform staff) */
    public static function get(string $key): array
    {
        return ['key' => self::has($key) ? $key : self::DEFAULT, ...(self::THEMES[$key] ?? self::THEMES[self::DEFAULT])];
    }

    public static function requiredFeature(string $key): ?string
    {
        return self::TIER_FEATURE[self::get($key)['tier']] ?? null;
    }
}
