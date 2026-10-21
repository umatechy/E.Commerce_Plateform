import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import ProductEdit from '@/Pages/Catalog/ProductEdit';
import SettingsHub from '@/Pages/Settings/Hub';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, routerMock, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-26 — fewer clicks: a product with its stock in one step, every setting on one page, the section's pages as tabs. */
beforeEach(() => {
  URL.createObjectURL = vi.fn(() => 'blob:preview');
  URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
  routerMock.visit.mockReset();
});

describe('a new product', () => {
  it('is created for sale with its price, and its stock is saved in the default warehouse', async () => {
    setPage({ ...owner, currency: 'PKR' }, '/products/new');
    let product: Record<string, unknown> = {};
    const stock: unknown[] = [];
    vi.stubGlobal('fetch', routeFetch({
      '/brands': () => json(200, { data: [] }),
      '/categories': () => json(200, { data: [] }),
      'POST /products': (_url, init) => {
        product = JSON.parse(String(init.body));
        return json(201, { data: { id: '01JPRODUCT0000000000000002', internal_id: 42, name: 'Kurta' } });
      },
      '/warehouses': () => json(200, { data: [{ id: 'w2', internal_id: 2, is_default: false }, { id: 'w1', internal_id: 1, is_default: true }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 } }),
      'POST /inventory': (_url, init) => {
        stock.push(JSON.parse(String(init.body)));
        return json(201, { data: { id: 'inv9' } });
      },
      'POST /inventory/inv9/opening-stock': (_url, init) => {
        stock.push(JSON.parse(String(init.body)).quantity);
        return json(200, { data: {} });
      },
    }));
    render(<ProductEdit productId={null} />);

    // One page: no tabs to find the price or the stock.
    expect(screen.queryByRole('tablist')).toBeNull();
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Kurta' } });
    fireEvent.change(screen.getByLabelText(/^Price \(PKR\)/), { target: { value: '1499' } });
    fireEvent.change(screen.getByLabelText(/How many do you have/), { target: { value: '7' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

    await waitFor(() => expect(routerMock.visit).toHaveBeenCalledWith('/products/01JPRODUCT0000000000000002?created=1'));
    expect(product).toMatchObject({ name: 'Kurta', status: 'active', type: 'simple', price_minor: 149900 });
    expect(stock).toEqual([{ warehouse_id: 1, product_id: 42, product_variant_id: null }, 7]);
  });

  it('refuses a stock that is not a whole number before anything is sent', async () => {
    setPage({ ...owner, currency: 'PKR' }, '/products/new');
    const created = vi.fn();
    vi.stubGlobal('fetch', routeFetch({ '/brands': () => json(200, { data: [] }), '/categories': () => json(200, { data: [] }), 'POST /products': () => { created(); return json(201, { data: {} }); } }));
    render(<ProductEdit productId={null} />);

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Kurta' } });
    fireEvent.change(screen.getByLabelText(/How many do you have/), { target: { value: 'ten' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

    expect(await screen.findByText('Enter a whole number, such as 10.')).toBeTruthy();
    expect(created).not.toHaveBeenCalled();
  });

  it('with sizes or colours asks for no stock now: each size gets its own', () => {
    setPage({ ...owner, currency: 'PKR' }, '/products/new');
    vi.stubGlobal('fetch', routeFetch({ '/brands': () => json(200, { data: [] }), '/categories': () => json(200, { data: [] }) }));
    render(<ProductEdit productId={null} />);

    fireEvent.click(screen.getByRole('switch', { name: 'This product comes in sizes or colours' }));
    expect(screen.queryByLabelText(/How many do you have/)).toBeNull();
    expect(screen.getByText(/Each size or colour has its own stock/)).toBeTruthy();
  });
});

describe('the settings page', () => {
  it('lists every setting the user may open, grouped, each one click away', () => {
    setPage(owner, '/settings');
    vi.stubGlobal('fetch', routeFetch({ '/attention': () => json(200, { data: [] }) }));
    render(<SettingsHub />);

    const main = screen.getByRole('main');
    expect(within(main).getByRole('heading', { name: 'Selling' })).toBeTruthy();
    expect(within(main).getByRole('link', { name: /Payment methods/ }).getAttribute('href')).toBe('/settings/payments');
    expect(within(main).getByRole('link', { name: /Delivery/ }).getAttribute('href')).toBe('/shipping');
    expect(within(main).getByRole('link', { name: /Security/ }).getAttribute('href')).toBe('/security');
    expect(within(main).getByRole('link', { name: /Close store/ })).toBeTruthy(); // owner only
  });

  it('leaves out what a staff role may not open', () => {
    setPage({ ...owner, is_owner: false, permissions: ['settings.view'] }, '/settings');
    vi.stubGlobal('fetch', routeFetch({ '/attention': () => json(200, { data: [] }) }));
    render(<SettingsHub />);

    const main = screen.getByRole('main');
    expect(within(main).getByRole('link', { name: /Store details/ })).toBeTruthy();
    expect(within(main).queryByRole('link', { name: /Package & billing/ })).toBeNull();
    expect(within(main).queryByRole('link', { name: /Close store/ })).toBeNull();
  });
});

describe('a section page', () => {
  it('shows the other pages of its section as tabs, the current one marked', () => {
    setPage(owner, '/shipments');
    vi.stubGlobal('fetch', routeFetch({ '/attention': () => json(200, { data: [] }) }));
    render(<ProductEdit productId={null} />);
    // Any AdminPage on /shipments: the Orders section's pages as tabs.
    const tabs = screen.getByRole('navigation', { name: 'In this section' });
    expect(within(tabs).getAllByRole('link').map((link) => link.textContent)).toEqual(['Orders', 'Shipments', 'Returns', 'Payments']);
    expect(within(tabs).getByRole('link', { name: 'Shipments' }).getAttribute('aria-current')).toBe('page');
  });
});
