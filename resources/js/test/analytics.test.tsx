import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import Advanced, { Change, type Overview } from '@/Pages/Analytics/Advanced';
import Reports from '@/Pages/Analytics/Reports';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase P3: advanced reports (Business) and advanced analytics (Premium). */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const withFeatures = (features: Record<string, boolean>, url: string) => setPage({ ...owner, currency: 'PKR', features, package: { code: 'x', name: 'Basic' } }, url);

const overview = (overrides: Partial<Overview> = {}): Overview => ({
  period: { start: '2026-09-27T00:00:00Z', end: '2026-10-26T23:59:59Z' },
  comparison: { kind: 'previous_period', start: '2026-08-28T00:00:00Z', end: '2026-09-26T23:59:59Z' },
  financial: true,
  currency: 'PKR',
  kpis: {
    net_sales_minor: { current: 500000, previous: 400000, change_percent: 25 },
    orders: { current: 3, previous: 4, change_percent: -25 },
    average_order_value_minor: { current: 200000, previous: null, change_percent: null },
    ordering_customers: { current: 3, previous: 3, change_percent: 0 },
    new_customers: { current: 1, previous: 2, change_percent: -50 },
    returning_customers: { current: 1, previous: 1, change_percent: 0 },
  },
  sales: { gross_minor: 600000, discounts_minor: 0, refunds_minor: 100000, net_minor: 500000, shipping_minor: 0, tax_minor: 0, total_minor: 600000 },
  customers: { guest_orders: 1, registered_orders: 2, repeat_rate_percent: 50, orders_per_customer: 1, average_lifetime_value_minor: 300000 },
  cohorts: [
    { month: '2026-09', customers: 4, retention: [25, null, null, null, null] },
    { month: '2026-10', customers: 1, retention: [null, null, null, null, null] },
  ],
  carts: { created: 2, converted: 1, abandoned: 1, conversion_percent: 50, average_order_value_minor: 300000, online_payments: 0, online_payment_failure_percent: null },
  products: { slow_movers: [{ id: 'p1', name: 'Oud Box', available: 7, last_sold_at: null }], returns: [{ name: 'Rose Attar', sold: 6, returned: 1, return_percent: 16.7 }] },
  ...overrides,
});

describe('the change against the comparison', () => {
  it('says up or down in words and arrows, and admits when there is nothing to compare', () => {
    const { container, rerender } = render(<Change value={25} />);
    expect(container.textContent).toContain('+25%');
    expect(container.textContent).toContain('up');
    rerender(<Change value={-25} />);
    expect(container.textContent).toContain('down');
    rerender(<Change value={null} />);
    expect(container.textContent).toBe('No earlier figure to compare');
  });
});

describe('the analytics page', () => {
  it('explains Premium to a store without it and asks the server nothing', () => {
    withFeatures({}, '/analytics');
    vi.stubGlobal('fetch', routeFetch({ '/attention': () => json(200, { data: [] }) }));
    render(<Advanced />);
    expect(screen.getByText('Advanced analytics are part of the Premium package')).toBeTruthy();
  });

  it('shows the figures, the comparison, how they are counted, cohorts and products', async () => {
    withFeatures({ 'advanced_analytics.enabled': true }, '/analytics');
    let asked: URL | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/analytics/overview': (url) => {
        asked = url;
        return json(200, { data: overview() });
      },
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Advanced />);

    expect(await screen.findByText('Where the money comes from')).toBeTruthy();
    expect(asked!.searchParams.get('compare_with')).toBe('previous_period');
    expect(screen.getAllByText('Rs. 5,000').length).toBeGreaterThan(0); // net sales
    expect(screen.getByText('was Rs. 4,000')).toBeTruthy();
    expect(screen.getAllByText('How is this counted?').length).toBeGreaterThan(3);
    expect(screen.getByText('50%', { selector: 'p' })).toBeTruthy(); // cart conversion
    const cohort = screen.getByRole('table');
    expect(within(cohort).getByText('25%')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Oud Box' }).getAttribute('href')).toBe('/products/p1');
    expect(screen.getByText('Never sold')).toBeTruthy();
    expect(screen.getByText('Rose Attar (1 of 6)')).toBeTruthy();
  });

  it('leaves the money out for staff without the financial permission', async () => {
    withFeatures({ 'advanced_analytics.enabled': true }, '/analytics');
    const kpis = { ...overview().kpis };
    delete kpis.net_sales_minor;
    delete kpis.average_order_value_minor;
    vi.stubGlobal('fetch', routeFetch({
      '/analytics/overview': () => json(200, { data: overview({ financial: false, sales: null, kpis, customers: { ...overview().customers, average_lifetime_value_minor: null } }) }),
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Advanced />);

    expect(await screen.findByText('Money figures')).toBeTruthy();
    expect(screen.queryByText('Where the money comes from')).toBeNull();
    expect(screen.queryByText('Net sales')).toBeNull();
  });
});

describe('the reports page', () => {
  it('offers the advanced reports as a Business feature without asking the server for them', async () => {
    withFeatures({}, '/reports?report=payments');
    window.history.replaceState(null, '', '/reports?report=payments');
    vi.stubGlobal('fetch', routeFetch({ '/attention': () => json(200, { data: [] }) }));
    render(<Reports />);

    expect(await screen.findByText('This report is part of the advanced reports')).toBeTruthy();
    expect(screen.getByRole('tab', { name: 'Categories & brands' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Export as CSV' })).toBeNull();
    expect(screen.queryByRole('link', { name: /compare periods/ })).toBeNull();
  });

  it('shows sales by category and brand where included, and links to Analytics on Premium', async () => {
    withFeatures({ 'reports.advanced': true, 'advanced_analytics.enabled': true }, '/reports?report=categories');
    window.history.replaceState(null, '', '/reports?report=categories');
    vi.stubGlobal('fetch', routeFetch({
      '/reports/categories': () => json(200, { data: { categories: [{ id: 1, name: 'Attars', revenue_minor: 300000, units: 3, orders: 2 }], brands: [{ id: null, name: null, revenue_minor: 300000, units: 3, orders: 2 }] } }),
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Reports />);

    expect(await screen.findByText('Attars')).toBeTruthy();
    expect(screen.getByText('No brand')).toBeTruthy();
    expect(screen.getByRole('link', { name: /compare periods/ }).getAttribute('href')).toBe('/analytics');
  });
});
