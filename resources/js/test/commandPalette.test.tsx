import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { json, owner, routeFetch, routerMock, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-2: "Go to…" (Ctrl+K), menu icons and folding groups. */
afterEach(() => {
  cleanup();
  routerMock.visit.mockClear();
  window.localStorage.clear();
});

describe('Go to…', () => {
  it('opens with Ctrl+K, finds a page or a quick action and goes there', async () => {
    setPage(owner, '/');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);

    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    const dialog = screen.getByRole('dialog', { name: 'Go to a page' });
    const search = within(dialog).getByLabelText(/Search pages/);
    fireEvent.change(search, { target: { value: 'add product' } });
    expect(within(dialog).getByRole('option', { name: /Add a product/ })).toBeTruthy();
    fireEvent.keyDown(search, { key: 'Enter' });
    expect(routerMock.visit).toHaveBeenCalledWith('/products/new');
    expect(screen.queryByRole('dialog', { name: 'Go to a page' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: /Go to…/ }));
    fireEvent.change(screen.getByLabelText(/Search pages/), { target: { value: 'nothing like this' } });
    expect(await screen.findByText(/Nothing matches/)).toBeTruthy();
  });

  it('offers only pages the menu shows this user', () => {
    setPage({ ...owner, is_owner: false, permissions: ['orders.view'] }, '/');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    const search = screen.getByLabelText(/Search pages/);
    fireEvent.change(search, { target: { value: 'products' } });
    expect(screen.queryByRole('option', { name: /^Products/ })).toBeNull();
    fireEvent.change(search, { target: { value: 'orders' } });
    expect(screen.getByRole('option', { name: /Orders/ })).toBeTruthy();
  });
});

describe('menu groups', () => {
  it('fold away and remember it, but the group of the open page stays open', () => {
    setPage(owner, '/');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    const nav = screen.getByRole('navigation', { name: 'Admin' });
    fireEvent.click(within(nav).getByRole('button', { name: 'Catalog' }));
    expect(within(nav).queryByRole('link', { name: 'Products' })).toBeNull();
    expect(window.localStorage.getItem('admin.sidebar.folded')).toBe('["Catalog"]');
    cleanup();

    setPage(owner, '/products');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    expect(within(screen.getByRole('navigation', { name: 'Admin' })).getByRole('link', { name: 'Products' })).toBeTruthy();
  });
});

describe('Go to… finds records', () => {
  it('finds an order, a product and a customer from the store lists and opens the one chosen', async () => {
    setPage(owner, '/');
    const searched: string[] = [];
    vi.stubGlobal('fetch', routeFetch({
      '/orders': (url) => {
        searched.push(`orders:${url.searchParams.get('search')}`);
        return json(200, { data: [{ id: 'o1', order_number: 'ORD-000026', status: 'delivered', currency: 'PKR', grand_total_minor: 491400, is_guest_order: true, guest_name: 'Ayesha', customer: null }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } });
      },
      '/products': () => json(200, { data: [{ id: 'p1', name: 'Pure Wool Shawl', sku: 'SH-1', status: 'active', effective_price_minor: 280000, currency: 'PKR' }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }),
      '/customers': () => json(200, { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }),
    }));
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    fireEvent.change(screen.getByLabelText(/Search pages/), { target: { value: 'ORD-26' } });

    expect(await screen.findByRole('option', { name: /ORD-000026/ })).toBeTruthy();
    expect(screen.getByRole('option', { name: /Pure Wool Shawl/ })).toBeTruthy();
    expect(screen.getByText('Ayesha (guest) · Delivered · Rs. 4,914')).toBeTruthy();
    expect(searched).toEqual(['orders:ORD-26']);

    fireEvent.click(screen.getByRole('option', { name: /ORD-000026/ }));
    expect(routerMock.visit).toHaveBeenCalledWith('/orders/o1');
    vi.unstubAllGlobals();
  });
});
