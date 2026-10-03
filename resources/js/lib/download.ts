import { AdminApiError, messageForStatus } from './adminApi';

/**
 * Downloads a file the staff API streams (a CSV export) without leaving
 * the page: on success the browser saves the file; on a refusal the
 * caller gets the same AdminApiError as from adminFetch, so the page can
 * say what went wrong instead of opening a JSON error page.
 */
export async function downloadFile(path: string, query: Record<string, string>, fallbackName: string, timeoutMs = 120000): Promise<void> {
  const params = new URLSearchParams(Object.entries(query).filter(([, value]) => value !== ''));
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  let response: Response;
  try {
    response = await fetch(`/api/v1${path}${params.toString() ? `?${params.toString()}` : ''}`, {
      headers: { Accept: 'text/csv, application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      signal: controller.signal,
    });
  } catch (error) {
    throw new AdminApiError(
      error instanceof DOMException && error.name === 'AbortError' ? 'The export took too long. Narrow the filters and try again.' : 'Could not reach the server. Check your connection and try again.',
      0,
      { code: error instanceof DOMException && error.name === 'AbortError' ? 'timeout' : 'network' },
    );
  } finally {
    clearTimeout(timer);
  }

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new AdminApiError(messageForStatus(response.status, body, response.headers.get('Retry-After')), response.status, body);
  }

  const name = response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] ?? fallbackName;
  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement('a');
  link.href = url;
  link.download = name;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
