import type { ReactNode } from 'react';
import { vi } from 'vitest';
import type { AuthProps } from '@/lib/access';

/**
 * A stand-in for @inertiajs/react in component tests: the page's shared
 * props and URL are whatever the test sets, links are plain anchors, and
 * router calls are recorded. Used as
 *
 *   vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);
 */
const page: { props: { auth: AuthProps }; url: string } = { props: { auth: { user: null, activeStore: null } }, url: '/' };

export const routerMock = { visit: vi.fn(), reload: vi.fn(), get: vi.fn(), on: vi.fn() };

export function setPage(auth: AuthProps, url = '/'): void {
  page.props = { auth };
  page.url = url;
}

export const owner: AuthProps = {
  user: { id: '01JOWNER', name: 'GM Awan', email: 'owner@example.com', mfa_enabled: true },
  activeStore: { id: 5, name: 'Ittar Waly', slug: 'ittar-waly' },
  timezone: 'Asia/Karachi',
  mfa_enrollment_required: false,
  permissions: [],
  is_owner: true,
  features: {},
  package: { code: 'basic', name: 'Basic' },
  stores: [{ id: 5, name: 'Ittar Waly' }],
  currency: 'USD',
};

export const inertiaMock = {
  usePage: () => page,
  router: routerMock,
  Link: ({ href, children, onClick, ...rest }: { href: string; children: ReactNode; onClick?: () => void }) => (
    <a
      href={href}
      onClick={(event) => {
        event.preventDefault();
        onClick?.();
      }}
      {...rest}
    >
      {children}
    </a>
  ),
};

/** A JSON response as fetch returns it. */
export function json(status: number, body: unknown = {}): Response {
  return new Response(status === 204 ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/**
 * A fetch that answers by path. A route is the start of the URL after
 * /api/v1, optionally with the method in front ("POST /brands"). The
 * first matching route wins; an unknown request fails the test loudly.
 */
export function routeFetch(routes: Record<string, (url: URL, init: RequestInit) => Response | Promise<Response>>) {
  return vi.fn(async (input: string, init: RequestInit = {}) => {
    const url = new URL(input, 'http://localhost');
    const path = url.pathname.replace('/api/v1', '');
    const method = init.method ?? 'GET';
    const match = Object.keys(routes).find((key) => {
      const [first, second] = key.split(' ');
      const [wantedMethod, wantedPath] = second === undefined ? ['GET', first] : [first, second];

      return wantedMethod === method && path === wantedPath;
    });
    if (!match) throw new Error(`Unexpected request in test: ${method} ${path}`);

    return routes[match](url, init);
  });
}
