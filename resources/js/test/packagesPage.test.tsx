import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import Packages from '@/Pages/SuperAdmin/Packages';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-29: a package lists only what it includes; a higher tier builds on the one below. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const feature = (key: string, enabled: boolean) => ({ key, type: 'feature', enabled, unlimited: false, limit: null });

describe('the packages page', () => {
  it('shows only included features, and each higher tier as the one below plus what it adds', async () => {
    setPage({ ...owner, user: { ...owner.user!, is_platform_staff: true } }, '/super-admin/packages');
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/packages': () => json(200, { data: [
        // Listed out of order on purpose: the page orders them by what they include.
        { code: 'premium', name: 'Premium', is_active: true, services: ['A dedicated WhatsApp support line'], entitlements: [feature('orders.basic', true), feature('product.variants', true), feature('ai.chatbot', true), { ...feature('loyalty.points', true), coming_soon: true }] },
        { code: 'basic', name: 'Basic', is_active: true, entitlements: [feature('orders.basic', true), feature('product.variants', false), feature('ai.chatbot', false)] },
        { code: 'business', name: 'Business', is_active: true, entitlements: [feature('orders.basic', true), feature('product.variants', true), feature('ai.chatbot', false)] },
        // Has something new but lacks a feature of the tier below: no "Everything in" claim.
        { code: 'special', name: 'Special', is_active: false, entitlements: [feature('orders.basic', false), feature('product.variants', true), feature('ai.chatbot', true), feature('pwa.enabled', true)] },
      ] }),
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Packages />);

    await screen.findByRole('heading', { level: 2, name: 'Premium' });
    const cards = Array.from(document.querySelectorAll('section'));
    const byName = (name: string) => cards.find((card) => within(card).queryByRole('heading', { level: 2, name }))!;

    // No Yes/No anywhere.
    expect(screen.queryByText('Yes')).toBeNull();
    expect(screen.queryByText('No')).toBeNull();

    const basic = within(byName('Basic'));
    expect(basic.queryByText(/Everything in/)).toBeNull();
    expect(basic.getAllByRole('listitem').map((li) => li.textContent)).toEqual(['Orders']);

    const business = within(byName('Business'));
    expect(business.getByText('Everything in Basic, plus:')).toBeTruthy();
    expect(business.getAllByRole('listitem').map((li) => li.textContent)).toEqual(['Product variants']);

    const premium = within(byName('Premium'));
    expect(premium.getByText('Everything in Business, plus:')).toBeTruthy();
    // P1: a feature not built yet is not among the included ones; it sits under "Coming soon". Services are shown.
    const lists = premium.getAllByRole('list');
    expect(within(lists[0]).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['AI chatbot']);
    expect(premium.getByText(/Coming soon/)).toBeTruthy();
    expect(premium.getByText('Loyalty points')).toBeTruthy();
    expect(premium.getByText('Services included')).toBeTruthy();
    expect(premium.getByText('A dedicated WhatsApp support line')).toBeTruthy();

    const special = within(byName('Special'));
    expect(special.queryByText(/Everything in/)).toBeNull();
    expect(special.getAllByRole('listitem')).toHaveLength(3);
  });
});
