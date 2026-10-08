import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import PlanChangeDialog from '@/Components/Billing/PlanChangeDialog';
import PaymentNoticeDialog from '@/Components/Billing/PaymentNoticeDialog';
import { CreditNotes, PaymentNotices } from '@/Components/SuperAdmin/BillingDesk';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B47 (Module 29 §42–49, §73–74, §92): plan changes, payment notices, the billing desk. */
beforeEach(() => setPage({ ...owner, currency: 'PKR' }, '/billing'));

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const plans = { data: [
  { code: 'business', name: 'Business', price_minor: 300000, currency: 'PKR', current: true, scheduled: false },
  { code: 'premium', name: 'Premium', price_minor: 600000, currency: 'PKR', current: false, scheduled: false },
  { code: 'basic', name: 'Basic', price_minor: 150000, currency: 'PKR', current: false, scheduled: false },
] };
const base = { currency: 'PKR', interval: 'monthly', features_lost: [], features_gained: [], limits_exceeded: {}, proration: null, blocked: null };

describe('Change plan', () => {
  it('shows what an upgrade costs now before changing', async () => {
    const changed = vi.fn(() => json(200, { data: { timing: 'now' } }));
    vi.stubGlobal('fetch', routeFetch({
      '/billing/plans': () => json(200, plans),
      '/billing/plan-change/preview': () => json(200, { data: { ...base, direction: 'upgrade', timing: 'now', from: { name: 'Business', price_minor: 300000 }, to: { name: 'Premium', price_minor: 600000 },
        features_gained: ['reviews.product'], proration: { charge_minor: 309700, credit_minor: 154800, net_minor: 154900, tax_minor: 15500, total_minor: 170400, until: '2026-04-15T09:00:00Z' } } }),
      'POST /billing/plan-change': changed,
    }));
    const onDone = vi.fn();
    render(<PlanChangeDialog onClose={() => undefined} onDone={onDone} />);

    fireEvent.change(await screen.findByLabelText('New plan'), { target: { value: 'premium' } });
    expect(await screen.findByText('You move to Premium now.')).toBeTruthy();
    expect(screen.getByText('Unused Business')).toBeTruthy();
    expect(screen.getByText('Product reviews and ratings')).toBeTruthy();
    // The current plan is not offered.
    expect(screen.queryByRole('option', { name: /^Business/ })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: /^Change and pay/ }));
    await waitFor(() => expect(onDone).toHaveBeenCalled());
    expect(changed).toHaveBeenCalledTimes(1);
  });

  it('explains a downgrade: when, what is lost, limits exceeded — and blocks what is not possible', async () => {
    let blocked = false;
    vi.stubGlobal('fetch', routeFetch({
      '/billing/plans': () => json(200, plans),
      '/billing/plan-change/preview': () => json(200, { data: blocked
        ? { ...base, direction: 'upgrade', timing: null, blocked: 'pay_open_invoice', from: { name: 'Business', price_minor: 300000 }, to: { name: 'Premium', price_minor: 600000 } }
        : { ...base, direction: 'downgrade', timing: 'period_end', effective_at: '2026-04-15T09:00:00Z', from: { name: 'Business', price_minor: 300000 }, to: { name: 'Basic', price_minor: 150000 },
          features_lost: ['reviews.product'], limits_exceeded: { max_products: { limit: 500, current: 620 } } } }),
    }));
    render(<PlanChangeDialog onClose={() => undefined} onDone={() => undefined} />);

    fireEvent.change(await screen.findByLabelText('New plan'), { target: { value: 'basic' } });
    expect(await screen.findByText(/You keep Business until/)).toBeTruthy();
    expect(screen.getByText('You lose')).toBeTruthy();
    expect(screen.getByText(/620 of 500/)).toBeTruthy();
    expect(screen.getByText(/Nothing is deleted/)).toBeTruthy();

    blocked = true;
    fireEvent.change(screen.getByLabelText('New plan'), { target: { value: 'premium' } });
    expect(await screen.findByText('Pay your open invoice first, then change the plan.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Change plan' }) as HTMLButtonElement).disabled).toBe(true);
  });
});

describe('I have paid', () => {
  it('sends the amount in minor units with the transfer reference', async () => {
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      'POST /billing/invoices/inv1/payment-notices': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(201, { data: {} });
      },
    }));
    const onDone = vi.fn();
    render(<PaymentNoticeDialog invoice={{ id: 'inv1', number: 'INV-000007', currency: 'PKR', amount_due_minor: 300000 }} onClose={() => undefined} onDone={onDone} />);

    expect((screen.getByLabelText('Amount (PKR)') as HTMLInputElement).value).toBe('3000.00');
    fireEvent.change(screen.getByLabelText('Transaction or reference number'), { target: { value: 'HBL-77812' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send' }));
    await waitFor(() => expect(onDone).toHaveBeenCalled());
    expect(sent).toMatchObject({ amount_minor: 300000, method: 'bank_transfer', reference: 'HBL-77812' });
  });
});

describe('Billing desk', () => {
  beforeEach(() => setPage({ ...owner, is_owner: false, currency: 'PKR' }, '/super-admin/billing'));

  it('confirms a reported payment', async () => {
    const approved = vi.fn(() => json(200, { data: { status: 'approved' } }));
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/billing/payment-notices': () => json(200, { data: [
        { id: 'n1', store: { id: 5, name: 'Lahore Sweets' }, invoice: { id: 'i1', number: 'INV-000007', due_minor: 300000 }, amount_minor: 300000, currency: 'PKR', method: 'bank_transfer', reference: 'HBL-77812', paid_on: '2026-04-07', note: null, status: 'pending', rejection_reason: null },
      ], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 }, pending: 1 }),
      'POST /super-admin/billing/payment-notices/n1/approve': approved,
    }));
    render(<PaymentNotices />);

    expect((await screen.findAllByText('Lahore Sweets')).length).toBeGreaterThan(0);
    fireEvent.click(screen.getAllByRole('button', { name: 'Confirm payment HBL-77812' })[0]);
    await waitFor(() => expect(approved).toHaveBeenCalledTimes(1));
  });

  it('does not let the person who asked for a credit note approve it', async () => {
    const row = { number: null, status: 'pending_approval', settlement: 'refund', reason: 'Outage', store: { id: 5, name: 'Lahore Sweets' }, total_minor: 500000, currency: 'PKR', refund_reference: 'R-1', rejection_reason: null, created_at: '2026-04-07T10:00:00Z' };
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/billing/credit-notes': () => json(200, { data: [{ ...row, id: 'c1', invoice: 'INV-000007', mine: true }, { ...row, id: 'c2', invoice: 'INV-000008', mine: false }], meta: { current_page: 1, last_page: 1, total: 2, per_page: 25 }, pending: 2 }),
    }));
    render(<CreditNotes />);

    expect((await screen.findAllByText('Another team member approves')).length).toBeGreaterThan(0);
    expect(screen.getAllByRole('button', { name: 'Approve credit note for INV-000008' }).length).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: 'Approve credit note for INV-000007' })).toBeNull();
  });
});
