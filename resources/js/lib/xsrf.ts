/** Laravel's XSRF-TOKEN cookie, sent back as X-XSRF-TOKEN on writes (Sanctum stateful requests). */
export function xsrfToken(): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

  return match ? decodeURIComponent(match[1]) : null;
}
