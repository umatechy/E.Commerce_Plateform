import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { CreateShipmentDialog, ShipmentDrawer } from '@/Components/Orders/ShipmentDialogs';
import Shipments from '@/Pages/Shipping/Shipments';
import CouriersCard from '@/Components/Shipping/CouriersCard';
import { clearToasts } from '@/Components/ui/toast';
import type { Order, Shipment } from '@/lib/orders';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B49 (gap G9 shipping side; Module 13): creating shipments, the shipment drawer, cash to collect, couriers. */
beforeEach(() => setPage({ ...owner, currency: 'PKR' }, '/shipments'));

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
  window.history.replaceState(null, '', '/');
});

const courierSettings = (enabled: boolean) => ({ data: {
  can_configure: true, default_weight_grams: 500, tracking_url_template: null, real_couriers: [],
  couriers: [{ provider: 'test_courier', label: 'Test courier', live_allowed: false, credential_fields: [], capabilities: { booking: true, cancel: true, tracking_poll: true, cod: true, return_pickup: true, verified: true, note: null },
    account: enabled ? { id: 'c1', environment: 'sandbox', is_enabled: true, credential_hints: {}, last_success_at: null, last_failure_at: null, last_failure_message: null } : null }],
} });

const shipment = (extra: Partial<Shipment> = {}): Shipment => ({
  id: 's1', order: { id: 'o1', order_number: 'ORD-1001' }, carrier: 'test_courier', carrier_label: 'Test courier', status: 'label_created', tracking_number: 'TCD12345ABCD',
  shipping_cost_minor: 0, currency: 'PKR', estimated_delivery_at: null, shipped_at: null, delivered_at: null, created_at: '2026-10-14T10:00:00Z',
  direction: 'outbound', pieces: 1, weight_grams: 750, cod_amount_minor: 177000, cod_status: 'pending', booking_status: 'booked', booking_error: null,
  actions: { book: false, cancel: true, refresh: true, restock: false, set_tracking: false }, items: [], tracking_events: [], ...extra,
});

describe('Create shipment', () => {
  const order = { id: 'o1', order_number: 'ORD-1001', items: [{ id: 7, product_name: 'Rose Attar', quantity: 2 }] } as unknown as Order;

  it('offers a connected courier and another courier, and sends the parcel details', async () => {
    let body: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/warehouses': () => json(200, { data: [{ id: 'w', internal_id: 3, name: 'Main', is_default: true }] }),
      '/courier-settings': () => json(200, courierSettings(true)),
      'POST /shipments': (_url, init) => {
        body = JSON.parse(String(init.body));

        return json(201, { data: shipment() });
      },
    }));
    const onDone = vi.fn();
    render(<CreateShipmentDialog order={order} onClose={() => undefined} onDone={onDone} />);

    fireEvent.click(await screen.findByRole('radio', { name: /^Test courier/ }));
    expect(screen.getByRole('button', { name: 'Create and book' })).toBeTruthy();
    fireEvent.click(screen.getByRole('radio', { name: /^Another courier/ }));
    fireEvent.change(screen.getByLabelText('Courier'), { target: { value: 'Leopards' } });
    fireEvent.change(screen.getByLabelText(/^Tracking number/), { target: { value: 'LE778899' } });
    fireEvent.change(await screen.findByLabelText('Ship from'), { target: { value: '3' } });
    fireEvent.change(screen.getByLabelText(/^Weight/), { target: { value: '1.25' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create shipment' }));

    await waitFor(() => expect(onDone).toHaveBeenCalled());
    expect(body).toMatchObject({ carrier: 'manual_courier', carrier_name: 'Leopards', tracking_number: 'LE778899', weight_grams: 1250, warehouse_id: 3, items: [{ order_item_id: 7, quantity: 2 }] });
  });

  it('does not offer a courier that is not switched on', async () => {
    vi.stubGlobal('fetch', routeFetch({
      '/warehouses': () => json(200, { data: [] }),
      '/courier-settings': () => json(200, courierSettings(false)),
    }));
    render(<CreateShipmentDialog order={order} onClose={() => undefined} onDone={() => undefined} />);
    expect(await screen.findByRole('radio', { name: /^Another courier/ })).toBeTruthy();
    expect(screen.queryByRole('radio', { name: /^Test courier/ })).toBeNull();
  });
});

describe('Shipment drawer', () => {
  it('explains a refused booking and books again', async () => {
    let booked = false;
    vi.stubGlobal('fetch', routeFetch({
      '/shipments/s1': () => json(200, { data: booked ? shipment() : shipment({ status: 'ready', booking_status: 'failed', booking_error: 'Add the address of the warehouse this ships from (Inventory → Warehouses), then book again.', actions: { book: true, cancel: true, refresh: false, restock: false, set_tracking: false } }) }),
      'POST /shipments/s1/book': () => {
        booked = true;

        return json(200, { data: shipment() });
      },
    }));
    render(<ShipmentDrawer shipmentId="s1" onClose={() => undefined} onChanged={() => undefined} />);

    expect(await screen.findByText('The courier did not take this parcel')).toBeTruthy();
    expect(screen.getByText(/Inventory → Warehouses/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Book again' }));
    await waitFor(() => expect(booked).toBe(true));
    expect(await screen.findByRole('link', { name: 'Print label' })).toBeTruthy();
    expect(screen.getByText('To collect on delivery')).toBeTruthy();
  });

  it('cancels only after confirming, saying the units go back', async () => {
    let cancelled = false;
    vi.stubGlobal('fetch', routeFetch({
      '/shipments/s1': () => json(200, { data: shipment() }),
      'POST /shipments/s1/cancel': () => {
        cancelled = true;

        return json(200, { data: shipment({ status: 'cancelled' }) });
      },
    }));
    render(<ShipmentDrawer shipmentId="s1" onClose={() => undefined} onChanged={() => undefined} />);

    fireEvent.click(await screen.findByRole('button', { name: 'Cancel shipment' }));
    const confirm = await screen.findByRole('dialog', { name: 'Cancel this shipment?' });
    expect(within(confirm).getByText(/courier is asked to cancel the booking first/)).toBeTruthy();
    fireEvent.click(within(confirm).getByRole('button', { name: 'Cancel shipment' }));
    await waitFor(() => expect(cancelled).toBe(true));
  });
});

describe('Cash to collect', () => {
  it('records what a courier paid for the selected parcels', async () => {
    let sent: Record<string, unknown> | null = null;
    const owed = shipment({ id: 's9', status: 'delivered', cod_status: 'to_remit', order: { id: 'o9', order_number: 'ORD-1009' } });
    vi.stubGlobal('fetch', routeFetch({
      '/shipments': (url) => json(200, { data: url.searchParams.get('view') === 'cod' ? [owed] : [], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } }),
      'POST /shipments/cod-remittance': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: { recorded: 1, amount_minor: 177000 } });
      },
    }));
    render(<Shipments />);

    fireEvent.click(await screen.findByRole('tab', { name: 'Cash to collect' }));
    fireEvent.click((await screen.findAllByRole('checkbox', { name: 'Select ORD-1009' }))[0]);
    fireEvent.click(screen.getByRole('button', { name: /^Record .* received$/ }));
    fireEvent.change(await screen.findByLabelText('Payment reference'), { target: { value: 'LCS-SETTLE-77' } });
    fireEvent.click(screen.getByRole('button', { name: 'Record cash received' }));
    await waitFor(() => expect(sent).toEqual({ shipment_ids: ['s9'], reference: 'LCS-SETTLE-77' }));
  });
});

describe('Couriers on Shipping setup', () => {
  it('previews the customer tracking link and saves the parcel weight in grams', async () => {
    let body: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/courier-settings': () => json(200, courierSettings(true)),
      'PUT /courier-settings': (_url, init) => {
        body = JSON.parse(String(init.body));

        return json(200, { data: { saved: true } });
      },
    }));
    render(<CouriersCard />);

    expect(await screen.findByText('Leopards, TCS, PostEx and others')).toBeTruthy();
    expect(screen.getByText('On (test)')).toBeTruthy();
    fireEvent.change(screen.getByLabelText(/Tracking link of your courier/), { target: { value: 'https://track.example.pk/cn/{tracking}' } });
    expect(screen.getByText('Customers will open: https://track.example.pk/cn/LE1234567890')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Usual parcel weight (kg)'), { target: { value: '0.75' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(body).toEqual({ default_weight_grams: 750, tracking_url_template: 'https://track.example.pk/cn/{tracking}' }));
  });
});
