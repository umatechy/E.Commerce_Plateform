import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen } from '@testing-library/react';
import AppLayer from '@/Components/Storefront/AppLayer';
import { owner, setPage } from '@/test/inertiaMock';
import type { Shell } from '@/Storefront/types';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B55 (Module 25 §21, §29): the store app's offline notice and its phone navigation. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const shell = {
  base_path: '/shop/acme',
  store: { slug: 'acme', name: 'Acme', currency: 'PKR' },
  pwa: { enabled: true, manifest_url: '/shop/acme/manifest.webmanifest', worker_url: '/shop/acme/sw.js', scope: '/shop/acme/', theme_color: '#111111', icon: null, install_prompt: false, mobile_nav: 'always', push_key: null },
} as unknown as Shell;

describe('Store app layer', () => {
  it('shows the phone navigation and says when the shop cannot be reached', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('offline'); }));
    setPage(owner, '/shop/acme/products');
    render(<AppLayer shell={shell} />);

    const nav = screen.getByRole('navigation', { name: 'App navigation' });
    expect(nav.querySelector('a[aria-current="page"]')?.getAttribute('href')).toBe('/shop/acme/products');
    expect(screen.getByRole('link', { name: /Cart/ }).getAttribute('href')).toBe('/shop/acme/cart');
    expect(screen.queryByText(/You are offline/)).toBeNull();

    await act(async () => {
      window.dispatchEvent(new Event('offline'));
    });
    expect(screen.getByText(/You are offline. Pages you saw before may be out of date/)).toBeTruthy();
  });

  it('shows no app navigation when the store keeps it off', () => {
    setPage(owner, '/shop/acme');
    render(<AppLayer shell={{ ...shell, pwa: { ...shell.pwa!, mobile_nav: 'off' } }} />);

    expect(screen.queryByRole('navigation', { name: 'App navigation' })).toBeNull();
  });
});
