import { adminFetch } from './adminApi';

/**
 * UI-25 — script errors in the admin reach Umar Techy (POST /client-errors,
 * shown in Super Admin → Monitoring → Browser errors), so a broken page is
 * known before a seller reports it.
 *
 * Only the error text, its stack and the page path (no query string) are
 * sent — never form values or page data. At most ten reports per page load,
 * each distinct error once; a failed report is dropped silently.
 */
const MAX_REPORTS = 10;
const sent = new Set<string>();
let installed = false;

export function reportError(error: unknown, extra?: string): void {
  if (sent.size >= MAX_REPORTS || typeof window === 'undefined') return;
  const message = (error instanceof Error ? `${error.name}: ${error.message}` : String(error ?? 'Unknown error')).slice(0, 2000);
  const stack = error instanceof Error ? (error.stack ?? '') + (extra ? `\n${extra}` : '') : extra ?? '';
  const key = `${message}|${window.location.pathname}`;
  if (sent.has(key)) return;
  sent.add(key);
  adminFetch('/client-errors', { method: 'POST', body: { message, stack: stack.slice(0, 8000), path: window.location.pathname } }).catch(() => undefined);
}

/** Listens for errors nothing else caught (once per page load). */
export function installErrorReporter(): void {
  if (installed || typeof window === 'undefined') return;
  installed = true;
  window.addEventListener('error', (event) => reportError(event.error ?? event.message));
  window.addEventListener('unhandledrejection', (event) => reportError(event.reason));
}
