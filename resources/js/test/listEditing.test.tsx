import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Products from '@/Pages/Catalog/Products';
import SavedViews, { viewFilters, viewQuery } from '@/Components/ui/SavedViews';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-21: change price and stock in the product list; saved views. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const product = { id: '01JA', internal_id: 7, type: 'simple', name: 'Lawn Suit', slug: 'lawn-suit', sku: null, status: 'active', visibility: 'public', brand: null, price_minor: 250000, sale_price_minor: null, effective_price_minor: 250000, currency: 'PKR', variants: [], stock_available: 8 };
const list = () => json(200, { data: [product], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } });
const usage = () => json(200, { data: {} });

describe('product list editing', () => {
  it('saves a new price typed into the list', async () => {
    setPage(owner, '/products');
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({ '/products': list, '/subscription/usage': usage, 'PUT /products/01JA': (_u, init) => { sent = JSON.parse(String(init.body)); return json(200, { data: product }); } }));
    render(<Products />);

    fireEvent.click(await screen.findByRole('button', { name: /Change Price of Lawn Suit/ }));
    const box = screen.getByLabelText('Price of Lawn Suit');
    fireEvent.change(box, { target: { value: '3000' } });
    fireEvent.keyDown(box, { key: 'Enter' });

    await waitFor(() => expect(sent).toEqual({ price_minor: 300000 }));
  });

  it('sets the stock by adjusting the one stock record by the difference', async () => {
    setPage(owner, '/products');
    let adjust: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/products': list,
      '/subscription/usage': usage,
      '/inventory': (url) => (url.searchParams.get('product') === '7' ? json(200, { data: [{ id: 's1', on_hand: 10, reserved: 2 }] }) : json(200, { data: [] })),
      'POST /inventory/s1/adjust': (_u, init) => { adjust = JSON.parse(String(init.body)); return json(200, { data: {} }); },
    }));
    render(<Products />);

    fireEvent.click(await screen.findByRole('button', { name: /Change Stock of Lawn Suit/ }));
    const box = screen.getByLabelText('Stock of Lawn Suit');
    fireEvent.change(box, { target: { value: '15' } });
    fireEvent.keyDown(box, { key: 'Enter' });

    await waitFor(() => expect(adjust).toMatchObject({ quantity: 7, reason: 'Stock set from the product list' }));
  });

  it('keeps the old value when Escape is pressed', async () => {
    setPage(owner, '/products');
    const fetchMock = routeFetch({ '/products': list, '/subscription/usage': usage });
    vi.stubGlobal('fetch', fetchMock);
    render(<Products />);

    fireEvent.click(await screen.findByRole('button', { name: /Change Price of Lawn Suit/ }));
    fireEvent.change(screen.getByLabelText('Price of Lawn Suit'), { target: { value: '1' } });
    fireEvent.keyDown(screen.getByLabelText('Price of Lawn Suit'), { key: 'Escape' });

    expect(screen.queryByLabelText('Price of Lawn Suit')).toBeNull();
    expect(fetchMock.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(false);
  });
});

describe('saved views', () => {
  it('saves the current filters under a name, with the account, and applies them again', async () => {
    setPage(owner, '/orders');
    let saved: unknown = null;
    vi.stubGlobal('fetch', routeFetch({ 'PUT /auth/preferences': (_u, init) => { saved = JSON.parse(String(init.body)); return json(200, { data: {} }); } }));
    const onApply = vi.fn();
    const { rerender } = render(<SavedViews list="orders" query="status=pending_confirmation" onApply={onApply} />);

    fireEvent.click(screen.getByRole('button', { name: /Save this view/ }));
    fireEvent.change(screen.getByLabelText('Name of the view'), { target: { value: 'COD to confirm' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(saved).toEqual({ saved_views: { orders: [{ name: 'COD to confirm', query: 'status=pending_confirmation' }] } }));

    rerender(<SavedViews list="orders" query="" onApply={onApply} />);
    fireEvent.click(screen.getByRole('button', { name: 'COD to confirm' }));
    expect(onApply).toHaveBeenCalledWith('status=pending_confirmation');
  });

  it('turns filters into a query and back', () => {
    const defaults = { search: '', status: '', page: '1' };
    expect(viewQuery({ search: 'ali', status: 'confirmed', page: '3' }, defaults)).toBe('search=ali&status=confirmed');
    expect(viewFilters('status=confirmed', defaults)).toEqual({ search: '', status: 'confirmed', page: '1' });
  });
});
