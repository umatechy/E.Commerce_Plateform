import type { Shell } from './types';

/**
 * Calls the storefront's own JSON APIs (cart, shipping quote, checkout,
 * search suggestions) from the shopper's browser.
 *
 * - On /shop/{slug} the store is named with X-Store-Slug; on a custom
 *   domain the server resolves it from the Host.
 * - A guest cart is identified by its X-Guest-Cart-Token (Module 11
 *   §63), kept per store in localStorage — it is the cart's credential.
 * - Same-origin requests are Sanctum-stateful, so writes carry the
 *   XSRF token from Laravel's cookie.
 */
export class StorefrontApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly body: Record<string, unknown>,
  ) {
    super(message);
  }
}

function tokenKey(shell: Shell): string {
  return `storefront:${shell.store.slug}:cart-token`;
}

export function cartToken(shell: Shell): string | null {
  try {
    return window.localStorage.getItem(tokenKey(shell));
  } catch {
    return null;
  }
}

export function forgetCart(shell: Shell): void {
  try {
    window.localStorage.removeItem(tokenKey(shell));
  } catch {
    /* storage unavailable: nothing to forget */
  }
}

function rememberCart(shell: Shell, token: string | null | undefined): void {
  if (!token) return;
  try {
    window.localStorage.setItem(tokenKey(shell), token);
  } catch {
    /* storage unavailable: the cart lives for this page only */
  }
}

function xsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

  return match ? decodeURIComponent(match[1]) : null;
}

export async function storefrontFetch<T = Record<string, unknown>>(
  shell: Shell,
  path: string,
  init: { method?: string; body?: unknown; query?: Record<string, string> } = {},
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (shell.base_path !== '') headers['X-Store-Slug'] = shell.store.slug;
  const token = cartToken(shell);
  if (token) headers['X-Guest-Cart-Token'] = token;
  if (init.body !== undefined) headers['Content-Type'] = 'application/json';
  const xsrf = xsrfToken();
  if (xsrf && init.method && init.method !== 'GET') headers['X-XSRF-TOKEN'] = xsrf;

  const query = init.query ? `?${new URLSearchParams(init.query).toString()}` : '';
  const response = await fetch(`/api/v1${path}${query}`, {
    method: init.method ?? 'GET',
    headers,
    credentials: 'same-origin',
    body: init.body !== undefined ? JSON.stringify(init.body) : undefined,
  });

  rememberCart(shell, response.headers.get('X-Guest-Cart-Token'));
  const body = response.status === 204 ? {} : await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new StorefrontApiError(String(body.message ?? 'Something went wrong. Please try again.'), response.status, body);
  }

  const guestToken = (body as { data?: { guest_token?: string } }).data?.guest_token;
  rememberCart(shell, guestToken);

  return body as T;
}

/** The first validation message of a 422, or the error's own message. */
export function errorMessage(error: unknown): string {
  if (error instanceof StorefrontApiError) {
    const errors = error.body.errors as Record<string, string[]> | undefined;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;

    return first ?? error.message;
  }

  return 'Something went wrong. Please try again.';
}

export function storeHref(shell: Shell, path = ''): string {
  return `${shell.base_path}${path}` || '/';
}
