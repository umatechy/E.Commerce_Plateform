import { xsrfToken } from '@/lib/xsrf';
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
 * - A signed-in customer is identified by an HttpOnly cookie the browser
 *   sends by itself; this code never sees the token.
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

/** The headers every storefront API call carries: the store, the session marker, the guest cart, and XSRF for writes. */
function requestHeaders(shell: Shell, method: string, extra: Record<string, string> = {}): Record<string, string> {
  // X-Storefront-Request lets the API honour the HttpOnly session cookie
  // (Phase B25); other sites cannot send it without passing CORS.
  const headers: Record<string, string> = { ...extra, 'X-Storefront-Request': '1' };
  if (shell.base_path !== '') headers['X-Store-Slug'] = shell.store.slug;
  const token = cartToken(shell);
  if (token) headers['X-Guest-Cart-Token'] = token;
  const xsrf = xsrfToken();
  if (xsrf && method !== 'GET') headers['X-XSRF-TOKEN'] = xsrf;

  return headers;
}

/**
 * A private file of the API (a return photo, Phase B34) as an object URL
 * for an <img>. Such files have no public address: they need the same
 * headers as every API call, which an <img src> cannot send. Revoke the
 * URL when the image is no longer shown.
 */
export async function storefrontObjectUrl(shell: Shell, path: string, headers: Record<string, string> = {}): Promise<string> {
  const response = await fetch(`/api/v1${path}`, { headers: requestHeaders(shell, 'GET', { ...headers, Accept: 'image/*' }), credentials: 'same-origin' });
  if (!response.ok) throw new StorefrontApiError('The picture could not be loaded.', response.status, {});

  return URL.createObjectURL(await response.blob());
}

export async function storefrontFetch<T = Record<string, unknown>>(
  shell: Shell,
  path: string,
  init: { method?: string; body?: unknown; query?: Record<string, string>; headers?: Record<string, string> } = {},
): Promise<T> {
  const headers = requestHeaders(shell, init.method ?? 'GET', { ...init.headers, Accept: 'application/json' });
  // A FormData body (a file upload) sets its own multipart content type.
  const isForm = typeof FormData !== 'undefined' && init.body instanceof FormData;
  if (init.body !== undefined && !isForm) headers['Content-Type'] = 'application/json';

  const query = init.query ? `?${new URLSearchParams(init.query).toString()}` : '';
  const response = await fetch(`/api/v1${path}${query}`, {
    method: init.method ?? 'GET',
    headers,
    credentials: 'same-origin',
    body: init.body === undefined ? undefined : isForm ? (init.body as FormData) : JSON.stringify(init.body),
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

/** Field errors of a 422, keyed by field. */
export function validationErrors(error: unknown): Record<string, string[]> {
  return error instanceof StorefrontApiError && error.status === 422 ? ((error.body.errors as Record<string, string[]>) ?? {}) : {};
}
