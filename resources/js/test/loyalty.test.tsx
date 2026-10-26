import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import Loyalty, { programSummary } from '@/Pages/Marketing/Loyalty';
import LoyaltyCard, { type CustomerLoyalty } from '@/Components/Customers/LoyaltyCard';
import LoyaltyCheckout from '@/Components/Storefront/LoyaltyCheckout';
import { pointsFor, usablePoints, valueOf } from '@/Storefront/loyalty';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';
import type { LoyaltyProgram, Shell } from '@/Storefront/types';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase P2: loyalty points — the owner's page, the customer card, checkout and the shop's sums. */
beforeEach(() => setPage({ ...owner, currency: 'PKR' }, '/loyalty'));
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const rates = { currency: 'PKR', earn_points: 10, earn_spend_minor: 10000, redeem_points: 100, redeem_value_minor: 1000, min_redeem_points: 200, hold_days: 7, expiry_days: null, birthday_points: 50, repeat_every: 5, repeat_points: 100 };
const stats = { members: 12, outstanding_points: 4500, outstanding_value_minor: 45000, pending_points: 300, earned_30d: 900, redeemed_30d: 400, redeemed_value_30d_minor: 4000 };

describe('the shop’s sums match the server’s', () => {
  it('counts points in whole steps, with the tier bonus rounded down', () => {
    expect(pointsFor(rates, 25999)).toBe(20); // two whole Rs. 100 steps
    expect(pointsFor(rates, 30000, 50)).toBe(45);
    expect(valueOf(rates, 250)).toBe(2000); // only whole blocks of 100
    expect(usablePoints(rates, 150)).toBe(0); // below the minimum of 200
    expect(usablePoints(rates, 390)).toBe(300);
  });

  it('says every rule in one plain sentence', () => {
    expect(programSummary(rates)).toEqual([
      '10 points for every Rs. 100 spent on products.',
      '100 points = Rs. 10 of store credit, from 200 points.',
      'Points can be used 7 days after delivery.',
      'Every 5th order brings 100 bonus points.',
      '50 points on the customer’s birthday.',
      'Points do not expire.',
    ]);
  });
});

describe('the loyalty page', () => {
  it('starts with no rates proposed and shows a live example and the share that comes back', async () => {
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/loyalty/overview': () => json(200, { data: { included: true, running: false, can_manage: true, can_adjust: true, currency: 'PKR', max_tiers: 5, program: null, stats: { ...stats, members: 0 } } }),
      'PUT /loyalty/program': (_url, init) => {
        sent = JSON.parse(init.body as string);
        return json(200, { message: 'Saved.' });
      },
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Loyalty />);

    expect(await screen.findByText('Not set up')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Set up the program' }));
    expect((screen.getByLabelText('Points given') as HTMLInputElement).value).toBe('');
    expect(screen.getByText('Fill in earning and using points to see an example.')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Points given'), { target: { value: '1' } });
    fireEvent.change(screen.getByLabelText('For every (PKR)'), { target: { value: '100' } });
    fireEvent.change(screen.getByLabelText('Points'), { target: { value: '100' } });
    fireEvent.change(screen.getByLabelText('Are worth (PKR)'), { target: { value: '50' } });
    fireEvent.change(screen.getByLabelText('Least points to use'), { target: { value: '100' } });
    expect(screen.getByText('50 points')).toBeTruthy(); // an order of Rs. 5,000
    expect(screen.getByText(/0\.5%/)).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Add tier' }));
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Gold' } });
    fireEvent.change(screen.getByLabelText('From points earned'), { target: { value: '1000' } });
    fireEvent.change(screen.getByLabelText('Extra points (%)'), { target: { value: '25' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save program' }));

    await waitFor(() => expect(sent).toMatchObject({
      earn_points: 1, earn_spend_minor: 10000, redeem_points: 100, redeem_value_minor: 5000, min_redeem_points: 100, hold_days: 0, expiry_days: null,
      birthday_points: 0, repeat_every: null, repeat_points: 0, tiers: [{ name: 'Gold', min_points: 1000, bonus_percent: 25 }],
    }));
  });

  it('shows a running program, its figures, and asks before turning it off', async () => {
    vi.stubGlobal('fetch', routeFetch({
      '/loyalty/overview': () => json(200, { data: { included: true, running: true, can_manage: true, can_adjust: true, currency: 'PKR', max_tiers: 5, stats, program: { ...rates, is_enabled: true, tiers: [], started_at: '2026-10-01T00:00:00Z', return_rate: 0.01 } } }),
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Loyalty />);

    expect(await screen.findByText('Running')).toBeTruthy();
    expect(screen.getByText('About 1% of spending comes back')).toBeTruthy();
    expect(screen.getByText('4,500')).toBeTruthy();
    expect(screen.getByText('Worth Rs. 450 if all were used')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Turn off' }));
    expect(await screen.findByRole('dialog', { name: 'Turn off loyalty points?' })).toBeTruthy();
  });

  it('explains that a package without loyalty points cannot turn it on', async () => {
    vi.stubGlobal('fetch', routeFetch({
      '/loyalty/overview': () => json(200, { data: { included: false, running: false, can_manage: true, can_adjust: true, currency: 'PKR', max_tiers: 5, stats, program: { ...rates, is_enabled: false, tiers: [], started_at: null, return_rate: 0.01 } } }),
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Loyalty />);

    expect(await screen.findByText(/part of the Premium package/)).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Turn on' }) as HTMLButtonElement).disabled).toBe(true);
  });
});

describe('loyalty points on the customer page', () => {
  const CUSTOMER = '01JCUSTOMER000000000000001';
  const loyalty = (overrides: Partial<CustomerLoyalty> = {}): CustomerLoyalty => ({
    balance: 450, value_minor: 4000, currency: 'PKR', lifetime_points: 900, tier: { name: 'Silver', min_points: 500, bonus_percent: 10 },
    next_tier: { name: 'Gold', min_points: 2000, bonus_percent: 25, points_to_go: 1100 }, pending_points: 120, expires_at: null,
    entries: [{ id: 'e1', type: 'earned', points: 300, balance_after: 450, note: 'Order ORD-1', created_at: '2026-10-02T10:00:00Z' }], can_adjust: true, ...overrides,
  });

  it('shows balance, tier and the way to the next, and gives points with a reason', async () => {
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      [`/customers/${CUSTOMER}/loyalty`]: () => json(200, { data: loyalty() }),
      [`POST /customers/${CUSTOMER}/loyalty/adjust`]: (_url, init) => {
        sent = JSON.parse(init.body as string);
        return json(201, { data: loyalty({ balance: 400 }) });
      },
    }));
    render(<LoyaltyCard customerId={CUSTOMER} />);

    expect(await screen.findByText('450')).toBeTruthy();
    expect(screen.getByText('Silver')).toBeTruthy();
    expect(screen.getByText('1,100 points to Gold.')).toBeTruthy();
    expect(screen.getByText('120 more on the way from orders not yet delivered.')).toBeTruthy();
    expect(screen.getByText('Earned on an order')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Change' }));
    const dialog = await screen.findByRole('dialog', { name: 'Change loyalty points' });
    fireEvent.change(within(dialog).getByLabelText('What to do'), { target: { value: 'take' } });
    fireEvent.change(within(dialog).getByLabelText('Points'), { target: { value: '50' } });
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Given twice' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(sent).toMatchObject({ points: -50, note: 'Given twice' }));
    expect(await screen.findByText('400')).toBeTruthy();
  });

  it('stays out of the way for a store without points', async () => {
    vi.stubGlobal('fetch', routeFetch({ [`/customers/${CUSTOMER}/loyalty`]: () => json(200, { data: loyalty({ balance: 0, entries: [], pending_points: 0, tier: null, next_tier: null, can_adjust: false }) }) }));
    const { container } = render(<LoyaltyCard customerId={CUSTOMER} />);
    await waitFor(() => expect(container.textContent).toBe(''));
  });
});

describe('loyalty at checkout', () => {
  const program: LoyaltyProgram = { ...rates, earning: true, tiers: [] };
  const shell = (loyalty: LoyaltyProgram | null) => ({ base_path: '/shop/acme', store: { slug: 'acme', currency: 'PKR' }, loyalty }) as unknown as Shell;

  it('tells a guest what the order would earn with an account', () => {
    render(<LoyaltyCheckout storefront={shell(program)} signedIn={false} baseMinor={250000} onCredited={() => {}} />);
    expect(screen.getByText(/Earn 250 points with this order/)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'sign in or create an account' }).getAttribute('href')).toBe('/shop/acme/account/login');
  });

  it('turns a signed-in customer’s points into credit for this order in one step', async () => {
    const onCredited = vi.fn();
    let sent: Record<string, unknown> | null = null;
    const mine = { program, balance: 380, value_minor: 3000, currency: 'PKR', lifetime_points: 380, tier: null, next_tier: null, pending_points: 0, expires_at: null, entries: [] };
    vi.stubGlobal('fetch', routeFetch({
      '/customer/loyalty': () => json(200, { data: mine }),
      'POST /customer/loyalty/redeem': (_url, init) => {
        sent = JSON.parse(init.body as string);
        return json(201, { data: { ...mine, balance: 80, value_minor: 0, credited_minor: 3000 } });
      },
    }));
    render(<LoyaltyCheckout storefront={shell(program)} signedIn baseMinor={100000} onCredited={onCredited} />);

    expect(screen.getByText('You will earn about 100 points with this order.')).toBeTruthy();
    fireEvent.click(await screen.findByRole('button', { name: 'Use 300 points for Rs. 30' }));
    await waitFor(() => expect(onCredited).toHaveBeenCalled());
    expect(sent).toMatchObject({ points: 300 });
    expect(screen.getByText('Rs. 30 was added to your store credit and will be used for this order.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Use .* points/ })).toBeNull();
  });

  it('shows nothing when the store gives no points', () => {
    const { container } = render(<LoyaltyCheckout storefront={shell(null)} signedIn baseMinor={100000} onCredited={() => {}} />);
    expect(container.textContent).toBe('');
  });
});
