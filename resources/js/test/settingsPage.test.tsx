import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import Settings from '@/Pages/Settings/Index';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-9: store settings in groups, each value as a chip, changes in a dialog. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const settings = [
  { key: 'store.contact_email', scope: 'store', type: 'string', value: null, configured: null, masked: null },
  { key: 'store.default_currency', scope: 'store', type: 'string', value: 'PKR', configured: null, masked: null },
  { key: 'reviews.enabled', scope: 'store', type: 'boolean', value: true, configured: null, masked: null },
  { key: 'badges.sale', scope: 'store', type: 'boolean', value: false, configured: null, masked: null },
];

describe('store settings', () => {
  it('lists settings under their groups and opens a change dialog', async () => {
    setPage(owner, '/settings');
    vi.stubGlobal('fetch', routeFetch({ '/store/settings': () => json(200, { data: settings }) }));
    render(<Settings />);

    const business = await screen.findByRole('region', { name: 'Business information' });
    expect(within(business).getByText('Contact email', { selector: 'p' })).toBeTruthy();
    expect(within(business).getByText('Not set')).toBeTruthy();
    expect(within(screen.getByRole('region', { name: 'Money and time' })).getByText('PKR')).toBeTruthy();
    expect(within(screen.getByRole('region', { name: 'Reviews and units sold' })).getByText('On')).toBeTruthy();
    expect(within(screen.getByRole('region', { name: 'Product badges' })).getByText('Off')).toBeTruthy();

    fireEvent.click(within(business).getByRole('button', { name: 'Change Contact email' }));
    expect(screen.getByRole('dialog', { name: 'Contact email' })).toBeTruthy();
  });
});
