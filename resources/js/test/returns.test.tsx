import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import Show from '@/Pages/Returns/Show';
import ReturnsCard from '@/Components/Orders/ReturnsCard';
import OrderReturns from '@/Components/Storefront/OrderReturns';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, routerMock, setPage } from '@/test/inertiaMock';
import { returnSteps, type ReturnRecord, type Returnable } from '@/lib/returns';
import type { Order } from '@/lib/orders';
import type { Shell } from '@/Storefront/types';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

const ID = '01JRETURN00000000000000001';
const ORDER = '01JORDER000000000000000001';

beforeEach(() => {
  setPage({ ...owner, currency: 'PKR' }, `/returns/${ID}`);
  window.history.replaceState(null, '', '/');
});

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const none = { review: false, approve: false, reject: false, cancel: false, mark_in_transit: false, receive: false, inspect: false, approve_refund: false, replace: false, refund: false };

const record = (overrides: Partial<ReturnRecord> = {}): ReturnRecord => ({
  id: ID,
  return_number: 'R-ORD-1001-1',
  status: 'requested',
  resolution: 'refund',
  reason: 'defective',
  description: 'Leaks',
  requested_by: 'customer',
  decision_note: null,
  return_method: null,
  return_shipping_paid_by: null,
  return_carrier: null,
  return_tracking_number: null,
  currency: 'PKR',
  items_refund_minor: 0,
  shipping_refund_minor: 0,
  restocking_fee_minor: 0,
  refund_total_minor: 0,
  refunded_minor: 0,
  order: { id: ORDER, order_number: 'ORD-1001', customer_name: 'Ayesha Khan', payment_status: 'paid', shipping_total_minor: 25000 },
  replacement_order: null,
  items: [{ order_item_id: 7, name: 'Rose Attar', sku: 'RA-1', variant: null, unit_price_minor: 100000, quantity: 2, resalable_quantity: 0, damaged_quantity: 0, rejected_quantity: 0, refund_minor: 0, inspection_note: null }],
  warehouse: null,
  can: { ...none, review: true, approve: true, reject: true, cancel: true },
  created_at: '2026-10-01T10:00:00Z',
  decided_at: null,
  shipped_back_at: null,
  received_at: null,
  inspected_at: null,
  refunded_at: null,
  completed_at: null,
  cancelled_at: null,
  ...overrides,
});

describe('return detail (staff)', () => {
  it('offers the steps the state allows, by permission', async () => {
    setPage({ ...owner, is_owner: false, permissions: ['returns.view', 'returns.manage'] }, `/returns/${ID}`);
    vi.stubGlobal('fetch', routeFetch({ [`/returns/${ID}`]: () => json(200, { data: record() }) }));
    render(<Show returnId={ID} />);

    await screen.findByRole('heading', { name: 'Return R-ORD-1001-1' });
    // Managing is not approving.
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Reject' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Cancel return' })).toBeTruthy();
    expect(screen.getByText('Leaks')).toBeTruthy();
  });

  it('inspection must account for every unit before it is sent', async () => {
    let sent: unknown = null;
    vi.stubGlobal(
      'fetch',
      routeFetch({
        [`/returns/${ID}`]: () => json(200, { data: record({ status: 'received', received_at: '2026-10-02T10:00:00Z', can: { ...none, inspect: true } }) }),
        [`POST /returns/${ID}/inspect`]: (_url, init) => {
          sent = JSON.parse(init.body as string);

          return json(200, { data: record({ status: 'inspected', inspected_at: '2026-10-02T11:00:00Z', items_refund_minor: 100000, can: { ...none, approve_refund: true, replace: true } }) });
        },
      }),
    );
    render(<Show returnId={ID} />);

    fireEvent.click(await screen.findByRole('button', { name: 'Inspect items' }));
    const dialog = await screen.findByRole('dialog', { name: 'Inspect the returned items' });
    const save = within(dialog).getByRole('button', { name: 'Save inspection' }) as HTMLButtonElement;
    // Starts with everything good; one damaged without lowering the good ones does not add up.
    expect(save.disabled).toBe(false);
    fireEvent.change(within(dialog).getByLabelText('Damaged'), { target: { value: '1' } });
    expect(save.disabled).toBe(true);
    expect(within(dialog).getByText('The three numbers must add up to 2.')).toBeTruthy();
    fireEvent.change(within(dialog).getByLabelText('Good, back into stock'), { target: { value: '1' } });
    expect(save.disabled).toBe(false);
    fireEvent.click(save);

    await waitFor(() => expect(sent).toEqual({ items: [{ order_item_id: 7, resalable: 1, damaged: 1, rejected: 0, note: null }], note: null }));
    expect(await screen.findByRole('button', { name: 'Approve refund' })).toBeTruthy();
  });

  it('sends the two staff choices in minor units and never an amount of its own', async () => {
    let sent: unknown = null;
    const inspected = record({ status: 'inspected', inspected_at: '2026-10-02T11:00:00Z', items_refund_minor: 200000, can: { ...none, approve_refund: true, replace: true } });
    vi.stubGlobal(
      'fetch',
      routeFetch({
        [`/returns/${ID}`]: () => json(200, { data: inspected }),
        [`POST /returns/${ID}/approve-refund`]: (_url, init) => {
          sent = JSON.parse(init.body as string);

          return json(200, { data: { ...inspected, status: 'approved_for_refund', refund_total_minor: 215000, can: { ...none, refund: true } } });
        },
      }),
    );
    render(<Show returnId={ID} />);

    fireEvent.click(await screen.findByRole('button', { name: 'Approve refund' }));
    const dialog = await screen.findByRole('dialog', { name: 'Approve the refund' });
    expect(within(dialog).getByText('Refund: Rs. 2,000')).toBeTruthy();
    fireEvent.change(within(dialog).getByLabelText(/Shipping to refund/), { target: { value: '250' } });
    fireEvent.change(within(dialog).getByLabelText(/Restocking fee/), { target: { value: '100' } });
    expect(within(dialog).getByText('Refund: Rs. 2,150')).toBeTruthy();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Approve refund' }));

    await waitFor(() => expect(sent).toEqual({ shipping_refund_minor: 25000, restocking_fee_minor: 10000 }));
    expect(await screen.findByRole('button', { name: 'Pay the refund' })).toBeTruthy();
  });

  it('tells an approver without the refund permission that someone else pays', async () => {
    setPage({ ...owner, is_owner: false, permissions: ['returns.view', 'returns.manage', 'returns.approve'], currency: 'PKR' }, `/returns/${ID}`);
    vi.stubGlobal('fetch', routeFetch({ [`/returns/${ID}`]: () => json(200, { data: record({ status: 'approved_for_refund', inspected_at: '2026-10-02T11:00:00Z', refund_total_minor: 190000, can: { ...none, refund: true } }) }) }));
    render(<Show returnId={ID} />);

    expect(await screen.findByText(/Someone who may issue refunds has to pay it/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Pay the refund' })).toBeNull();
  });

  it('lists only the steps that happened', () => {
    expect(returnSteps(record({ decided_at: '2026-10-01T12:00:00Z', received_at: '2026-10-03T12:00:00Z' })).map((step) => step.label)).toEqual(['Requested', 'Approved', 'Received']);
    expect(returnSteps(record({ status: 'rejected', decided_at: '2026-10-01T12:00:00Z' })).map((step) => step.label)).toEqual(['Requested', 'Not accepted']);
  });
});

describe('returns on the order page (staff)', () => {
  const order = { id: ORDER, order_number: 'ORD-1001' } as Order;
  const returnable: Returnable = {
    blocked: null, window_days: 7, customer_requests_enabled: false,
    lines: [{ order_item_id: 7, name: 'Rose Attar', sku: null, variant: null, ordered: 3, delivered: 3, in_returns: 1, returnable: 2, delivered_at: '2026-10-01T10:00:00Z', window_open: true, unit_price_minor: 100000 }],
  };

  it('records a return within what can be returned and opens it', async () => {
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal(
      'fetch',
      routeFetch({
        '/returns': () => json(200, { data: { data: [], current_page: 1, last_page: 1, total: 0, per_page: 50 } }),
        [`/orders/${ORDER}/returnable`]: () => json(200, { data: returnable }),
        [`POST /orders/${ORDER}/returns`]: (_url, init) => {
          sent = JSON.parse(init.body as string);

          return json(201, { data: record() });
        },
      }),
    );
    render(<ReturnsCard order={order} />);

    fireEvent.click(await screen.findByRole('button', { name: 'Start a return' }));
    const dialog = await screen.findByRole('dialog', { name: 'Start a return for order ORD-1001' });
    expect(within(dialog).getByText(/Up to 2 \(delivered 3, 1 already in a return\)/)).toBeTruthy();

    fireEvent.change(within(dialog).getByLabelText('Quantity of Rose Attar'), { target: { value: '3' } });
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'defective' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Record the return' }));
    expect(await within(dialog).findByText('A quantity is more than can be returned.')).toBeTruthy();
    expect(sent).toBeNull();

    fireEvent.change(within(dialog).getByLabelText('Quantity of Rose Attar'), { target: { value: '2' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Record the return' }));
    await waitFor(() => expect(sent).toMatchObject({ items: [{ order_item_id: 7, quantity: 2 }], resolution: 'refund', reason: 'defective', description: null }));
    expect(String((sent as unknown as { idempotency_key: string }).idempotency_key).length).toBeGreaterThan(7);
    await waitFor(() => expect(routerMock.visit).toHaveBeenCalledWith(`/returns/${ID}`));
  });

  it('offers no return while nothing is delivered', async () => {
    vi.stubGlobal(
      'fetch',
      routeFetch({
        '/returns': () => json(200, { data: { data: [], current_page: 1, last_page: 1, total: 0, per_page: 50 } }),
        [`/orders/${ORDER}/returnable`]: () => json(200, { data: { ...returnable, lines: [{ ...returnable.lines[0], delivered: 0, in_returns: 0, returnable: 0 }] } }),
      }),
    );
    render(<ReturnsCard order={order} />);

    expect(await screen.findByText(/Items can be returned once they are delivered/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Start a return' })).toBeNull();
  });
});

describe('returns in the customer account', () => {
  const shell = { base_path: '/shop/acme', store: { slug: 'acme', currency: 'PKR' } } as unknown as Shell;
  const line = { order_item_id: 7, name: 'Rose Attar', sku: null, variant: null, ordered: 2, delivered: 2, in_returns: 0, returnable: 2, delivered_at: '2026-10-01T10:00:00Z', window_open: true, unit_price_minor: 100000 };

  it('points to the store when it takes returns by contact only', async () => {
    vi.stubGlobal('fetch', routeFetch({ [`/customer/orders/${ORDER}/returnable`]: () => json(200, { data: { enabled: false, blocked: null, window_days: 7, lines: [line], returns: [] } }) }));
    render(<OrderReturns shell={shell} orderId={ORDER} />);

    expect((await screen.findByRole('link', { name: 'contact the store' })).getAttribute('href')).toBe(`/shop/acme/account/support/new?order=${ORDER}`);
    expect(screen.queryByRole('button', { name: 'Send request' })).toBeNull();
  });

  it('sends a request and shows the approved return with the way to report the parcel', async () => {
    let sent: Record<string, unknown> | null = null;
    let returns: ReturnRecord[] = [];
    vi.stubGlobal(
      'fetch',
      routeFetch({
        [`/customer/orders/${ORDER}/returnable`]: () => json(200, { data: { enabled: true, blocked: null, window_days: 7, lines: [{ ...line, returnable: returns.length ? 1 : 2 }], returns } }),
        [`POST /customer/orders/${ORDER}/returns`]: (_url, init) => {
          sent = JSON.parse(init.body as string);
          returns = [record({ status: 'approved', decision_note: 'Send it to our Lahore shop', can: { ...none, cancel: true, mark_in_transit: true } })];

          return json(201, { data: returns[0] });
        },
      }),
    );
    render(<OrderReturns shell={shell} orderId={ORDER} />);

    await screen.findByRole('heading', { name: 'Request a return' });
    fireEvent.click(screen.getByRole('button', { name: 'Send request' }));
    expect(await screen.findByText('Enter how many of at least one item you want to return.')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('How many of Rose Attar to return'), { target: { value: '1' } });
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'size_or_fit' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send request' }));

    await waitFor(() => expect(sent).toMatchObject({ items: [{ order_item_id: 7, quantity: 1 }], resolution: 'refund', reason: 'size_or_fit' }));
    expect(await screen.findByText('From the store: Send it to our Lahore shop')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'I have sent the items' })).toBeTruthy();
    expect(screen.getByText('Your request was sent. The store will answer by email.')).toBeTruthy();
  });
});
