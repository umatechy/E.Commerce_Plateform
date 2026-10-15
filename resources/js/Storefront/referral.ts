import type { Shell } from './types';

/**
 * Phase B57 (Module 28 §8–12) — an affiliate's referral, remembered on this
 * device. The server signs a token when a visitor arrives through an
 * affiliate's link; the shop keeps it for the program's attribution window
 * and sends it with the order. The server checks the signature, the store
 * and the window again, so a changed or copied token earns nothing.
 *
 * Kept in this browser's storage, per store, not in a cookie: it travels
 * only with the order, never with every request. The latest link wins.
 */
type Stored = { token: string; expires: number };

function key(shell: Shell): string {
  return `ut-ref-${shell.store.slug}`;
}

/** Keeps the referral the page arrived with, if any. */
export function rememberReferral(shell: Shell, now = Date.now()): void {
  const referral = shell.referral;
  if (!referral?.token) return;
  try {
    const stored: Stored = { token: referral.token, expires: now + referral.days * 86_400_000 };
    window.localStorage.setItem(key(shell), JSON.stringify(stored));
  } catch {
    /* storage unavailable: the referral counts only if the order is placed on this page */
  }
}

/** The referral to send with an order: the page's own, else a remembered one still in its window. */
export function referralToken(shell: Shell, now = Date.now()): string | null {
  if (shell.referral?.token) return shell.referral.token;
  try {
    const raw = window.localStorage.getItem(key(shell));
    if (raw === null) return null;
    const stored = JSON.parse(raw) as Partial<Stored>;
    if (typeof stored.token !== 'string' || typeof stored.expires !== 'number' || stored.expires <= now) {
      window.localStorage.removeItem(key(shell));

      return null;
    }

    return stored.token;
  } catch {
    return null;
  }
}
