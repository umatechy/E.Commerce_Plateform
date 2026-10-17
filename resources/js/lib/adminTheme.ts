/**
 * UI-7 — the admin's three colour themes. A per-browser preference: kept in
 * localStorage (wrapped, since storage can be blocked) and applied as
 * data-admin-theme on <html>, which switches the --brand-* colours in
 * app.css. Indigo is the default and what a blocked storage falls back to.
 */
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

export function setTheme(theme: ThemeId): void {
  try {
    window.localStorage.setItem(KEY, theme);
  } catch {
    // Storage blocked: the theme still applies for this visit.
  }
  apply(theme);
  listeners.forEach((listener) => listener(theme));
}

export function onThemeChange(listener: (theme: ThemeId) => void): () => void {
  listeners.add(listener);

  return () => listeners.delete(listener);
}
