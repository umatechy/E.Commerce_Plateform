import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import StoreCreditCard, { type StoreCredit } from '@/Components/Customers/StoreCreditCard';
import ReturnShow from '@/Pages/Returns/Show';
import CreateOrder from '@/Pages/Orders/Create';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';
import type { ReturnRecord } from '@/lib/returns';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

const CUSTOMER = '01JCUSTOMER000000000000001';
const RETURN = '01JRETURN00000000000000001';

beforeEach(() => {
  setPage({ ...owner, currency: 'PKR' }, '/');
  window.history.replaceState(null, '', '/');
});

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const credit = (overrides: Partial<StoreCredit> = {}): StoreCredit => ({
  currency: 'PKR',
  balance_minor: 150000,
  other_balances: [],
  entries: [{ id: 'e1', type: 'return_refund', amount_minor: 150000, balance_after_minor: 150000, currency: 'PKR', note: 'Return R-1', created_at: '2026-10-02T10:00:00Z', by: 'GM Awan' }],
  can_adjust: true,
  ...overrides,
});

describe('store credit on the customer page', () => {
  it('shows the balance and the ledger, and offers a change only to who may', async () => {
    vi.stubGlobal('fetch', routeFetch({ [`/customers/${CUSTOMER}/store-credit`]: () => json(200, { data: credit({ can_adjust: false }) }) }));
    render(<StoreCreditCard customerId={CUSTOMER} />);

    expect(await screen.findByText('Rs. 1,500')).toBeTruthy();
    expect(screen.getByText('Refund of a return')).toBeTruthy();
    expect(screen.getByText('+ Rs. 1,500')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Change' })).toBeNull();
  });

  it('takes credit back as a negative amount in minor units, with the reason', async () => {
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      [`/customers/${CUSTOMER}/store-credit`]: () => json(200, { data: credit() }),
      [`POST /customers/${CUSTOMER}/store-credit/adjust`]: (_url, init) => {
        sent = JSON.parse(init.body as string);

        return json(201, { data: credit({ balance_minor: 100000 }) });
      },
    }));
    render(<StoreCreditCard customerId={CUSTOMER} />);

    fireEvent.click(await screen.findByRole('button', { name: 'Change' }));
    const dialog = await screen.findByRole('dialog', { name: 'Change store credit' });
    const save = within(dialog).getByRole('button', { name: 'Save' }) as HTMLButtonElement;
    fireEvent.change(within(dialog).getByLabelText('What to do'), { target: { value: 'take' } });
    fireEvent.change(within(dialog).getByLabelText('Amount (PKR)'), { target: { value: '500' } });
    // No reason, no change.
    expect(save.disabled).toBe(true);
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Given twice' } });
    fireEvent.click(save);

    await waitFor(() => expect(sent).toMatchObject({ amount_minor: -50000, note: 'Given twice' }));
    expect(await screen.findByText('Rs. 1,000')).toBeTruthy();
  });
});

describe('a refund as store credit', () => {
  const none = { review: false, approve: false, reject: false, cancel: false, mark_in_transit: false, receive: false, inspect: false, approve_refund: false, replace: false, refund: false };
  const inspected = (overrides: Partial<ReturnRecord> = {}): ReturnRecord => ({
    id: RETURN, return_number: 'R-ORD-1-1', status: 'inspected', resolution: 'refund', reason: 'defective', description: null, requested_by: 'customer',
    decision_note: null, return_method: null, return_shipping_paid_by: null, return_carrier: null, return_tracking_number: null, currency: 'PKR',
    items_refund_minor: 100000, shipping_refund_minor: 0, restocking_fee_minor: 0, refund_total_minor: 0, refunded_minor: 0, refund_method: 'payment', refunded_credit_minor: 0,
    store_credit_possible: true, order: { id: 'o', order_number: 'ORD-1', customer_name: 'Ayesha', shipping_total_minor: 0, store_credit_minor: 0 },
    items: [], photos: [], can: { ...none, approve_refund: true, replace: true },
    created_at: '2026-10-01T10:00:00Z', decided_at: '2026-10-01T11:00:00Z', shipped_back_at: null, received_at: '2026-10-02T10:00:00Z', inspected_at: '2026-10-02T11:00:00Z', refunded_at: null, completed_at: null, cancelled_at: null,
    ...overrides,
  });

  it('is offered for a customer with an account and sent as a choice, not an amount', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      [`/returns/${RETURN}`]: () => json(200, { data: inspected() }),
      [`POST /returns/${RETURN}/approve-refund`]: (_url, init) => {
        sent = JSON.parse(init.body as string);

        return json(200, { data: inspected({ status: 'approved_for_refund', refund_total_minor: 100000, refund_method: 'store_credit', can: { ...none, refund: true } }) });
      },
    }));
    render(<ReturnShow returnId={RETURN} />);

    fireEvent.click(await screen.findByRole('button', { name: 'Approve refund' }));
    const dialog = await screen.findByRole('dialog', { name: 'Approve the refund' });
    fireEvent.click(within(dialog).getByLabelText('Give it as store credit instead of money'));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Approve refund' }));

    await waitFor(() => expect(sent).toEqual({ shipping_refund_minor: 0, restocking_fee_minor: 0, as_store_credit: true }));
    // The next step says what it really does.
    expect(await screen.findByRole('button', { name: 'Give the store credit' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Pay the refund' })).toBeNull();
  });

  it('is not offered for a guest order', async () => {
    vi.stubGlobal('fetch', routeFetch({ [`/returns/${RETURN}`]: () => json(200, { data: inspected({ store_credit_possible: false }) }) }));
    render(<ReturnShow returnId={RETURN} />);

    fireEvent.click(await screen.findByRole('button', { name: 'Approve refund' }));
    const dialog = await screen.findByRole('dialog', { name: 'Approve the refund' });
    expect(within(dialog).queryByLabelText('Give it as store credit instead of money')).toBeNull();
  });
});

describe('store credit on an order staff take', () => {
  const customer = { id: CUSTOMER, name: 'Ayesha Khan', email: 'ayesha@example.com', phone: null, status: 'active', erased: false };

  function openFor(balance: number) {
    setPage({ ...owner, currency: 'PKR', features: { 'payment.cod': true } }, '/orders/new');
    window.history.replaceState(null, '', `/orders/new?customer=${CUSTOMER}`);
    vi.stubGlobal('fetch', routeFetch({
      [`/customers/${CUSTOMER}`]: () => json(200, { data: customer }),
      [`/customers/${CUSTOMER}/store-credit`]: () => json(200, { data: credit({ balance_minor: balance }) }),
      '/products': () => json(200, { data: [] }),
    }));
    render(<CreateOrder />);
  }

  it('offers the customer\'s balance and then asks how the rest is paid', async () => {
    openFor(150000);

    const box = await screen.findByLabelText(/Pay with Ayesha Khan.s store credit \(balance Rs\. 1,500\)/);
    expect(screen.getByLabelText('How the customer pays')).toBeTruthy();
    fireEvent.click(box);
    expect(screen.getByLabelText('How the rest is paid')).toBeTruthy();
  });

  it('offers nothing when the customer has no credit', async () => {
    openFor(0);

    expect(await screen.findByText('ayesha@example.com')).toBeTruthy();
    await waitFor(() => expect(screen.queryByLabelText(/store credit/)).toBeNull());
  });
});

describe('store credit expiry', () => {
  it('says how much expires next and when, only when there is a date', async () => {
    vi.stubGlobal('fetch', routeFetch({ [`/customers/${CUSTOMER}/store-credit`]: () => json(200, { data: credit({ next_expiry: { amount_minor: 70000, expires_at: '2026-11-02T10:00:00Z' } }) }) }));
    render(<StoreCreditCard customerId={CUSTOMER} />);

    expect(await screen.findByText(/Rs\. 700 expires on/)).toBeTruthy();
    cleanup();

    vi.stubGlobal('fetch', routeFetch({ [`/customers/${CUSTOMER}/store-credit`]: () => json(200, { data: credit({ next_expiry: null }) }) }));
    render(<StoreCreditCard customerId={CUSTOMER} />);
    expect(await screen.findByText('Rs. 1,500')).toBeTruthy();
    expect(screen.queryByText(/expires on/)).toBeNull();
  });
});
