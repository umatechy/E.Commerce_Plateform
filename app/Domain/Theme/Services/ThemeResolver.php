<?php

declare(strict_types=1);

namespace App\Domain\Theme\Services;

use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Support\ThemeCatalog;
use App\Domain\Theme\Support\ThemeOptions;
use App\Domain\Tenancy\Models\Store;

/**
 * Module 17 §8 (mirrors B13's SeoResolver / B14's
 * DomainResolverService exactly) — the ONE place a rendering layer calls
 * to get a store's resolved theme configuration. Never duplicated across
 * controllers.
 *
 * Phase B36 — Module 17 §39 "Configuration Inheritance" and §51:
 * Platform default (Classic) → selected theme → the store's own values,
 * computed deterministically; the stored configuration is never changed.
 * Then the package (§41, Module 18 §40): a store whose package no longer
 * includes its theme, a premium layout, an animation profile, advanced
 * sections or reordering is shown the nearest included presentation
 * instead, until it upgrades again or chooses something else.
 */
final class ThemeResolver
{
    public function __construct(private readonly ThemeEntitlements $entitlements) {}

    /** The LIVE, public-facing configuration — never the draft. */
    public function resolvePublished(Store $store): ?array
    {
        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->with('theme')->first();

        if ($storeTheme === null || $storeTheme->published_config === null) {
            return null;
        }

        return [
            'config' => $storeTheme->published_config,
            'custom_css' => $storeTheme->custom_css,
            'published_at' => $storeTheme->published_at?->toIso8601String(),
            'theme_key' => $storeTheme->theme?->key,
        ];
    }

    /** Staff-only preview of the unpublished draft — never served on the public resolution path. */
    public function resolveDraft(StoreTheme $storeTheme): array
    {
        return ['config' => $storeTheme->draft_config, 'custom_css' => $storeTheme->custom_css, 'theme_key' => $storeTheme->theme?->key];
    }

    /**
     * The presentation to render: theme, tokens, layout and motion with
     * every inherited value filled in, and the sections to show in order.
     *
     * @param array<string, mixed> $config a stored (validated) configuration
     * @return array{theme: array{key: string, name: string, version: string, performance: string}, tokens: array<string, mixed>, layout: array<string, mixed>, motion: array<string, mixed>, sections: list<array<string, mixed>>}
     */
    public function present(array $config, ?string $storedThemeKey = null): array
    {
        $base = ThemeCatalog::get(ThemeCatalog::DEFAULT);
        $key = is_string($config['theme'] ?? null) ? $config['theme'] : ($storedThemeKey ?? ThemeCatalog::DEFAULT);
        // A theme the package no longer includes falls back to Classic.
        $theme = $this->entitlements->allows(ThemeCatalog::requiredFeature($key)) ? ThemeCatalog::get($key) : $base;

        $tokens = [...$base['tokens'], ...$theme['tokens'], ...($config['tokens'] ?? [])];
        // Owner decision 14: a retired serif font (in an old configuration) renders as its sans-serif replacement.
        foreach (['font_family', 'heading_font'] as $font) {
            if (is_string($tokens[$font] ?? null) && isset(ThemeOptions::RETIRED_FONTS[$tokens[$font]])) {
                $tokens[$font] = ThemeOptions::RETIRED_FONTS[$tokens[$font]];
            }
        }
        $layout = [...$base['layout'], ...$theme['layout'], ...($config['layout'] ?? [])];
        $motion = [...$base['motion'], ...$theme['motion'], ...($config['motion'] ?? [])];

        if (! $this->entitlements->allows('layout.advanced')) {
            foreach (ThemeOptions::PREMIUM_LAYOUT as $field => $premium) {
                if (in_array($layout[$field], $premium, true)) {
                    $layout[$field] = $base['layout'][$field];
                }
            }
        }
        $motion = $this->clampMotion($motion);

        $sections = array_values(array_filter($config['sections'] ?? [], fn (array $s) => $s['is_visible'] ?? true));
        if (! $this->entitlements->allows('homepage.advanced_sections')) {
            $sections = array_values(array_filter($sections, fn (array $s) => ThemeOptions::isBasicSection($s['type'])));
        }
        if ($this->entitlements->allows('homepage.reorder')) {
            usort($sections, fn (array $a, array $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));
        } else {
            $sections = ThemeEntitlements::canonical($sections);
        }

        return [
            'theme' => ['key' => $theme['key'], 'name' => $theme['name'], 'version' => $theme['version'], 'performance' => $theme['performance']],
            'tokens' => $tokens,
            'layout' => $layout,
            'motion' => $motion,
            'sections' => $sections,
        ];
    }

    /**
     * Module 18 §8, §28, §32: the strongest motion the package includes;
     * the storefront also honours prefers-reduced-motion on top of this.
     *
     * @param array<string, mixed> $motion
     * @return array<string, mixed>
     */
    private function clampMotion(array $motion): array
    {
        $order = ['premium' => 'standard', 'playful' => 'standard', 'standard' => 'minimal'];
        while (isset(ThemeOptions::MOTION_PROFILE_FEATURE[$motion['profile']]) && ! $this->entitlements->allows(ThemeOptions::MOTION_PROFILE_FEATURE[$motion['profile']])) {
            $motion['profile'] = $order[$motion['profile']];
        }
        // Owner request 2026-10-07 (Module 18 §13 "button elevation"): Premium buttons rise a little on hover.
        // Decided by the package, never stored in the store's configuration.
        $motion['button_hover'] = $this->entitlements->allows('animation.premium');
        if ($motion['intensity'] === 'high' && ! $this->entitlements->allows('animation.premium')) {
            $motion['intensity'] = 'medium';
        }
        if ($motion['reveal_on_scroll'] && ! $this->entitlements->allows('animation.advanced')) {
            $motion['reveal_on_scroll'] = false;
        }

        return $motion;
    }
}
