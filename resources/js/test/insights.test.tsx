import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import Insights from '@/Components/Dashboard/Insights';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-23: the dashboard words the server's insights and links each to its list. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('insights', () => {
  it('words each insight from its numbers', async () => {
    setPage(owner, '/');
    vi.stubGlobal('fetch', routeFetch({
      '/insights': () => json(200, { data: [
        { key: 'selling_out', tone: 'amber', href: '/inventory?stock=low', values: { products: [{ id: 'p1', name: 'Wool Shawl', sold: 4, left: 3 }] } },
        { key: 'cod_cancellations', tone: 'red', href: '/orders?status=cancelled', values: { percent: 30, orders: 10, previous_percent: 12 } },
        { key: 'waiting_long', tone: 'amber', href: '/orders?status=pending_confirmation', values: { count: 2 } },
      ] }),
    }));
    render(<Insights />);

    expect(await screen.findByText('Wool Shawl: 4 sold this week, 3 left')).toBeTruthy();
    expect(screen.getByText('30% of cash-on-delivery orders were cancelled in the last 30 days')).toBeTruthy();
    expect(screen.getByText('The 30 days before: 12%')).toBeTruthy();
    expect(screen.getByRole('link', { name: /2 orders have waited more than a day/ }).getAttribute('href')).toBe('/orders?status=pending_confirmation');
  });

  it('shows nothing when there is nothing to say', async () => {
    setPage(owner, '/');
    vi.stubGlobal('fetch', routeFetch({ '/insights': () => json(200, { data: [] }) }));
    const { container } = render(<Insights />);
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(container.textContent).toBe('');
  });
});
