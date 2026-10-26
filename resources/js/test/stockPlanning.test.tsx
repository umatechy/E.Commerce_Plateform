import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import Planning, { type PlanRow } from '@/Pages/Inventory/Planning';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase P4a: stock planning (Business and Premium). */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const row: PlanRow = { product_id: 1, product_variant_id: null, product: 'Rose Attar', variant: null, sku: 'RA-12', available: 14, incoming: 3, sold: 6, per_day: 0.2, days_left: 70, reorder_point: null, suggested: 4, state: 'running_low' };

describe('stock planning', () => {
  it('explains the package to a store without it and asks the server nothing', () => {
    setPage({ ...owner, features: {}, package: { code: 'basic', name: 'Basic' } }, '/stock-planning');
    vi.stubGlobal('fetch', routeFetch({ '/attention': () => json(200, { data: [] }) }));
    render(<Planning />);
    expect(screen.getByText('Stock planning is part of the Business and Premium packages')).toBeTruthy();
  });

  it('shows what to order, with the rate and what is on its way, for the chosen periods', async () => {
    setPage({ ...owner, features: { 'inventory.advanced': true } }, '/stock-planning');
    let asked: URL | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/stock/reorder': (url) => {
        asked = url;
        return json(200, { data: [row] });
      },
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Planning />);

    expect(await screen.findByText('Rose Attar')).toBeTruthy();
    expect(asked!.searchParams.get('cover_days')).toBe('30');
    expect(screen.getByText('Runs out soon')).toBeTruthy();
    expect(screen.getByText('+3 on the way')).toBeTruthy();
    expect(screen.getByText('0.2 a day')).toBeTruthy();
    expect(screen.getByText('How is this worked out?')).toBeTruthy();
  });
});
