import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import AttentionBell from '@/Components/AttentionBell';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-17: the header bell lists what needs attention, each a link to its list. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('needs attention', () => {
  it('shows how many things wait and links each to its list', async () => {
    setPage(owner, '/');
    vi.stubGlobal('fetch', routeFetch({
      '/attention': () => json(200, { data: [
        { key: 'invoice_overdue', count: 1, tone: 'red', href: '/billing' },
        { key: 'orders_to_confirm', count: 3, tone: 'amber', href: '/orders?status=pending_confirmation' },
      ] }),
    }));
    render(<AttentionBell />);

    const bell = await screen.findByRole('button', { name: 'Needs attention: 2 items' });
    fireEvent.click(bell);
    expect(screen.getByRole('link', { name: /3 orders to confirm/ }).getAttribute('href')).toBe('/orders?status=pending_confirmation');
    expect(screen.getByRole('link', { name: /Your Umar Techy invoice is overdue/ }).getAttribute('href')).toBe('/billing');
  });

  it('says all is caught up when nothing waits', async () => {
    setPage(owner, '/');
    vi.stubGlobal('fetch', routeFetch({ '/attention': () => json(200, { data: [] }) }));
    render(<AttentionBell />);

    fireEvent.click(await screen.findByRole('button', { name: 'Needs attention: nothing' }));
    expect(screen.getByText('All caught up')).toBeTruthy();
  });
});
