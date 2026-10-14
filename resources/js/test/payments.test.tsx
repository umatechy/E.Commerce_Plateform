import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import PaymentsSetup from '@/Pages/Settings/Payments';
import PaymentsIndex from '@/Pages/Payments/Index';
import SuperAdminPayments from '@/Pages/SuperAdmin/Payments';
import PaymentChoice, { type PaymentOption } from '@/Components/Storefront/PaymentChoice';
import WalletPayment from '@/Components/Storefront/WalletPayment';
import { clearToasts } from '@/Components/ui/toast';
import { normalizeMobile } from '@/lib/payments';
import type { Shell } from '@/Storefront/types';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B48 (gap G9; Module 12): payment setup, checkout choices, the wallet payment page, findings, providers. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
  window.history.replaceState(null, '', '/'); // tabs keep their state in the address
});

const fields = {
  jazzcash: [{ key: 'merchant_id', label: 'Merchant ID', secret: false, max_length: 40, help: null }, { key: 'password', label: 'Password', secret: true, max_length: 100, help: null }, { key: 'integrity_salt', label: 'Integrity salt', secret: true, max_length: 100, help: null }],
  easypaisa: [{ key: 'store_id', label: 'Store ID', secret: false, max_length: 20, help: null }, { key: 'password', label: 'API password', secret: true, max_length: 100, help: null }],
};
const caps = (verified: boolean) => ({ wallet_payment: true, card_payment: false, status_inquiry: true, refund: false, partial_refund: false, notifications: true, signed_notifications: true, currencies: ['PKR'], verified, note: null });
const account = (extra: Record<string, unknown>) => ({ id: 'acc', environment: 'sandbox', is_enabled: false, credential_hints: { store_id: '43••••21', password: 'set' }, min_amount_minor: null, max_amount_minor: null, verified_at: null, last_success_at: null, last_failure_at: null, last_failure_category: null, updated_at: null, ...extra });
const settings = (canConfigure = true) => ({ data: {
  can_configure: canConfigure, online_included: true, currency: 'PKR',
  cod: { enabled: true, min_order_minor: 0, max_order_minor: 0 },
  bank_transfer: { enabled: false, account_title: null, bank_name: null, account_number: null, instructions: null, complete: false },
  providers: [
    { provider: 'jazzcash', label: 'JazzCash', capabilities: caps(false), credential_fields: fields.jazzcash, live_allowed: false, account: null },
    { provider: 'easypaisa', label: 'Easypaisa', capabilities: caps(false), credential_fields: fields.easypaisa, live_allowed: false, account: account({}) },
  ],
  card: { available: false },
  checkout_preview: ['cod'],
} });

describe('Payments setup', () => {
  beforeEach(() => setPage({ ...owner, currency: 'PKR' }, '/settings/payments'));

  it('shows what checkout offers and each wallet’s state, and connects a sandbox account without echoing secrets', async () => {
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/payment-settings': () => json(200, settings()),
      'PUT /payment-settings/providers/jazzcash': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: {} });
      },
    }));
    render(<PaymentsSetup />);

    const preview = await screen.findByRole('region', { name: 'Your customers can pay with' });
    expect(within(preview).getByText('Cash on delivery')).toBeTruthy();
    expect(screen.getByText('Sandbox — testing only')).toBeTruthy();
    expect(screen.getAllByText(/has not yet checked JazzCash in its sandbox/).length).toBe(1);
    expect(screen.getByText('Card payments')).toBeTruthy();
    expect(screen.getByText('Account needed')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Connect JazzCash' }));
    const dialog = await screen.findByRole('dialog');
    expect((within(dialog).getByRole('radio', { name: /Production/ }) as HTMLInputElement).disabled).toBe(true);
    fireEvent.change(within(dialog).getByLabelText('Merchant ID'), { target: { value: 'MC10001' } });
    fireEvent.change(within(dialog).getByLabelText('Password'), { target: { value: 'pw' } });
    fireEvent.change(within(dialog).getByLabelText('Integrity salt'), { target: { value: 'salt' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save details' }));

    await waitFor(() => expect(sent).toEqual({ environment: 'sandbox', credentials: { merchant_id: 'MC10001', password: 'pw', integrity_salt: 'salt' }, min_amount_minor: null, max_amount_minor: null }));
  });

  it('is read-only for someone who may not change where the money goes', async () => {
    vi.stubGlobal('fetch', routeFetch({ '/payment-settings': () => json(200, settings(false)) }));
    render(<PaymentsSetup />);

    expect(await screen.findByText(/Only the store owner/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Connect/ })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save bank transfer' })).toBeNull();
  });
});

const shell = { base_path: '/shop/acme', store: { slug: 'acme', currency: 'PKR' } } as unknown as Shell;

describe('Checkout payment choice', () => {
  beforeEach(() => setPage(owner, '/shop/acme/checkout', { storefront: { language: { current: 'en' } } }));

  const options: PaymentOption[] = [
    { method: 'cod', kind: 'cod', min_minor: null, max_minor: 300000 },
    { method: 'jazzcash', kind: 'wallet', label: 'JazzCash', payer_fields: [{ key: 'mobile', label: '', hint: '' }, { key: 'cnic_last6', label: '', hint: '' }] },
  ];

  it('disables a method this cart is outside of, with the reason, and asks a wallet for its number', () => {
    const onChange = vi.fn();
    const { rerender } = render(<PaymentChoice options={options} value="cod" onChange={onChange} payer={{ mobile: '', cnic_last6: '' }} onPayer={() => undefined} payerErrors={{}} totalMinor={450000} currency="PKR" />);

    expect((screen.getByRole('radio', { name: /Cash on delivery/ }) as HTMLInputElement).disabled).toBe(true);
    expect(screen.getByText(/Available for orders up to/)).toBeTruthy();
    fireEvent.click(screen.getByRole('radio', { name: /JazzCash/ }));
    expect(onChange).toHaveBeenCalledWith('jazzcash');

    rerender(<PaymentChoice options={options} value="jazzcash" onChange={onChange} payer={{ mobile: '', cnic_last6: '' }} onPayer={() => undefined} payerErrors={{ mobile: 'Enter the number.' }} totalMinor={450000} currency="PKR" />);
    expect(screen.getByLabelText('JazzCash mobile number')).toBeTruthy();
    expect(screen.getByLabelText('Last 6 digits of your CNIC')).toBeTruthy();
    expect(screen.getByRole('alert').textContent).toBe('Enter the number.');
  });

  it('reads Pakistani mobile numbers the way the server does', () => {
    expect(normalizeMobile('+92 300 1234567')).toBe('03001234567');
    expect(normalizeMobile('0300-1234567')).toBe('03001234567');
    expect(normalizeMobile('12345')).toBeNull();
  });
});

describe('Wallet payment page', () => {
  beforeEach(() => setPage(owner, '/shop/acme/checkout', { storefront: { language: { current: 'en' } } }));

  const attempt = (state: string, extra: Record<string, unknown> = {}) => ({ id: 'a1', provider: 'jazzcash', reference: 'UT01', amount_minor: 450000, currency: 'PKR', payer_hint: '0300•••4567', status: state, state, message: null, reason: null, expires_at: '2026-10-14T10:10:00Z', created_at: '2026-10-14T10:00:00Z', completed_at: null, ...extra });
  const view = (state: string, extra: Record<string, unknown> = {}) => ({ data: {
    id: 'p1', order_number: 'ORD-1001', method: 'jazzcash', status: state === 'paid' ? 'paid' : 'requires_action', amount_minor: 450000, currency: 'PKR', pay_by: '2026-10-14T10:15:00Z', can_retry: state === 'failed',
    options: [{ method: 'jazzcash', provider: 'jazzcash', label: 'JazzCash', payer_fields: [{ key: 'mobile', label: '', hint: '' }, { key: 'cnic_last6', label: '', hint: '' }] }, { method: 'easypaisa', provider: 'easypaisa', label: 'Easypaisa', payer_fields: [{ key: 'mobile', label: '', hint: '' }] }],
    attempt: attempt(state), ...extra,
  } });

  it('tells the shopper to approve on the phone while the request waits', async () => {
    vi.stubGlobal('fetch', routeFetch({ '/checkout/payments/p1': () => json(200, view('waiting')) }));
    render(<WalletPayment storefront={shell} paymentId="p1" token="tok" />);

    expect(await screen.findByText('Check your phone')).toBeTruthy();
    expect(screen.getByText(/0300•••4567/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Send payment request/ })).toBeNull();
  });

  it('after a decline, retries with the other wallet carrying the token, and reports when it is paid', async () => {
    let headers: Headers | null = null;
    let body: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/checkout/payments/p1': () => json(200, view('failed', { attempt: attempt('failed', { reason: 'payment_declined' }) })),
      'POST /checkout/payments/p1/attempts': (_url, init) => {
        headers = new Headers(init.headers);
        body = JSON.parse(String(init.body));

        return json(201, view('paid'));
      },
    }));
    const onPaid = vi.fn();
    render(<WalletPayment storefront={shell} paymentId="p1" token="tok" onPaid={onPaid} />);

    expect(await screen.findByText('Payment not completed')).toBeTruthy();
    expect(screen.getByText(/was not approved/)).toBeTruthy();
    fireEvent.click(screen.getByRole('radio', { name: 'Easypaisa' }));
    expect(screen.queryByLabelText('Last 6 digits of your CNIC')).toBeNull();
    fireEvent.change(screen.getByLabelText('Easypaisa mobile number'), { target: { value: '0345 1234567' } });
    fireEvent.click(screen.getByRole('button', { name: /Send payment request/ }));

    await waitFor(() => expect(onPaid).toHaveBeenCalled());
    expect(body).toEqual({ method: 'easypaisa', payer: { mobile: '0345 1234567' } });
    expect(headers!.get('X-Payment-Token')).toBe('tok');
    expect(screen.getByText('Payment received')).toBeTruthy();
  });
});

describe('Payments — needs attention', () => {
  beforeEach(() => setPage({ ...owner, currency: 'PKR' }, '/payments'));

  it('lists what reconciliation found and resolves it with a note', async () => {
    let note: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/payments': () => json(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } }),
      '/payment-reconciliation': () => json(200, { data: [{ id: 'f1', kind: 'amount_mismatch', status: 'open', details: { expected_minor: 450000, expected_currency: 'PKR', confirmed_minor: 449999, confirmed_currency: 'PKR', reference: 'UT01' }, payment: { id: 'p1', order: { id: 'o1', order_number: 'ORD-1001' } }, attempt: null, resolution_note: null, resolved_at: null, created_at: '2026-10-14T10:00:00Z' }], meta: { open_count: 1 } }),
      'POST /payment-reconciliation/f1/resolve': (_url, init) => {
        note = JSON.parse(String(init.body)).note;

        return json(200, { data: {} });
      },
    }));
    render(<PaymentsIndex />);

    fireEvent.click(await screen.findByRole('tab', { name: 'Needs attention (1)' }));
    expect(await screen.findByText('Amount does not match')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Resolve' }));
    fireEvent.change(await screen.findByLabelText('What was done'), { target: { value: 'Refunded 4,499.99 in the JazzCash portal.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Mark as resolved' }));
    await waitFor(() => expect(note).toBe('Refunded 4,499.99 in the JazzCash portal.'));
  });
});

describe('Super Admin — payment providers', () => {
  beforeEach(() => setPage({ ...owner, user: { ...owner.user!, is_platform_staff: true } }, '/super-admin/payments'));

  it('opens a provider for real money only with a reason', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/payment-providers': () => json(200, { data: { simulator_enabled: false, open_findings: 2, card: { available: false }, providers: [
        { provider: 'jazzcash', label: 'JazzCash', capabilities: caps(false), credential_fields: fields.jazzcash, live_allowed: false, account: null,
          stores: { sandbox: 3, production: 0, taking_payments: 0 }, last_24h: { succeeded: 0, failed: 0, expired: 0, pending: 0, unknown: 0 } },
      ] } }),
      'PUT /super-admin/payment-providers/jazzcash/live': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: {} });
      },
    }));
    render(<SuperAdminPayments />);

    expect(await screen.findByText('Sandbox only')).toBeTruthy();
    expect(screen.getByText('Not yet checked in sandbox')).toBeTruthy();
    expect(screen.getByRole('tab', { name: 'Findings (2)' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Open for real payments' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Checked in the JazzCash sandbox' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Open for real payments' }));
    await waitFor(() => expect(sent).toEqual({ allowed: true, reason: 'Checked in the JazzCash sandbox' }));
  });
});
