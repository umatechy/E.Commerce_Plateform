import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-25: a page that breaks shows a way out, and the error is reported. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

function Broken(): never {
  throw new Error('Kaboom in the orders page');
}

describe('a page that breaks', () => {
  it('keeps the frame, says what happened, and reports the error', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    setPage(owner, '/orders');
    let reported: { message?: string; path?: string } | null = null;
    vi.stubGlobal('fetch', routeFetch({
      'POST /client-errors': (_u, init) => { reported = JSON.parse(String(init.body)); return new Response(null, { status: 204 }); },
      '/attention': () => json(200, { data: [] }),
    }));
    render(<AuthenticatedLayout><Broken /></AuthenticatedLayout>);

    expect(screen.getByRole('heading', { name: 'This page could not be shown' })).toBeTruthy();
    expect(screen.getByRole('navigation', { name: 'Admin' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Go to the dashboard' }).getAttribute('href')).toBe('/');
    await waitFor(() => expect(reported).toMatchObject({ message: 'Error: Kaboom in the orders page', path: '/' }));
    expect(screen.getByRole('button', { name: 'Try again' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
  });
});
