import { useCallback, useState } from 'react';

/**
 * List filters that live in the URL (search, status, page, sort), so a
 * reload, a bookmark or a shared link shows the same list. Only filter
 * values go here, never anything private.
 *
 * The address bar is updated in place (no page visit): the page data
 * comes from /api/v1, so nothing has to be fetched from the page route.
 */
export function readUrlState<T extends Record<string, string>>(defaults: T, search: string): T {
  const params = new URLSearchParams(search);
  const state = { ...defaults };
  for (const key of Object.keys(defaults) as (keyof T)[]) {
    const value = params.get(key as string);
    if (value !== null) state[key] = value as T[keyof T];
  }

  return state;
}

/**
 * The query string for a state. Parameters of the current address that
 * are not part of this state are kept (`existing`), so two filter groups
 * on one page (a tab and a list's filters) do not erase each other.
 */
export function toSearch<T extends Record<string, string>>(state: T, defaults: T, existing = ''): string {
  const params = new URLSearchParams(existing);
  for (const [key, value] of Object.entries(state)) {
    if (value !== '' && value !== defaults[key]) params.set(key, value);
    else params.delete(key);
  }
  const text = params.toString();

  return text === '' ? '' : `?${text}`;
}

export function useUrlState<T extends Record<string, string>>(defaults: T): [T, (patch: Partial<T>) => void] {
  const [state, setState] = useState<T>(() => (typeof window === 'undefined' ? defaults : readUrlState(defaults, window.location.search)));

  const update = useCallback(
    (patch: Partial<T>) => {
      setState((current) => {
        const next = { ...current, ...patch } as T;
        if (typeof window !== 'undefined') {
          window.history.replaceState(window.history.state, '', `${window.location.pathname}${toSearch(next, defaults, window.location.search)}`);
        }

        return next;
      });
    },
    // `defaults` is a literal at every call site.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  );

  return [state, update];
}
