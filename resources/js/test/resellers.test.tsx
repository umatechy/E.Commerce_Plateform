import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import Portal from '@/Pages/Partner/Portal';
import Join, { invitationToken } from '@/Pages/Partner/Join';
import Resellers from '@/Pages/SuperAdmin/Resellers';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';
import { accessFrom } from '@/lib/access';
import { navigationFor } from '@/lib/adminNav';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B58 (Module 28 §44, §46, §92): the partner portal, joining, and Super Admin → Resellers. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const partner = { ...owner, activeStore: null, permissions: [], is_owner: false, is_reseller: true };
const overview = {
  business_name: 'Pixel Agency', contact_name: 'Ayesha', status: 'active', code: 'PIXEL42', signup_link: 'https://umartechy.example/register?partner=PIXEL42',
  tier: { name: 'Silver', client_discount_percent: 10, initial_commission_percent: 20, recurring_commission_percent: 10, recurring_months: 12, hold_days: 30, can_provision: true, stores_left: 4, payout_threshold_minor: 0 },
  stats: { stores: 1, paying: 1, trialing: 0 },
  balances: { PKR: { pending_minor: 54000, payable_minor: 27000, in_payout_minor: 0, paid_minor: 0 } },
  payout_details: { method: null, account_title: null, account: null, bank: null },
  business_categories: [{ value: 'fashion', label: 'Clothing & fashion' }],
};

describe('the partner portal', () => {
  it('shows the menu entry only to resellers', () => {
    const hrefs = (props: typeof partner) => navigationFor(accessFrom(props)).flatMap((g) => g.items.map((i) => i.href));
    expect(hrefs(partner)).toContain('/partner');
    expect(hrefs({ ...partner, is_reseller: false })).not.toContain('/partner');
  });

  it('asks for two-step sign-in before it opens', async () => {
    setPage(partner, '/partner');
    vi.stubGlobal('fetch', routeFetch({ '/partner/overview': () => json(403, { message: 'Turn on two-step sign-in to use the partner portal.', code: 'mfa_enrollment_required' }) }));
    render(<Portal />);

    expect(await screen.findByText('Turn on two-step sign-in')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Go to Security' }).getAttribute('href')).toBe('/security');
  });

  it('shows the sign-up link and creates a store for a client once, with an idempotency key', async () => {
    setPage(partner, '/partner');
    let sent: { body?: Record<string, unknown>; key?: string | null } = {};
    vi.stubGlobal('fetch', routeFetch({
      '/partner/overview': () => json(200, { data: overview }),
      '/partner/stores': () => json(200, { data: [] }),
      '/partner/packages': () => json(200, { data: [{ code: 'business', name: 'Business', prices: [{ interval: 'monthly', currency: 'PKR', public_minor: 500000, partner_minor: 450000 }] }] }),
      'POST /partner/stores': (_u, init) => {
        sent = { body: JSON.parse(String(init.body)), key: new Headers(init.headers).get('Idempotency-Key') };
        return json(201, { data: { id: '01S', store: 'Rida Boutique' }, message: 'Store created.' });
      },
    }));
    render(<Portal />);

    expect(((await screen.findByLabelText('Your sign-up link')) as HTMLInputElement).value).toBe(overview.signup_link);
    expect(await screen.findByText('No stores yet')).toBeTruthy();
    fireEvent.click(screen.getAllByRole('button', { name: 'Create a store for a client' })[0]);
    const dialog = await screen.findByRole('dialog', { name: 'Create a store for a client' });
    fireEvent.change(within(dialog).getByLabelText('Store name'), { target: { value: 'Rida Boutique' } });
    fireEvent.change(within(dialog).getByLabelText('What the client sells'), { target: { value: 'fashion' } });
    await waitFor(() => expect(within(dialog).getByRole('option', { name: 'Business' })).toBeTruthy());
    fireEvent.change(within(dialog).getByLabelText('Package'), { target: { value: 'business' } });
    expect(within(dialog).getByText(/public price/)).toBeTruthy();
    fireEvent.change(within(dialog).getByLabelText(/Client’s email/), { target: { value: 'rida@client.example' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create store' }));

    await waitFor(() => expect(sent.body).toMatchObject({ store_name: 'Rida Boutique', business_category: 'fashion', package_code: 'business', owner_email: 'rida@client.example', starter_template: true }));
    expect(sent.key).toBeTruthy();
  });
});

describe('joining from the invitation', () => {
  const token = 'a'.repeat(24) + 'B7'.repeat(12); // 48 letters and digits, as the server makes them

  it('accepts only a whole token', () => {
    expect(invitationToken(`#token=${token}`)).toBe(token);
    expect(invitationToken('#token=short')).toBeNull();
    expect(invitationToken('')).toBeNull();
  });

  it('reads the token from the fragment, removes it from the address and creates the account', async () => {
    window.history.replaceState(null, '', `/partner/join#token=${token}`);
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign, hash: `#token=${token}`, pathname: '/partner/join' });
    let registered: Record<string, unknown> = {};
    const fetchMock = routeFetch({
      'POST /partner/invitations/lookup': () => json(200, { data: { business_name: 'Pixel Agency', email: 'ayesha@pixel.example', tier: 'Silver', account_exists: false, signed_in_as: null, signed_in_matches: false } }),
      'POST /partner/invitations/register': (_u, init) => {
        registered = JSON.parse(String(init.body));
        return json(201, { data: { mfa_required: true } });
      },
    });
    vi.stubGlobal('fetch', fetchMock);
    setPage({ ...owner, user: null }, '/partner/join');
    render(<Join />);

    expect(await screen.findByText('Pixel Agency')).toBeTruthy();
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).not.toContain(token);
    expect(JSON.parse(String(init.body))).toEqual({ token });
    fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Ayesha Khan' } });
    fireEvent.change(screen.getByLabelText('Choose a password'), { target: { value: 'Partner-Pass-2026!' } });
    fireEvent.change(screen.getByLabelText('Repeat the password'), { target: { value: 'Partner-Pass-2026!' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create account and accept' }));

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/security'));
    expect(registered).toMatchObject({ token, name: 'Ayesha Khan' });
  });
});

describe('Super Admin → Resellers', () => {
  const staff = { ...owner, user: { ...owner.user!, is_platform_staff: true }, activeStore: null, platform_capabilities: ['view', 'resellers'] };
  const tier = { id: '01T', name: 'Silver', description: null, is_active: true, client_discount_percent: 10, initial_commission_percent: 20, recurring_commission_percent: 10, recurring_months: 12, hold_days: 30, can_provision: true, max_stores: 5, payout_threshold_minor: 0, resellers: 0 };

  it('creates a tier in percent and invites a reseller, showing the link once', async () => {
    setPage(staff, '/super-admin/resellers');
    let tierBody: Record<string, unknown> = {};
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/resellers/overview': () => json(200, { data: { tiers: [tier], counts: { pending: 1 }, stores: 0, totals: {}, currencies: ['PKR', 'USD'] } }),
      '/super-admin/resellers': () => json(200, { data: [] }),
      'POST /super-admin/resellers/tiers': (_u, init) => {
        tierBody = JSON.parse(String(init.body));
        return json(201, { data: tier, message: 'Tier created.' });
      },
      'POST /super-admin/resellers': () => json(201, { data: { id: '01R', code: 'PIXEL42', invitation_url: 'https://umartechy.example/partner/join#token=secret' } }),
    }));
    render(<Resellers />);

    fireEvent.click(await screen.findByRole('tab', { name: /Tiers/ }));
    fireEvent.click(screen.getByRole('button', { name: 'New tier' }));
    const dialog = await screen.findByRole('dialog', { name: 'New tier' });
    fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Gold' } });
    fireEvent.change(within(dialog).getByLabelText('Client discount (%)'), { target: { value: '12.5' } });
    fireEvent.change(within(dialog).getByLabelText('First invoice (%)'), { target: { value: '25' } });
    fireEvent.change(within(dialog).getByLabelText('Renewals (%)'), { target: { value: '10' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create tier' }));
    await waitFor(() => expect(tierBody).toMatchObject({ name: 'Gold', client_discount_percent: 12.5, initial_commission_percent: 25, recurring_commission_percent: 10, recurring_months: null, can_provision: false }));

    fireEvent.click(screen.getByRole('tab', { name: /Resellers/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Invite reseller' }));
    const invite = await screen.findByRole('dialog', { name: 'Invite a reseller' });
    fireEvent.change(within(invite).getByLabelText('Business name'), { target: { value: 'Pixel Agency' } });
    fireEvent.change(within(invite).getByLabelText('Contact person'), { target: { value: 'Ayesha' } });
    fireEvent.change(within(invite).getByLabelText('Email'), { target: { value: 'ayesha@pixel.example' } });
    fireEvent.click(within(invite).getByRole('button', { name: 'Send invitation' }));
    expect(((await screen.findByLabelText('Invitation link')) as HTMLInputElement).value).toContain('#token=secret');
  });
});
