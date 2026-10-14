import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import CloseStore from '@/Pages/Settings/CloseStore';
import StoreLifecycleCard, { type Lifecycle } from '@/Components/SuperAdmin/StoreLifecycleCard';
import { clearToasts } from '@/Components/ui/toast';
import { accessFrom } from '@/lib/access';
import { navigationFor } from '@/lib/adminNav';
import { daysUntil, type ClosureInfo } from '@/lib/storeLifecycle';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B51 (gap G14; Module 03 §33–37): closing, reopening and Umar Techy's side of a store's life. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
  window.history.replaceState(null, '', '/');
});

const inDays = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString();

const open: ClosureInfo = {
  status: 'active', stage_label: 'active', retention_ends_at: null, retention_days: 90, can_close: true, can_reopen: false, is_owner: true, store_name: 'Ittar Waly',
  open_items: [{ key: 'orders', count: 2, label: '2 orders are not finished yet', hint: 'Customers who paid or are waiting for a parcel should hear from you.' }],
  reasons: ['not_selling', 'too_expensive', 'other', 'platform_request'], history: [], datasets: ['orders', 'order-lines', 'customers', 'products'],
};

describe('Settings → Close store', () => {
  beforeEach(() => setPage(owner, '/settings/close-store'));

  it('explains what closing means, shows what is open, and closes only once the store name is typed', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/store-lifecycle': () => json(200, { data: open }),
      'POST /store-lifecycle/close': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: { ...open, status: 'cancelled', can_close: false } });
      },
    }));
    render(<CloseStore />);

    expect(await screen.findByText('What closing means')).toBeTruthy();
    expect(screen.getByText('You can change your mind for 90 days.')).toBeTruthy();
    expect(screen.getByText('2 orders are not finished yet')).toBeTruthy();
    expect(screen.getAllByRole('button', { name: /^Download/ })).toHaveLength(4);
    expect(screen.queryByRole('option', { name: 'Closed by Umar Techy' })).toBeNull();

    const close = screen.getByRole('button', { name: 'Close store' }) as HTMLButtonElement;
    expect(close.disabled).toBe(true);
    fireEvent.change(screen.getByLabelText(/^Why are you closing/), { target: { value: 'not_selling' } });
    fireEvent.change(screen.getByLabelText(/^Type the store name/), { target: { value: 'ittar waly ' } });
    expect(close.disabled).toBe(false);
    fireEvent.click(close);
    await waitFor(() => expect(sent).toEqual({ store_name: 'ittar waly ', reason: 'not_selling', note: null }));
  });

  it('shows a closed store with the days left, the banner, and reopens after the name is typed', async () => {
    setPage({ ...owner, activeStore: { ...owner.activeStore!, status: 'cancelled', retention_ends_at: inDays(40) } }, '/settings/close-store');
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/store-lifecycle': () => json(200, { data: { ...open, status: 'cancelled', can_close: false, can_reopen: true, closed_at: inDays(-50), retention_ends_at: inDays(40), open_items: [] } }),
      'POST /store-lifecycle/reopen': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: open });
      },
    }));
    render(<CloseStore />);

    expect(await screen.findByText('Ittar Waly is closed')).toBeTruthy();
    expect(screen.getByText(/40 days left to download it or reopen/)).toBeTruthy();
    expect(screen.getByText(/This store is closed and read-only/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Close store' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Reopen store' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText(/^Type the store name/), { target: { value: 'Ittar Waly' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reopen store' }));
    await waitFor(() => expect(sent).toEqual({ store_name: 'Ittar Waly' }));
  });

  it('tells other team members that only the owner decides', async () => {
    vi.stubGlobal('fetch', routeFetch({ '/store-lifecycle': () => json(200, { data: { ...open, is_owner: false, datasets: [] } }) }));
    render(<CloseStore />);

    expect(await screen.findByText('Only the owner can close this store')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /^Download/ })).toBeNull();
  });

  it('shows the Close store menu entry to the owner only', () => {
    const labels = (isOwner: boolean) => navigationFor(accessFrom({ ...owner, is_owner: isOwner, permissions: ['settings.view', 'settings.manage', 'billing.view'] })).flatMap((g) => g.items.map((i) => i.label));
    expect(labels(true)).toContain('Close store');
    expect(labels(false)).not.toContain('Close store');
    expect(daysUntil(inDays(-3))).toBe(0);
  });
});

const lifecycle = (extra: Partial<Lifecycle> = {}): Lifecycle => ({
  ...open, is_owner: false, can_close: false, store: { id: 'S1', name: 'Ittar Waly', slug: 'ittar-waly' }, suspension_note: null, archived_at: null, legal_hold: false, legal_hold_reason: null,
  allowed: ['close', 'suspend'], suspension_reasons: ['abuse', 'policy', 'other'], deletion_blockers: [], deletion: null, history: [], ...extra,
});

describe('Super Admin → store life', () => {
  beforeEach(() => setPage({ ...owner, user: { ...owner.user!, is_platform_staff: true }, platform_capabilities: ['view', 'stores', 'store_deletion'] }, '/super-admin/stores/5'));

  it('suspends only with a reason and a note', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/stores/5/lifecycle': () => json(200, { data: lifecycle() }),
      'POST /super-admin/stores/5/lifecycle/suspend': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: lifecycle({ status: 'suspended', allowed: ['close', 'reactivate', 'archive'] }) });
      },
    }));
    render(<StoreLifecycleCard storeId="5" />);

    fireEvent.click(await screen.findByRole('button', { name: 'Suspend store' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'abuse' } });
    fireEvent.change(within(dialog).getByLabelText(/^What happened/), { target: { value: 'Counterfeit goods, ticket 4411' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Suspend store' }));
    await waitFor(() => expect(sent).toEqual({ note: 'Counterfeit goods, ticket 4411', reason: 'abuse' }));
  });

  it('plans a deletion with what is removed and kept, and asks for the address and a reason', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/stores/5/lifecycle': () => json(200, { data: lifecycle({ status: 'archived', allowed: ['restore'], retention_ends_at: inDays(-2) }) }),
      '/super-admin/stores/5/lifecycle/deletion-plan': () => json(200, { data: { purge: { orders: 20, products: 15 }, retain: { invoices: 3 }, purge_total: 35, retained_tables: ['audit_logs', 'invoices'], blockers: [] } }),
      'POST /super-admin/stores/5/lifecycle/deletion': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: lifecycle({ status: 'archived', allowed: ['restore'] }) });
      },
    }));
    render(<StoreLifecycleCard storeId="5" />);

    fireEvent.click(await screen.findByRole('button', { name: 'Plan deletion…' }));
    const dialog = await screen.findByRole('dialog');
    expect(await within(dialog).findByText('Removed: 35 rows')).toBeTruthy();
    expect(within(dialog).getByText('invoices (3)')).toBeTruthy();
    const schedule = within(dialog).getByRole('button', { name: 'Schedule deletion' }) as HTMLButtonElement;
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Owner asked by email on 12 Oct' } });
    expect(schedule.disabled).toBe(true);
    fireEvent.change(within(dialog).getByLabelText(/^Type the store address/), { target: { value: 'ittar-waly' } });
    fireEvent.click(schedule);
    await waitFor(() => expect(sent).toEqual({ reason: 'Owner asked by email on 12 Oct', confirm_slug: 'ittar-waly' }));
  });

  it('shows why a store cannot be deleted yet', async () => {
    vi.stubGlobal('fetch', routeFetch({ '/super-admin/stores/5/lifecycle': () => json(200, { data: lifecycle({ status: 'archived', allowed: ['restore'], legal_hold: true, legal_hold_reason: 'Tax inquiry', deletion_blockers: ['The store is on legal hold: Tax inquiry.'] }) }) }));
    render(<StoreLifecycleCard storeId="5" />);

    expect(await screen.findByText('The store is on legal hold: Tax inquiry.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Plan deletion…' }) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByRole('button', { name: 'Lift legal hold' })).toBeTruthy();
  });
});
