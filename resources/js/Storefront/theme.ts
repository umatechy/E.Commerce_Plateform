import { createContext, useContext, type CSSProperties } from 'react';
import { fontStack } from './fonts';
import type { Shell } from './types';

/**
 * Module 17 (tokens, layout) and Module 18 (motion) on the storefront — Phase
 * B36. The server sends the resolved presentation (theme defaults, the
 * store's own values, the package applied); this file turns it into CSS
 * variables and data attributes. Components read only these variables, so
 * every theme is the same code (Module 17 §1 "one storefront engine").
 */
export type ThemeLayout = {
  header_style: 'classic' | 'minimal' | 'centered' | 'split';
  container: 'narrow' | 'standard' | 'wide';
  product_card: 'standard' | 'minimal' | 'elevated' | 'overlay';
  grid_columns: 3 | 4;
  hero_style: 'simple' | 'centered' | 'split' | 'fullbleed';
  sticky_header: boolean;
  footer_style: 'simple' | 'columns';
};

export type ThemeMotion = {
  profile: 'none' | 'minimal' | 'standard' | 'premium' | 'playful';
  intensity: 'low' | 'medium' | 'high';
  reveal_on_scroll: boolean;
  hover_effects: boolean;
  /** Premium (from the package, not the store's settings): buttons rise a little on hover. */
  button_hover?: boolean;
};

export const DEFAULT_LAYOUT: ThemeLayout = { header_style: 'classic', container: 'standard', product_card: 'standard', grid_columns: 4, hero_style: 'simple', sticky_header: false, footer_style: 'simple' };
export const DEFAULT_MOTION: ThemeMotion = { profile: 'minimal', intensity: 'medium', reveal_on_scroll: false, hover_effects: true };

const RADIUS: Record<string, [string, string]> = { none: ['0', '0'], sm: ['0.25rem', '0.375rem'], md: ['0.5rem', '0.75rem'], lg: ['0.875rem', '1.25rem'] };
const SHADOW: Record<string, [string, string]> = {
  none: ['none', 'none'],
  subtle: ['0 1px 2px rgb(0 0 0 / 0.06)', '0 4px 12px rgb(0 0 0 / 0.08)'],
  medium: ['0 2px 6px rgb(0 0 0 / 0.08)', '0 10px 24px rgb(0 0 0 / 0.12)'],
  strong: ['0 4px 12px rgb(0 0 0 / 0.12)', '0 16px 36px rgb(0 0 0 / 0.18)'],
};
const DENSITY: Record<string, string> = { compact: '0.75', standard: '1', comfortable: '1.25' };
export const CONTAINER: Record<ThemeLayout['container'], string> = { narrow: 'max-w-5xl', standard: 'max-w-6xl', wide: 'max-w-7xl' };

/**
 * Module 18 §5–8 motion tokens per profile: durations (ms), easing, the
 * distance an element travels in, the lift and zoom on hover. NONE has no
 * motion; MINIMAL only fades; PREMIUM is slower and smoother; PLAYFUL has a
 * small overshoot (no bounce loops, Module 18 §7). Transform and opacity
 * only (§30).
 */
const MOTION: Record<ThemeMotion['profile'], { fast: number; normal: number; ease: string; distance: number; lift: number; scale: number; page: boolean }> = {
  none: { fast: 0, normal: 0, ease: 'linear', distance: 0, lift: 0, scale: 1, page: false },
  minimal: { fast: 120, normal: 180, ease: 'cubic-bezier(0.2, 0, 0, 1)', distance: 0, lift: 0, scale: 1.02, page: false },
  standard: { fast: 150, normal: 260, ease: 'cubic-bezier(0.2, 0, 0, 1)', distance: 14, lift: 2, scale: 1.04, page: true },
  premium: { fast: 220, normal: 480, ease: 'cubic-bezier(0.16, 1, 0.3, 1)', distance: 24, lift: 4, scale: 1.06, page: true },
  playful: { fast: 180, normal: 380, ease: 'cubic-bezier(0.34, 1.4, 0.64, 1)', distance: 28, lift: 6, scale: 1.08, page: true },
};
const INTENSITY: Record<ThemeMotion['intensity'], number> = { low: 0.5, medium: 1, high: 1.4 };

/** Module 18 §23: at most this many items are staggered, and the whole stagger ends within STAGGER_MAX_MS. */
export const STAGGER_ITEMS = 8;
export const STAGGER_MAX_MS = 480;

export function layoutOf(shell: Shell): ThemeLayout {
  return { ...DEFAULT_LAYOUT, ...(shell.theme.layout ?? {}) };
}

export function motionOf(shell: Shell): ThemeMotion {
  return { ...DEFAULT_MOTION, ...(shell.theme.motion ?? {}) };
}

export function motionTokens(motion: ThemeMotion) {
  const profile = MOTION[motion.profile] ?? MOTION.minimal;
  const k = INTENSITY[motion.intensity] ?? 1;

  return {
    ...profile,
    distance: Math.round(profile.distance * k),
    lift: Math.round(profile.lift * k),
    scale: 1 + (profile.scale - 1) * k,
    stagger: Math.min(70, Math.floor(STAGGER_MAX_MS / STAGGER_ITEMS)),
  };
}

/** The storefront root's CSS variables and data attributes. */
export function themeStyle(shell: Shell): { style: CSSProperties; attributes: Record<string, string> } {
  const tokens = shell.theme.tokens ?? {};
  const motion = motionOf(shell);
  const m = motionTokens(motion);
  const [radius, radiusLarge] = RADIUS[tokens.radius ?? 'md'] ?? RADIUS.md;
  const [shadow, shadowHover] = SHADOW[tokens.shadow ?? 'subtle'] ?? SHADOW.subtle;
  const colors = Object.fromEntries(
    Object.entries(tokens)
      .filter(([, value]) => typeof value === 'string' && value.startsWith('#'))
      .map(([key, value]) => [`--sf-${key}`, value]),
  );

  return {
    style: {
      ...colors,
      '--sf-radius': radius,
      '--sf-radius-lg': radiusLarge,
      '--sf-shadow': shadow,
      '--sf-shadow-hover': shadowHover,
      '--sf-density': DENSITY[tokens.density ?? 'standard'] ?? '1',
      '--sf-font-body': fontStack(tokens.font_family),
      '--sf-font-heading': fontStack(tokens.heading_font ?? tokens.font_family),
      '--sf-dur-fast': `${m.fast}ms`,
      '--sf-dur-normal': `${m.normal}ms`,
      '--sf-ease': m.ease,
      '--sf-distance': `${m.distance}px`,
      '--sf-lift': `${-m.lift}px`,
      '--sf-scale': String(m.scale),
      fontFamily: 'var(--sf-font-body)',
    } as CSSProperties,
    attributes: {
      'data-theme': shell.theme.key ?? 'default',
      'data-motion': motion.profile,
      'data-hover': motion.hover_effects && motion.profile !== 'none' ? 'on' : 'off',
      'data-button-motion': motion.button_hover && motion.hover_effects && motion.profile !== 'none' ? 'on' : 'off',
      'data-page-motion': m.page ? 'on' : 'off',
    },
  };
}

/** What components below the layout need from the theme. */
export type StorefrontTheme = { shell: Shell; layout: ThemeLayout; motion: ThemeMotion; reveal: boolean };

export const StorefrontThemeContext = createContext<StorefrontTheme | null>(null);

export function useStorefrontTheme(): StorefrontTheme | null {
  return useContext(StorefrontThemeContext);
}
