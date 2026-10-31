import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Domains, { stepsOf, type DomainRow } from '@/Pages/Storefront/Domains';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase S2: a store's own domain, connected step by step; every status is the server's. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const row = (more: Partial<DomainRow> = {}): DomainRow => ({
  id: 'd1', hostname: 'roseattars.pk', domain_type: 'custom_domain', status: 'verification_required', is_primary: false, ssl_status: 'none', verified_at: null,
  dns_status: null, dns_checked_at: null, ssl_checked_at: null,
  records: [
    { type: 'TXT', name: '_umartechy-verify.roseattars.pk', value: 'umartechy-verify=abc', purpose: 'ownership' },
    { type: 'A', name: 'roseattars.pk', value: '203.0.113.10', purpose: 'address' },
    { type: 'A', name: 'www.roseattars.pk', value: '203.0.113.10', purpose: 'address' },
  ],
  ...more,
});

describe('the way to live', () => {
  it('follows the server’s fields, one step at a time', () => {
    expect(stepsOf(row())).toEqual({ ownership: 'now', address: 'waiting', certificate: 'waiting' });
    expect(stepsOf(row({ status: 'verified', dns_status: 'elsewhere' }))).toEqual({ ownership: 'done', address: 'now', certificate: 'waiting' });
    expect(stepsOf(row({ status: 'verified', dns_status: 'pointing', ssl_status: 'pending' }))).toEqual({ ownership: 'done', address: 'done', certificate: 'now' });
    expect(stepsOf(row({ status: 'active', dns_status: 'pointing', ssl_status: 'active' }))).toEqual({ ownership: 'done', address: 'done', certificate: 'done' });
  });
});

describe('the domains page', () => {
  it('shows the record to add now, and asks the server to check', async () => {
    setPage(owner, '/domains');
    let asked = 0;
    vi.stubGlobal('fetch', routeFetch({
      '/domains': () => json(200, { data: [row()], meta: { shop_ipv4: '203.0.113.10' } }),
      'POST /domains/d1/check': () => {
        asked += 1;
        return json(200, { data: row() });
      },
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Domains />);

    expect(await screen.findByText('_umartechy-verify.roseattars.pk')).toBeTruthy();
    expect(screen.getByText('Being connected')).toBeTruthy();
    expect(screen.queryByText('www.roseattars.pk')).toBeNull(); // the address comes after ownership
    fireEvent.click(screen.getByRole('button', { name: 'Check now' }));
    await waitFor(() => expect(asked).toBe(1));
  });

  it('gives the address records and says where the domain points now', async () => {
    setPage(owner, '/domains');
    vi.stubGlobal('fetch', routeFetch({
      '/domains': () => json(200, { data: [row({ status: 'verified', dns_status: 'elsewhere', records: row().records.slice(1) })], meta: { shop_ipv4: '203.0.113.10' } }),
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Domains />);

    expect(await screen.findByText('www.roseattars.pk')).toBeTruthy();
    expect(screen.getByText('The domain points to another address right now.')).toBeTruthy();
  });

  it('shows a live domain without steps to do, and says so plainly when the server has no address yet', async () => {
    setPage(owner, '/domains');
    vi.stubGlobal('fetch', routeFetch({
      '/domains': () => json(200, { data: [row({ id: 'd1', status: 'active', dns_status: 'pointing', ssl_status: 'active', is_primary: true, records: [] }), row({ id: 'd2', hostname: 'other.pk', status: 'verified', records: [] })], meta: { shop_ipv4: null } }),
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Domains />);

    expect(await screen.findByText('Live')).toBeTruthy();
    expect(screen.getByText('Your store’s address')).toBeTruthy();
    expect(screen.getByText(/shop’s address is not set up on the server yet/)).toBeTruthy();
    expect(screen.getAllByRole('button', { name: 'Check now' })).toHaveLength(1);
  });
});
