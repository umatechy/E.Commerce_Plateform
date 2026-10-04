import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Products from '@/Pages/Catalog/Products';
import Collections from '@/Pages/Catalog/Collections';
import ProductPicker from '@/Components/Catalog/ProductPicker';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';
import { parseTags, type Product } from '@/lib/catalog';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B39 (gap G15): collections, the bulk bar and the ordered product picker. */
beforeEach(() => {
  setPage(owner, '/products');
  window.history.replaceState(null, '', '/');
});

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const product = (id: string, name: string, overrides: Partial<Product> = {}): Product => ({
  id, internal_id: 1, type: 'simple', name, slug: name.toLowerCase(), sku: null, short_description: null, description: null,
  status: 'draft', visibility: 'public', brand_id: null, primary_category_id: null, price_minor: 1000, sale_price_minor: null,
  effective_price_minor: 1000, currency: 'USD', variants: [], ...overrides,
});

const A = '01JPRODUCT0000000000000001';
const B = '01JPRODUCT0000000000000002';

describe('tags', () => {
  it('splits on commas and drops empty entries', () => {
    expect(parseTags(' Eid, Gift ,, Handmade ')).toEqual(['Eid', 'Gift', 'Handmade']);
  });
});

describe('Products bulk changes', () => {
  it('sends one change for the chosen products and reports what was skipped and why', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/products': () => json(200, { data: [product(A, 'Rose Attar'), product(B, 'Oud Oil', { price_minor: null })], meta: { current_page: 1, last_page: 1, total: 2, per_page: 25 } }),
      '/subscription/usage': () => json(200, { data: {} }),
      'POST /products/bulk': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: { affected: 1, skipped: [{ id: B, name: 'Oud Oil', reason: 'A product needs a price before it is published.' }] } });
      },
    }));
    render(<Products />);

    fireEvent.click(await screen.findByRole('button', { name: 'Choose all on this page' }));
    expect(screen.getByText('2 chosen')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Change'), { target: { value: 'publish' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

    expect(await screen.findByText(/1 changed, 1 not changed/)).toBeTruthy();
    expect(screen.getByText(/needs a price/)).toBeTruthy();
    expect(sent).toEqual({ action: 'publish', products: [A, B], params: {} });
  });

  it('asks for the value a change needs before sending it', async () => {
    const fetch = routeFetch({
      '/products': () => json(200, { data: [product(A, 'Rose Attar')], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } }),
      '/subscription/usage': () => json(200, { data: {} }),
    });
    vi.stubGlobal('fetch', fetch);
    render(<Products />);

    fireEvent.click(await screen.findByLabelText('Choose Rose Attar'));
    fireEvent.change(screen.getByLabelText('Change'), { target: { value: 'set_sale_percent' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

    expect((await screen.findByRole('alert')).textContent).toContain('Fill in the value');
    expect(fetch.mock.calls.some(([url]) => String(url).includes('/bulk'))).toBe(false);
  });
});

describe('ProductPicker', () => {
  it('keeps the chosen order, moves and removes', () => {
    const onChange = vi.fn();
    render(<ProductPicker label="Products" max={5} onChange={onChange} value={[{ id: A, name: 'Rose Attar', status: 'active' }, { id: B, name: 'Oud Oil', status: 'draft' }]} />);

    fireEvent.click(screen.getByRole('button', { name: 'Up Oud Oil' }));
    expect(onChange).toHaveBeenLastCalledWith([{ id: B, name: 'Oud Oil', status: 'draft' }, { id: A, name: 'Rose Attar', status: 'active' }]);
    fireEvent.click(screen.getByRole('button', { name: 'Remove Rose Attar' }));
    expect(onChange).toHaveBeenLastCalledWith([{ id: B, name: 'Oud Oil', status: 'draft' }]);
  });
});

describe('Collections', () => {
  it('creates a rule-based collection from the chosen conditions', async () => {
    setPage(owner, '/collections');
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/collections': () => json(200, { data: [] }),
      '/categories': () => json(200, { data: [] }),
      '/brands': () => json(200, { data: [] }),
      '/tags': () => json(200, { data: [{ id: 7, name: 'Handmade', slug: 'handmade', product_count: 3 }] }),
      'POST /collections': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(201, { data: {} });
      },
    }));
    render(<Collections />);

    fireEvent.click((await screen.findAllByRole('button', { name: 'Add collection' }))[0]);
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Handmade under 2000' } });
    fireEvent.change(screen.getByLabelText('Kind'), { target: { value: 'rule' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save collection' }));
    expect((await screen.findByText(/Add at least one condition/)).textContent).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Add a condition'), { target: { value: 'tag' } });
    fireEvent.click(await screen.findByLabelText('Handmade'));
    fireEvent.change(screen.getByLabelText('Add a condition'), { target: { value: 'price_max' } });
    fireEvent.change(screen.getByLabelText('Amount (USD)'), { target: { value: '20.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save collection' }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toMatchObject({ name: 'Handmade under 2000', type: 'rule', sort: 'newest', rules: [{ field: 'tag', value: [7] }, { field: 'price_max', value: 2000 }] });
  });
});
