import { useEffect, useSyncExternalStore } from 'react';
import { router } from '@inertiajs/react';

/**
 * UI-13 — "you have unsaved changes" for in-app navigation as well as for
 * closing the tab. A form that has changes registers here (useUnsavedWarning
 * in lib/useForm). While any is registered:
 *
 * - closing, reloading or typing an address: the browser's own question;
 * - an Inertia visit to another page (a menu link, a button): the visit is
 *   held and the admin layout shows a "Leave without saving?" dialog. Leave
 *   repeats the visit; Stay drops it.
 *
 * Partial reloads (`only: [...]`), visits to the same page and non-GET
 * visits (the form saving itself) are never held.
 */
const dirtyForms = new Set<symbol>();
let pending: string | null = null;
let bypass = false;
const listeners = new Set<() => void>();

function emit(): void {
  listeners.forEach((listener) => listener());
}

export function registerDirty(): () => void {
  const id = Symbol('dirty-form');
  dirtyForms.add(id);

  return () => {
    dirtyForms.delete(id);
  };
}

export function hasUnsavedChanges(): boolean {
  return dirtyForms.size > 0;
}

/** The address of the visit being held, or null. */
export function usePendingLeave(): string | null {
  return useSyncExternalStore(
    (listener) => {
      listeners.add(listener);

      return () => listeners.delete(listener);
    },
    () => pending,
    () => pending,
  );
}

export function stay(): void {
  pending = null;
  emit();
}

export function leave(): void {
  const url = pending;
  pending = null;
  emit();
  if (url === null) return;
  bypass = true;
  dirtyForms.clear();
  router.visit(url, { onFinish: () => { bypass = false; } });
}

/** Installed once by the admin layout: holds visits away from a page with unsaved changes. */
export function useLeaveGuard(): void {
  useEffect(() => {
    return router.on('before', (event) => {
      const visit = event.detail.visit;
      if (bypass || dirtyForms.size === 0) return;
      if (visit.method !== 'get' || (visit.only?.length ?? 0) > 0) return;
      const target = new URL(visit.url.toString(), window.location.origin);
      if (target.pathname === window.location.pathname) return;
      event.preventDefault();
      pending = target.pathname + target.search + target.hash;
      emit();
    });
  }, []);
}
