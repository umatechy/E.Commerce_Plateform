import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import Show from '@/Pages/Customers/Show';
import Groups from '@/Pages/Customers/Groups';
import Create from '@/Pages/Orders/Create';
import Packages from '@/Pages/SuperAdmin/Packages';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';
import type { CustomerDetail } from '@/lib/customers';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

const ID = '01JCUSTOMER000000000000001';

beforeEach(() => {
  setPage(owner, `/customers/${ID}`);
  window.history.replaceState(null, '', '/');
});

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const detail = (overrides: Partial<CustomerDetail> = {}): CustomerDetail => ({
  id: ID,
  name: 'Ayesha Khan',
  email: 'ayesha@example.com',
  phone: '+923001234567',
  status: 'active',
  status_reason: null,
  status_changed_at: null,
  source: 'registered',
  registered: true,
  email_verified: false,
  marketing_email_opt_in: true,
  erased: false,
  group: null,
  tags: [{ id: '01JTAG0000000000000000001', name: 'VIP' }],
  orders_count: 2,
  total_spent_minor: 8000,
  last_order_at: '2026-09-01T10:00:00Z',
  created_at: '2026-01-01T10:00:00Z',
  first_order_at: '2026-02-01T10:00:00Z',
  average_order_value_minor: 4000,
  addresses: [],
  possible_duplicates: [],
  ...overrides,
});

const empty = () => json(200, { data: [] });
const noOrders = () => json(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } });

function customerRoutes(customer: CustomerDetail, extra: Record<string, Parameters<typeof routeFetch>[0][string]> = {}) {
  return routeFetch({
    ...extra,
    [`/customers/${ID}`]: () => json(200, { data: customer }),
    [`/customers/${ID}/notes`]: empty,
    [`/customers/${ID}/activity`]: empty,
    '/orders': noOrders,
    '/customer-groups': empty,
  });
}

describe('customer detail', () => {
  it('shows the profile, figures and the actions a manager may take', async () => {
    vi.stubGlobal('fetch', customerRoutes(detail()));
    render(<Show customerId={ID} />);

    expect(await screen.findByRole('heading', { name: 'Ayesha Khan' })).toBeTruthy();
    expect(screen.getByText('Not confirmed')).toBeTruthy();
    expect(screen.getByText('VIP')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Block' })).toBeTruthy();
    // A registered customer's email is theirs to change.
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit customer' });
    expect((within(dialog).getByLabelText('Email') as HTMLInputElement).disabled).toBe(true);
  });

  it('offers no changes to someone who may only view customers', async () => {
    setPage({ ...owner, is_owner: false, permissions: ['customers.view'] }, `/customers/${ID}`);
    vi.stubGlobal('fetch', customerRoutes(detail()));
    render(<Show customerId={ID} />);

    await screen.findByRole('heading', { name: 'Ayesha Khan' });
    expect(screen.queryByRole('button', { name: 'Block' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Edit' })).toBeNull();
    expect(screen.queryByText('Add a note')).toBeNull();
    expect(screen.queryByText('Privacy requests')).toBeNull();
  });

  it('asks for the reason before blocking and sends it', async () => {
    let sent: unknown = null;
    vi.stubGlobal(
      'fetch',
      customerRoutes(detail(), {
        [`POST /customers/${ID}/block`]: (_url, init) => {
          sent = JSON.parse(init.body as string);

          return json(200, { data: detail({ status: 'blocked', status_reason: 'Chargebacks' }) });
        },
      }),
    );
    render(<Show customerId={ID} />);

    fireEvent.click(await screen.findByRole('button', { name: 'Block' }));
    const dialog = await screen.findByRole('dialog', { name: 'Block Ayesha Khan?' });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Block customer' }));
    expect(await within(dialog).findByText('Give the reason. It is kept with the change.')).toBeTruthy();
    expect(sent).toBeNull();

    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Chargebacks' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Block customer' }));
    await waitFor(() => expect(sent).toEqual({ reason: 'Chargebacks' }));
  });

  it('warns of possible duplicates and of a block', async () => {
    vi.stubGlobal(
      'fetch',
      customerRoutes(detail({ status: 'blocked', status_reason: 'Abuse', possible_duplicates: [{ id: '01JCUSTOMER000000000000002', name: 'A. Khan', email: 'ayesha@example.com', reason: 'same_email' }] })),
    );
    render(<Show customerId={ID} />);

    expect(await screen.findByText('This may be the same person as:')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'A. Khan' }).getAttribute('href')).toBe('/customers/01JCUSTOMER000000000000002');
    expect(screen.getByText(/They cannot sign in or place orders/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Unblock' })).toBeTruthy();
  });

  it('erases only after the email is typed', async () => {
    vi.stubGlobal('fetch', customerRoutes(detail()));
    render(<Show customerId={ID} />);

    fireEvent.click(await screen.findByRole('button', { name: 'Erase personal data…' }));
    const dialog = await screen.findByRole('dialog', { name: "Erase this person's personal data?" });
    const erase = within(dialog).getByRole('button', { name: 'Erase for good' }) as HTMLButtonElement;
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Request' } });
    expect(erase.disabled).toBe(true);
    fireEvent.change(within(dialog).getByLabelText(/Type their email/), { target: { value: 'AYESHA@example.com' } });
    expect(erase.disabled).toBe(false);
  });
});

describe('package (owner decision 2026-10-03)', () => {
  const business = { ...owner, features: { 'customers.advanced': true } };

  it('on Basic: tags are read-only and merging is not offered', async () => {
    vi.stubGlobal('fetch', customerRoutes(detail({ registered: false, possible_duplicates: [{ id: '01JCUSTOMER000000000000002', name: 'A. Khan', email: 'a.khan@example.com', reason: 'same_email' }] })));
    render(<Show customerId={ID} />);

    await screen.findByRole('heading', { name: 'Ayesha Khan' });
    expect(screen.getByText('VIP')).toBeTruthy();
    expect(screen.queryByLabelText('Add tags')).toBeNull();
    expect(screen.getByText('Changing groups and tags comes with the Business and Premium packages.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Merge into…' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Merge this record/ })).toBeNull();
  });

  it('on Business: merges into the chosen duplicate after the email is typed, then opens it', async () => {
    setPage(business, `/customers/${ID}`);
    let sent: unknown = null;
    vi.stubGlobal(
      'fetch',
      customerRoutes(detail({ registered: false, possible_duplicates: [{ id: '01JCUSTOMER000000000000002', name: 'A. Khan', email: 'a.khan@example.com', reason: 'same_email' }] }), {
        [`POST /customers/${ID}/merge`]: (_url, init) => {
          sent = JSON.parse(init.body as string);

          return json(200, { data: { into: '01JCUSTOMER000000000000002', moved: { orders: 2 } } });
        },
      }),
    );
    const { routerMock } = await import('@/test/inertiaMock');
    render(<Show customerId={ID} />);

    expect(await screen.findByLabelText('Add tags')).toBeTruthy();
    fireEvent.click(await screen.findByRole('button', { name: 'Merge this record into A. Khan' }));
    const dialog = await screen.findByRole('dialog', { name: 'Merge Ayesha Khan into another customer' });
    const submit = within(dialog).getByRole('button', { name: 'Merge for good' }) as HTMLButtonElement;
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Added twice' } });
    expect(submit.disabled).toBe(true);
    fireEvent.change(within(dialog).getByLabelText(/Type the email of the customer who stays/), { target: { value: 'A.KHAN@example.com' } });
    expect(submit.disabled).toBe(false);
    fireEvent.click(submit);

    await waitFor(() => expect(sent).toEqual({ into: '01JCUSTOMER000000000000002', reason: 'Added twice', confirm_email: 'A.KHAN@example.com' }));
    await waitFor(() => expect(routerMock.visit).toHaveBeenCalledWith('/customers/01JCUSTOMER000000000000002'));
  });

  it('a merged record says where it went and offers no changes', async () => {
    setPage(business, `/customers/${ID}`);
    vi.stubGlobal('fetch', customerRoutes(detail({ status: 'archived', merged: true, merged_into: { id: '01JCUSTOMER000000000000002', name: 'A. Khan', at: '2026-10-03T10:00:00Z' } })));
    render(<Show customerId={ID} />);

    expect((await screen.findByRole('link', { name: 'A. Khan' })).getAttribute('href')).toBe('/customers/01JCUSTOMER000000000000002');
    expect(screen.queryByRole('button', { name: 'Restore' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Edit' })).toBeNull();
  });
});

describe('groups and tags', () => {
  it('links each group and tag to its customers by id', async () => {
    setPage(owner, '/customers/groups');
    vi.stubGlobal(
      'fetch',
      routeFetch({
        '/customer-groups': () => json(200, { data: [{ id: '01JGROUP00000000000000001', name: 'Retail', description: null, customers_count: 3 }] }),
        '/customer-tags': () => json(200, { data: [{ id: '01JTAG0000000000000000001', name: 'VIP', customers_count: 1 }] }),
      }),
    );
    render(<Groups />);

    expect((await screen.findByRole('link', { name: '3 customers' })).getAttribute('href')).toBe('/customers?group=01JGROUP00000000000000001');
    expect(screen.getByRole('link', { name: '1 customer' }).getAttribute('href')).toBe('/customers?tag=01JTAG0000000000000000001');
  });
});

describe('admin order', () => {
  it('is for a chosen customer and carries the payment method', async () => {
    setPage({ ...owner, features: { 'payment.cod': true } }, '/orders/new');
    window.history.replaceState(null, '', `/orders/new?customer=${ID}`);
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal(
      'fetch',
      routeFetch({
        [`/customers/${ID}`]: () => json(200, { data: detail() }),
        'POST /orders': (_url, init) => {
          sent = JSON.parse(init.body as string);

          return json(422, { message: 'Stop here.', errors: {} });
        },
        '/products': () => json(200, { data: [] }),
      }),
    );
    render(<Create />);

    expect(await screen.findByText('ayesha@example.com · +923001234567')).toBeTruthy();
    const method = screen.getByLabelText('How the customer pays') as HTMLSelectElement;
    // Bank transfer is not in this package.
    expect(Array.from(method.options).map((o) => o.value)).toEqual(['', 'cod']);
    fireEvent.change(method, { target: { value: 'cod' } });

    // Without products nothing is sent.
    fireEvent.click(screen.getByRole('button', { name: 'Create order' }));
    expect(await screen.findByText('Add at least one product.')).toBeTruthy();
    expect(sent).toBeNull();
  });
});

describe('package contents', () => {
  it('sends only what changed, with the reason', async () => {
    setPage({ ...owner, is_owner: false }, '/super-admin/packages');
    let sent: { entitlements: unknown[]; reason: string } | null = null;
    vi.stubGlobal(
      'fetch',
      routeFetch({
        '/super-admin/packages': () =>
          json(200, {
            data: [
              { code: 'basic', name: 'Basic', is_active: true, entitlements: [
                { key: 'payment.cod', type: 'feature', enabled: false, limit: null, unlimited: false, period: null },
                { key: 'max_products', type: 'usage_limit', enabled: null, limit: 50, unlimited: false, period: null },
              ] },
              { code: 'premium', name: 'Premium', is_active: true, entitlements: [{ key: 'payment.bank_transfer', type: 'feature', enabled: true, limit: null, unlimited: false, period: null }] },
            ],
          }),
        'PUT /super-admin/packages/basic/entitlements': (_url, init) => {
          sent = JSON.parse(init.body as string);

          return json(200, { data: {} });
        },
      }),
    );
    render(<Packages />);

    fireEvent.click(await screen.findByRole('button', { name: 'Contents of Basic' }));
    const dialog = await screen.findByRole('dialog', { name: 'What Basic includes' });
    // A feature only another package has is offered too.
    expect(within(dialog).getByText('Not set on this package yet.')).toBeTruthy();
    const save = within(dialog).getByRole('button', { name: 'Save changes' }) as HTMLButtonElement;
    expect(save.disabled).toBe(true);

    fireEvent.change(within(dialog).getByLabelText('Limit'), { target: { value: '200' } });
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Launch offer' } });
    fireEvent.click(save);

    await waitFor(() => expect(sent).toEqual({ entitlements: [{ key: 'max_products', unlimited: false, limit: 200 }], reason: 'Launch offer' }));
  });
});
