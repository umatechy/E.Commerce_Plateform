/**
 * UI-7 — the admin's three colour themes. A per-browser preference: kept in
 * localStorage (wrapped, since storage can be blocked) and applied as
 * data-admin-theme on <html>, which switches the --brand-* colours in
 * app.css. Indigo is the default and what a blocked storage falls back to.
 */
import { savePreference } from './preferences';

export type ThemeId = 'indigo' | 'emerald' | 'slate';

export const THEMES: { id: ThemeId; label: string; swatch: string }[] = [
  { id: 'indigo', label: 'Indigo', swatch: '#4f46e5' },
  { id: 'emerald', label: 'Emerald', swatch: '#047857' },
  { id: 'slate', label: 'Slate', swatch: '#1e293b' },
];

const KEY = 'ut.admin-theme';
const listeners = new Set<(theme: ThemeId) => void>();

function isTheme(value: unknown): value is ThemeId {
  return THEMES.some((t) => t.id === value);
}

export function storedTheme(): ThemeId {
  try {
    const value = window.localStorage.getItem(KEY);

    return isTheme(value) ? value : 'indigo';
  } catch {
    return 'indigo';
  }
}

function apply(theme: ThemeId): void {
  if (typeof document !== 'undefined') document.documentElement.dataset.adminTheme = theme;
}

export function applyStoredTheme(): void {
  apply(storedTheme());
}

/** `save`: also keep it with the account (false when the account is where it came from). */
export function setTheme(theme: ThemeId, save = true): void {
  try {
    window.localStorage.setItem(KEY, theme);
  } catch {
    // Storage blocked: the theme still applies for this visit.
  }
  apply(theme);
  listeners.forEach((listener) => listener(theme));
  if (save) savePreference({ theme });
}

export function isThemeId(value: unknown): value is ThemeId {
  return isTheme(value);
}

export function onThemeChange(listener: (theme: ThemeId) => void): () => void {
  listeners.add(listener);

  return () => listeners.delete(listener);
}

/**
 * UI-22 — light or dark admin, or the device's setting (system). Kept like
 * the theme colour: on this browser and with the account.
 */
export type ModeId = 'light' | 'dark' | 'system';

export const MODES: { id: ModeId; label: string }[] = [
  { id: 'light', label: 'Light' },
  { id: 'dark', label: 'Dark' },
  { id: 'system', label: 'System' },
];

const MODE_KEY = 'ut.admin-mode';
const modeListeners = new Set<(mode: ModeId) => void>();

export function isModeId(value: unknown): value is ModeId {
  return value === 'light' || value === 'dark' || value === 'system';
}

export function storedMode(): ModeId {
  try {
    const value = window.localStorage.getItem(MODE_KEY);

    return isModeId(value) ? value : 'light';
  } catch {
    return 'light';
  }
}

export function setMode(mode: ModeId, save = true): void {
  try {
    window.localStorage.setItem(MODE_KEY, mode);
  } catch {
    // Storage blocked: the mode still applies for this visit.
  }
  modeListeners.forEach((listener) => listener(mode));
  if (save) savePreference({ mode });
}

export function onModeChange(listener: (mode: ModeId) => void): () => void {
  modeListeners.add(listener);

  return () => modeListeners.delete(listener);
}

/** Whether the admin shows dark: chosen, or the device prefers it under "system". */
export function isDark(mode: ModeId): boolean {
  if (mode === 'dark') return true;
  if (mode === 'light') return false;
  try {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
  } catch {
    return false;
  }
}
