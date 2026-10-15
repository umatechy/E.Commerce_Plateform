import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Apps from '@/Pages/Apps/Index';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B56 (Module 27 §10–15): installing an app needs the owner to read and accept what it may see. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const card = { slug: 'ledger-sync', name: 'Ledger Sync', tagline: 'Orders to your accounts.', category: 'accounting', category_label: 'Accounting', publisher: 'Example Apps', trust: 'reviewed', trust_label: 'Reviewed by Umar Techy', pricing: 'free', status: 'published', high_risk: true, installed: null };
const detail = {
  ...card,
  description: 'Copies orders into your book.',
  publisher: { name: 'Example Apps', website: 'https://apps.example.com', support_email: 'help@example.com' },
  privacy_url: 'https://apps.example.com/privacy',
  terms_url: null,
  version: '1.0.0',
  release_notes: null,
  permissions: [
    { scope: 'products:read', label: 'See your products, prices and pictures', risk: 'low' },
    { scope: 'orders:read', label: 'See your orders, including customers’ names, addresses and phone numbers', risk: 'high' },
  ],
  events: ['order.created'],
  data_use: 'Reads orders to make invoices.',
  problems: [],
  can_manage: true,
  installation: null,
};

describe('Apps', () => {
  it('shows what an app may see and installs only after the owner agrees, then goes to the app to connect', async () => {
    setPage(owner, '/apps');
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign, search: '' });
    const fetchMock = routeFetch({
      '/apps': () => json(200, { data: [card], meta: { categories: { accounting: 'Accounting' }, can_manage: true } }),
      '/apps/ledger-sync': () => json(200, { data: detail }),
      'POST /apps/ledger-sync/install': () => json(201, { data: { installation: '01J', status: 'awaiting_connection', redirect_url: 'https://app.example.com/connect?code=abc' } }),
    });
    vi.stubGlobal('fetch', fetchMock);
    render(<Apps />);

    fireEvent.click(await screen.findByRole('button', { name: /Ledger Sync/ }));
    expect(await screen.findByText(/including customers’ names, addresses and phone numbers/)).toBeTruthy();
    expect(screen.getAllByText('Personal data').length).toBeGreaterThan(0);
    expect(screen.getByText(/It can only read/)).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Install' }));
    const confirm = await screen.findByRole('button', { name: 'Install and connect' });
    expect((confirm as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(screen.getByLabelText(/I allow Ledger Sync to see this for my store/));
    fireEvent.click(confirm);

    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://app.example.com/connect?code=abc'));
    const install = fetchMock.mock.calls.find(([url, init]) => String(url).includes('/install') && (init as RequestInit).method === 'POST');
    expect(JSON.parse(String((install?.[1] as RequestInit).body))).toEqual({ accept_permissions: true });
  });
});
