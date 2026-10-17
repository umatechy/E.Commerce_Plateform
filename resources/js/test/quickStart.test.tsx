import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import QuickStart from '@/Components/Dashboard/QuickStart';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-16: a new store's first steps, done in one guide. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const checks = [
  { key: 'business_info', done: false, required: true, message: 'A contact email for your store.' },
  { key: 'products', done: false, required: true, message: 'At least one active, public product with a price.' },
];

describe('quick start', () => {
  it('saves the contact, adds the first product with its stock, then opens the store', async () => {
    setPage(owner, '/');
    const calls: string[] = [];
    vi.stubGlobal('fetch', routeFetch({
      'PUT /store/settings/store.contact_email': (_u, init) => { calls.push(`email ${JSON.parse(String(init.body)).value}`); return json(200, { data: {} }); },
      'PUT /store/settings/store.contact_phone': () => { calls.push('phone'); return json(200, { data: {} }); },
      'POST /products': (_u, init) => { const b = JSON.parse(String(init.body)); calls.push(`product ${b.name} ${b.price_minor} ${b.status}`); return json(201, { data: { id: 'p1', internal_id: 7 } }); },
      '/warehouses': () => json(200, { data: [{ id: 'w0', internal_id: 3, is_default: false }, { id: 'w1', internal_id: 4, is_default: true }] }),
      'POST /inventory': (_u, init) => { const b = JSON.parse(String(init.body)); calls.push(`stock record ${b.product_id} in ${b.warehouse_id}`); return json(201, { data: { id: 's1' } }); },
      'POST /inventory/s1/opening-stock': (_u, init) => { calls.push(`opening ${JSON.parse(String(init.body)).quantity}`); return json(201, { data: {} }); },
      'POST /storefront/launch': () => { calls.push('launch'); return json(200, { data: {} }); },
    }));
    const onClose = vi.fn();
    const { rerender } = render(<QuickStart checks={checks} launched={false} currency="PKR" onChanged={vi.fn()} onClose={onClose} />);

    expect(screen.getByText('Step 1 of 3 · about two minutes')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Contact email'), { target: { value: 'shop@example.com' } });
    fireEvent.change(screen.getByLabelText(/Phone or WhatsApp/), { target: { value: '0300 1234567' } });
    fireEvent.click(screen.getByRole('button', { name: /Save and continue/ }));

    expect(await screen.findByText('Your first product')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Product name'), { target: { value: 'Lawn suit' } });
    fireEvent.change(screen.getByLabelText('Price (PKR)'), { target: { value: '2500' } });
    fireEvent.change(screen.getByLabelText(/How many you have/), { target: { value: '10' } });
    fireEvent.click(screen.getByRole('button', { name: /Add product and continue/ }));

    expect(await screen.findByText('Open your store')).toBeTruthy();
    // The dashboard reloads the checks; now nothing required is missing.
    rerender(<QuickStart checks={checks.map((c) => ({ ...c, done: true }))} launched={false} currency="PKR" onChanged={vi.fn()} onClose={onClose} />);
    fireEvent.click(screen.getByRole('button', { name: /Open my store/ }));
    await waitFor(() => expect(onClose).toHaveBeenCalled());

    expect(calls).toEqual(['email shop@example.com', 'phone', 'product Lawn suit 250000 active', 'stock record 7 in 4', 'opening 10', 'launch']);
  });

  it('lists what is still missing instead of a launch button', () => {
    setPage(owner, '/');
    render(<QuickStart checks={[{ ...checks[0], done: true }, checks[1]]} launched={false} currency="PKR" onChanged={vi.fn()} onClose={vi.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Skip this step' }));

    expect(screen.getByText('At least one active, public product with a price.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Open my store/ })).toBeNull();
  });
});
