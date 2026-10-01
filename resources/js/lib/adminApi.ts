import { xsrfToken } from './xsrf';

/**
 * Calls the staff API (/api/v1/...) from an admin page. The staff session
 * is Sanctum-stateful, so every write carries the XSRF token from
 * Laravel's cookie; without it the request is refused with a 419.
 *
 * Phase B31 (G6): one place decides what a failed call means for the
 * person looking at the page. A request never hangs (timeout), a server
 * fault never shows its own text, and a call that needs the password
 * again (step-up) asks for it and then carries on.
 */
export class AdminApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly body: Record<string, unknown>,
  ) {
    super(message);
  }

  /** The API's machine-readable reason, e.g. `usage_limit_exceeded`. */
  get code(): string | undefined {
    return typeof this.body.code === 'string' ? this.body.code : undefined;
  }
}

export const DEFAULT_TIMEOUT_MS = 15000;

type Query = Record<string, string | number | boolean | undefined | null>;
type Init = { method?: string; body?: unknown; query?: Query; timeoutMs?: number };

/**
 * Asks the user for the password (and the two-step code) again. Resolves
 * true once the server accepted them, false when the user cancelled. The
 * admin layout registers the dialog that does this.
 */
type StepUpHandler = () => Promise<boolean>;
let stepUpHandler: StepUpHandler | null = null;

export function registerStepUpHandler(handler: StepUpHandler | null): void {
  stepUpHandler = handler;
}

/** What to tell the user for a status, when the API's own message is not one to show. */
export function messageForStatus(status: number, body: Record<string, unknown>, retryAfter?: string | null): string {
  const own = typeof body.message === 'string' && body.message !== '' ? body.message : null;

  if (status === 401) return 'Your session has ended. Sign in again.';
  if (status === 419) return 'Your session expired. Reload the page and try again.';
  if (status === 403) {
    // Laravel's bare refusals say nothing useful to a person.
    return own && !/^(this action is unauthorized\.?|forbidden)$/i.test(own) ? own : 'You do not have permission to do this.';
  }
  if (status === 404) return 'This item was not found. It may have been removed.';
  if (status === 429) {
    const seconds = Number(retryAfter);

    return Number.isFinite(seconds) && seconds > 0
      ? `Too many requests. Try again in ${Math.ceil(seconds)} seconds.`
      : 'Too many requests. Wait a moment and try again.';
  }
  // A server fault: never its own text (it can carry internals).
  if (status >= 500) return 'Something went wrong on our side. Please try again.';

  return own ?? 'Something went wrong. Please try again.';
}

function buildUrl(path: string, query?: Query): string {
  const params = Object.entries(query ?? {})
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .map(([key, value]) => [key, String(value)]);

  return `/api/v1${path}${params.length > 0 ? `?${new URLSearchParams(params).toString()}` : ''}`;
}

async function send(path: string, init: Init): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  const method = init.method ?? 'GET';
  const isForm = typeof FormData !== 'undefined' && init.body instanceof FormData;
  if (init.body !== undefined && !isForm) headers['Content-Type'] = 'application/json';
  const xsrf = xsrfToken();
  if (xsrf && method !== 'GET') headers['X-XSRF-TOKEN'] = xsrf;

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), init.timeoutMs ?? DEFAULT_TIMEOUT_MS);

  try {
    return await fetch(buildUrl(path, init.query), {
      method,
      headers,
      credentials: 'same-origin',
      signal: controller.signal,
      body: init.body === undefined ? undefined : isForm ? (init.body as FormData) : JSON.stringify(init.body),
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw new AdminApiError('The server took too long to answer. Check your connection and try again.', 0, { code: 'timeout' });
    }
    throw new AdminApiError('Could not reach the server. Check your connection and try again.', 0, { code: 'network' });
  } finally {
    clearTimeout(timer);
  }
}

export async function adminFetch<T = Record<string, unknown>>(path: string, init: Init = {}, isRetry = false): Promise<T> {
  const response = await send(path, init);
  const body: Record<string, unknown> = response.status === 204 ? {} : await response.json().catch(() => ({}));

  if (response.ok) return body as T;

  // Step-up (Module 30 §6): the server wants the password again for this
  // action. Ask once, then repeat the same request.
  if (response.status === 403 && body.code === 'step_up_required' && !isRetry && stepUpHandler !== null) {
    if (await stepUpHandler()) return adminFetch<T>(path, init, true);
    throw new AdminApiError('Cancelled. Nothing was changed.', 403, { code: 'step_up_cancelled' });
  }

  // The session is gone: the sign-in page is the only useful place.
  if (response.status === 401 && typeof window !== 'undefined' && !path.startsWith('/auth/')) {
    window.location.assign('/login');
  }

  throw new AdminApiError(messageForStatus(response.status, body, response.headers.get('Retry-After')), response.status, body);
}

/** The first validation message of a 422, or the error's own message. */
export function adminErrorMessage(error: unknown, fallback = 'Something went wrong. Please try again.'): string {
  if (error instanceof AdminApiError) {
    const errors = error.body.errors as Record<string, string[]> | undefined;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;

    return first ?? error.message;
  }

  return fallback;
}

/** A 422's messages by field (the first of each). Empty for any other error. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof AdminApiError) || error.status !== 422) return {};
  const errors = error.body.errors as Record<string, string[]> | undefined;

  return errors ? Object.fromEntries(Object.entries(errors).map(([key, messages]) => [key, messages[0]])) : {};
}

/** True when the user closed the step-up dialog: nothing happened, so nothing needs reporting. */
export function wasCancelled(error: unknown): boolean {
  return error instanceof AdminApiError && error.code === 'step_up_cancelled';
}

/** A new key per user action, so a double submit of the same action is one action server-side. */
export function idempotencyKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}
