import { xsrfToken } from './xsrf';

/**
 * Calls the staff API (/api/v1/...) from an admin page. The staff session
 * is Sanctum-stateful, so every write carries the XSRF token from
 * Laravel's cookie; without it the request is refused with a 419.
 */
export class AdminApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly body: Record<string, unknown>,
  ) {
    super(message);
  }
}

export async function adminFetch<T = Record<string, unknown>>(
  path: string,
  init: { method?: string; body?: unknown; query?: Record<string, string | undefined> } = {},
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (init.body !== undefined) headers['Content-Type'] = 'application/json';
  const method = init.method ?? 'GET';
  const xsrf = xsrfToken();
  if (xsrf && method !== 'GET') headers['X-XSRF-TOKEN'] = xsrf;

  const params = Object.entries(init.query ?? {}).filter((entry): entry is [string, string] => entry[1] !== undefined && entry[1] !== '');
  const query = params.length > 0 ? `?${new URLSearchParams(params).toString()}` : '';
  const response = await fetch(`/api/v1${path}${query}`, {
    method,
    headers,
    credentials: 'same-origin',
    body: init.body !== undefined ? JSON.stringify(init.body) : undefined,
  });
  const body = response.status === 204 ? {} : await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new AdminApiError(String(body.message ?? 'Something went wrong. Please try again.'), response.status, body);
  }

  return body as T;
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
