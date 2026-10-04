import { usePage } from '@inertiajs/react';
import { ur } from './i18n/ur';

/**
 * SRS LOC-001/006, Module 05 §41 (Phase B38): the storefront's own words in
 * the visitor's language.
 *
 * The English text is the key: `t('Add to cart')`. A dictionary per language
 * maps it; a text without an entry is shown in English (locale fallback,
 * Module 35 §4.3). Placeholders are written `{name}`:
 * `t('{count} products', { count: 5 })`. A test checks that every text used
 * with t() in the storefront has an Urdu entry.
 *
 * Store content (product names, theme texts) is translated on the server;
 * this file is only for the interface. Business identifiers — order numbers,
 * SKUs, prices — are never passed through it (LOC-003).
 */
const DICTIONARIES: Record<string, Record<string, string>> = { ur };

export type Translate = (text: string, values?: Record<string, string | number>) => string;

export function translate(locale: string | undefined, text: string, values?: Record<string, string | number>): string {
  const phrase = (locale && DICTIONARIES[locale]?.[text]) || text;

  return values ? phrase.replace(/\{(\w+)\}/g, (match, key: string) => (key in values ? String(values[key]) : match)) : phrase;
}

type LanguageProps = { storefront?: { language?: { current?: string; dir?: string } } };

/** The current storefront language code ('en' outside a storefront page). */
export function useLocale(): string {
  const props = usePage().props as LanguageProps;

  return props.storefront?.language?.current ?? 'en';
}

/** t() for the current page's language. */
export function useT(): Translate {
  const locale = useLocale();

  return (text, values) => translate(locale, text, values);
}

export function isRtl(locale: string): boolean {
  return locale === 'ur' || locale === 'ar';
}
