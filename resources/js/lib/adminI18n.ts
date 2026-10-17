import { useCallback, useSyncExternalStore } from 'react';
import { adminUr } from './adminUr';
import { savePreference } from './preferences';

/**
 * UI-18 — the admin in English or Urdu (اردو, right to left), chosen by each
 * user in the account menu and kept on this browser (like the theme colour).
 *
 * Same rule as the storefront translator (Storefront/i18n): the English text
 * is the key, `t('Orders')`; a text without an Urdu entry shows in English.
 * Placeholders are written `{name}`. Business identifiers — order numbers,
 * SKUs, amounts, store and product names — are never passed through it.
 * resources/js/test/adminI18n.test.ts fails when a text used with t() in the
 * admin has no Urdu entry.
 */
export type AdminLanguage = 'en' | 'ur';
export type AdminTranslate = (text: string, values?: Record<string, string | number>) => string;

export const ADMIN_LANGUAGES: { id: AdminLanguage; label: string; native: string }[] = [
  { id: 'en', label: 'English', native: 'English' },
  { id: 'ur', label: 'Urdu', native: 'اردو' },
];

const KEY = 'ut.admin-language';
const listeners = new Set<() => void>();
let current: AdminLanguage | null = null;

function read(): AdminLanguage {
  if (current !== null) return current;
  try {
    current = window.localStorage.getItem(KEY) === 'ur' ? 'ur' : 'en';
  } catch {
    current = 'en';
  }

  return current;
}

/** The admin language outside React (e.g. lib/datetime). */
export function currentAdminLanguage(): AdminLanguage {
  return typeof window === 'undefined' ? 'en' : read();
}

/** `save`: also keep it with the account (false when the account is where it came from). */
export function setAdminLanguage(language: AdminLanguage, save = true): void {
  current = language;
  try {
    window.localStorage.setItem(KEY, language);
  } catch {
    // Storage blocked: the language still applies for this visit.
  }
  listeners.forEach((listener) => listener());
  if (save) savePreference({ language });
}

export function adminTranslate(language: AdminLanguage, text: string, values?: Record<string, string | number>): string {
  // In Urdu, a text with no entry stays English and is isolated left to right, so its full stop and words keep their order.
  const phrase = language === 'ur' ? (adminUr[text] ?? (text === '' ? text : `\u2066${text}\u2069`)) : text;

  return values ? phrase.replace(/\{(\w+)\}/g, (match, key: string) => (key in values ? String(values[key]) : match)) : phrase;
}

export function useAdminLanguage(): AdminLanguage {
  return useSyncExternalStore(
    (listener) => {
      listeners.add(listener);

      return () => listeners.delete(listener);
    },
    read,
    () => 'en',
  );
}

/** t() for the admin language; stable while the language stays the same. */
export function useAdminT(): AdminTranslate {
  const language = useAdminLanguage();

  return useCallback<AdminTranslate>((text, values) => adminTranslate(language, text, values), [language]);
}

/** For tests. */
export function resetAdminLanguage(): void {
  current = null;
}
