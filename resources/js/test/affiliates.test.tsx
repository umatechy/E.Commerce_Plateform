import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import Affiliates from '@/Pages/Marketing/Affiliates';
import AffiliatePortal from '@/Pages/Storefront/AffiliatePortal';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';
import { referralToken, rememberReferral } from '@/Storefront/referral';
import type { Seo, Shell } from '@/Storefront/types';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B57 (Module 28): the store's affiliate program — admin, the referral on the shop, and the affiliate's page. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
  window.localStorage.clear();
  window.location.hash = '';
});

const shell = {
  store: { name: 'Demo', slug: 'demo', currency: 'PKR', locale: 'en', timezone: 'Asia/Karachi', tagline: null, logo_url: null, favicon_url: null, social_links: {} },
  theme: { tokens: {}, custom_css: null },
  announcement: null,
  navigation: { categories: [], pages: [] },
  base_path: '/shop/demo',
  preview: false,
} as unknown as Shell;
const seo = { title: 'Demo', description: null, canonical: '/', robots: 'noindex' } as unknown as Seo;

describe('the referral on the shop', () => {
  it('keeps the signed token for the window, sends it with the order, and forgets it after', () => {
    const now = Date.UTC(2026, 9, 15);
    rememberReferral({ ...shell, referral: { token: 'signed.abc', code: 'SANA', days: 30 } }, now);
    expect(referralToken(shell, now + 29 * 86_400_000)).toBe('signed.abc');
    expect(referralToken({ ...shell, referral: { token: 'newer.def', code: 'BILAL', days: 30 } }, now)).toBe('newer.def');
    expect(referralToken({ ...shell, store: { ...shell.store, slug: 'other' } }, now)).toBeNull();
    expect(referralToken(shell, now + 31 * 86_400_000)).toBeNull();
    expect(window.localStorage.getItem('ut-ref-demo')).toBeNull();
  });
});

const stats = { clicks_30d: 120, orders_30d: 4, sales_30d_minor: 800000, commission_30d_minor: 80000, pending_minor: 60000, payable_minor: 20000, paid_minor: 0, in_review: 1, applications: 1, active_affiliates: 2 };
const rules = { commission_type: 'percentage', rate_bp: 1000, rate_percent: 10, fixed_minor: null, window_days: 30, hold_days: 14, require_delivery: true, require_payment: true, new_customers_only: false, min_order_minor: 0, cap_per_order_minor: null, payout_threshold_minor: 0, self_referral: 'review' };
const overview = (program: unknown) => ({
  data: { included: true, can_manage: true, can_pay: true, currency: 'PKR', program, defaults: rules, limits: { max_window_days: 90, max_rate_percent: 50 }, stats, coupons: [{ id: 7, code: 'SANA10' }] },
});
const running = { id: '01P', name: 'Partners', status: 'active', accepts_applications: true, version: 2, currency: 'PKR', terms: '', rules, summary: ['You earn 10% of the products in each order you bring, after discounts. Shipping and tax do not count.'] };
const balance = { pending_minor: 0, payable_minor: 0, paid_minor: 0, in_payout_minor: 0 };
const hira = {
  id: '01H', name: 'Hira', email: 'hira@example.com', phone: null, status: 'pending', status_reason: null, code: 'HIRA', coupon: null, source: 'applied',
  application_note: 'My Instagram page', is_customer: false, created_at: '2026-10-14T10:00:00Z', approved_at: null,
  payout: { method: null, account_title: null, account: null, bank: null }, clicks_30d: 0, orders: 0, sales_minor: 0, balance,
};

describe('Marketing → Affiliates', () => {
  beforeEach(() => setPage({ ...owner, permissions: ['affiliates.view', 'affiliates.manage', 'affiliates.payouts'] }, '/affiliates'));

  it('starts with setting the rules: nothing counts before', async () => {
    let saved: Record<string, unknown> = {};
    vi.stubGlobal('fetch', routeFetch({
      '/affiliates/overview': () => json(200, overview(null)),
      'PUT /affiliates/program': (_u, init) => {
        saved = JSON.parse(String(init.body));
        return json(200, { data: running, message: 'Program saved.' });
      },
    }));
    render(<Affiliates />);

    expect(await screen.findByText('Not set up')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Set up the program' }));
    fireEvent.change(screen.getByLabelText('Rate (%)'), { target: { value: '12.5' } });
    fireEvent.change(screen.getByLabelText(/Least payout/), { target: { value: '1,000' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save program' }));

    await waitFor(() => expect(saved.rate_percent).toBe(12.5));
    expect(saved).toMatchObject({ commission_type: 'percentage', fixed_minor: null, window_days: 30, hold_days: 14, payout_threshold_minor: 100000, self_referral: 'review', cap_per_order_minor: null });
  });

  it('approves an application and shows the private link once', async () => {
    vi.stubGlobal('fetch', routeFetch({
      '/affiliates/overview': () => json(200, overview(running)),
      '/affiliates': () => json(200, { data: [hira] }),
      '/affiliates/01H/adjustments': () => json(200, { data: [] }),
      'POST /affiliates/01H/approve': () => json(200, { data: { portal_url: 'https://demo.example.com/affiliate#token=secret' }, message: 'Approved.' }),
    }));
    render(<Affiliates />);

    expect(await screen.findByText('Running')).toBeTruthy();
    fireEvent.click(screen.getByRole('tab', { name: /Affiliates \(1 new\)/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Open Hira' }));
    expect(await screen.findByText('My Instagram page')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }));

    const link = (await screen.findByLabelText('Private link')) as HTMLInputElement;
    expect(link.value).toBe('https://demo.example.com/affiliate#token=secret');
    expect(screen.getByText(/it is shown only this once/)).toBeTruthy();
  });

  it('explains why a commission was held back and records the decision', async () => {
    let decided: Record<string, unknown> = {};
    const held = {
      id: '01C', status: 'review', source: 'link', affiliate: { id: '01H', name: 'Hira', code: 'HIRA' }, order: { id: '01O', number: 'ORD-000042', status: 'confirmed' },
      base_minor: 400000, amount_minor: 40000, reversed_minor: 0, net_minor: 40000, currency: 'PKR', rate_percent: 10, program_version: 2,
      flags: { self_referral: ['same_email', 'same_phone'] }, note: null, hold_until: null, created_at: '2026-10-14T10:00:00Z', in_payout: false,
    };
    vi.stubGlobal('fetch', routeFetch({
      '/affiliates/overview': () => json(200, overview(running)),
      '/affiliates/commissions': () => json(200, { data: [held], meta: { total: 1 } }),
      'POST /affiliates/commissions/01C/decide': (_u, init) => {
        decided = JSON.parse(String(init.body));
        return json(200, { message: 'Rejected: it will not be paid.' });
      },
    }));
    render(<Affiliates />);

    fireEvent.click(await screen.findByRole('tab', { name: /Commissions \(1 to review\)/ }));
    expect(await screen.findByText(/matches the affiliate’s email address and phone number/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Reject' }));
    const dialog = await screen.findByRole('dialog', { name: 'Reject this commission?' });
    fireEvent.change(within(dialog).getByLabelText(/Note/), { target: { value: 'Own order' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reject' }));
    await waitFor(() => expect(decided).toEqual({ decision: 'reject', note: 'Own order' }));
  });

  it('shows where to send a payout only when paying, and records the reference', async () => {
    let paid: { body?: Record<string, unknown>; key?: string | null } = {};
    const payout = { id: '01Y', affiliate: { id: '01H', name: 'Hira' }, status: 'pending', amount_minor: 20000, currency: 'PKR', method: 'jazzcash', reference: null, note: null, created_at: '2026-10-15T10:00:00Z', paid_at: null };
    vi.stubGlobal('fetch', routeFetch({
      '/affiliates/overview': () => json(200, overview(running)),
      '/affiliates/payouts': () => json(200, { data: [payout] }),
      'POST /affiliates/payouts/01Y/pay-to': () => json(200, { data: { method: 'jazzcash', account_title: 'Hira K', account_number: '03001234567', bank: null } }),
      'POST /affiliates/payouts/01Y/paid': (_u, init) => {
        paid = { body: JSON.parse(String(init.body)), key: new Headers(init.headers).get('Idempotency-Key') };
        return json(200, { message: 'Recorded as paid.' });
      },
    }));
    render(<Affiliates />);

    fireEvent.click(await screen.findByRole('tab', { name: 'Payouts' }));
    expect(screen.queryByText('03001234567')).toBeNull();
    fireEvent.click(await screen.findByRole('button', { name: 'Pay' }));
    expect(await screen.findByText('03001234567')).toBeTruthy();
    expect(screen.getByText(/recorded in the audit log/)).toBeTruthy();
    const record = screen.getAllByRole('button', { name: 'Record as paid' }).at(-1) as HTMLButtonElement;
    expect(record.disabled).toBe(true);
    fireEvent.change(screen.getByLabelText('Transaction reference'), { target: { value: 'JC-778899' } });
    fireEvent.click(record);
    await waitFor(() => expect(paid.body).toEqual({ reference: 'JC-778899' }));
    expect(paid.key).toBeTruthy();
  });
});

describe('the affiliate’s private page', () => {
  const portal = {
    name: 'Hira', status: 'active', code: 'HIRA', link: 'https://demo.example.com/?ref=HIRA', coupon: 'SANA10',
    program: { name: 'Partners', running: true, summary: ['You earn 10%.'], terms: null }, stats: { clicks_30d: 12, orders: 1 },
    balance: { pending_minor: 40000, payable_minor: 0, paid_minor: 0, in_payout_minor: 0 }, currency: 'PKR',
    commissions: [{ date: '2026-10-14T10:00:00Z', sale_minor: 400000, commission_minor: 40000, status: 'pending', source: 'link' }],
    payouts: [], adjustments: [], payout_details: { method: null, account_title: null, account: null, bank: null },
  };

  it('reads the token from the link’s fragment and sends it only in a header', async () => {
    window.location.hash = '#token=secret-token';
    const fetchMock = routeFetch({ '/storefront/affiliate': () => json(200, { data: portal }) });
    vi.stubGlobal('fetch', fetchMock);
    setPage({ ...owner }, '/shop/demo/affiliate', { storefront: shell });
    render(<AffiliatePortal storefront={shell} seo={seo} />);

    expect(await screen.findByText('Hello, Hira')).toBeTruthy();
    expect((screen.getByRole('textbox', { name: 'Your link' }) as HTMLInputElement).value).toBe('https://demo.example.com/?ref=HIRA');
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).not.toContain('secret-token');
    expect(new Headers(init.headers).get('X-Affiliate-Token')).toBe('secret-token');
    expect(screen.getByText('Add your account so the store can pay you.')).toBeTruthy();
  });

  it('says plainly when the link no longer works', async () => {
    window.location.hash = '#token=old';
    vi.stubGlobal('fetch', routeFetch({ '/storefront/affiliate': () => json(404, { message: 'Not found' }) }));
    setPage({ ...owner }, '/shop/demo/affiliate', { storefront: shell });
    render(<AffiliatePortal storefront={shell} seo={seo} />);

    expect(await screen.findByText('This private link does not work any more. Ask the store for a new one.')).toBeTruthy();
  });
});
