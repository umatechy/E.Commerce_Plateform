import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import AuthenticatedLayout from './AuthenticatedLayout';
import Products from '@/Pages/Catalog/Products';
import Brands from '@/Pages/Catalog/Brands';
import Dashboard from '@/Pages/Dashboard';
import Promotions from '@/Pages/Marketing/Promotions';
import { adminFetch } from '@/lib/adminApi';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

beforeEach(() => {
  setPage(owner, '/');
  window.history.replaceState(null, '', '/');
});

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

const product = (id: string, name: string, status = 'active') => ({
  id, internal_id: 1, type: 'simple', name, slug: name, sku: null, short_description: null, description: null, status, visibility: 'public',
  brand: null, brand_id: null, primary_category_id: null, price_minor: 2500, sale_price_minor: null, effective_price_minor: 2500, currency: 'USD', variants: [],
});

describe('admin navigation', () => {
  it('shows a store owner the store sections and no platform section', () => {
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    const nav = screen.getByRole('navigation', { name: 'Admin' });

    expect(within(nav).getByRole('link', { name: 'Orders' }).getAttribute('href')).toBe('/orders');
    expect(within(nav).getByRole('link', { name: 'Billing' })).toBeTruthy();
    expect(within(nav).queryByText('Platform administration')).toBeNull();
    expect(within(nav).getByRole('link', { name: 'Dashboard' }).getAttribute('aria-current')).toBe('page');
  });

  it('leaves out the pages a staff role does not open', () => {
    setPage({ ...owner, is_owner: false, permissions: ['orders.view'] }, '/orders');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    const nav = screen.getByRole('navigation', { name: 'Admin' });

    expect(within(nav).getByRole('link', { name: 'Orders' }).getAttribute('aria-current')).toBe('page');
    expect(within(nav).queryByText('Billing')).toBeNull();
    expect(within(nav).queryByText('Products')).toBeNull();
    expect(within(nav).getByRole('link', { name: 'Security' })).toBeTruthy();
  });

  it('keeps the platform pages in their own marked section, for platform staff only', () => {
    setPage({ ...owner, user: { ...owner.user!, is_platform_staff: true } }, '/super-admin/stores');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    const nav = screen.getByRole('navigation', { name: 'Admin' });

    expect(within(nav).getByRole('heading', { name: 'Platform administration' })).toBeTruthy();
    expect(within(nav).getByText('Umar Techy staff only. Acts across all stores.')).toBeTruthy();
    expect(within(nav).getByRole('link', { name: 'Stores' }).getAttribute('aria-current')).toBe('page');
  });

  it('closes the admin for an owner who has not turned on two-step sign-in, and says why', () => {
    setPage({ ...owner, mfa_enrollment_required: true }, '/security');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    const nav = screen.getByRole('navigation', { name: 'Admin' });

    // Only Security is a link; the rest is shown closed, not as links that would bounce.
    expect(within(nav).getAllByRole('link').map((link) => link.textContent)).toEqual(['Security']);
    const orders = within(nav).getByText('Orders').closest('[aria-disabled="true"]');
    expect(orders?.getAttribute('title')).toBe('Turn on two-step sign-in first');
    expect(screen.getByRole('status').textContent).toContain('One step before you can manage your store');
  });

  it('opens the pages again without a reload once two-step sign-in is on', () => {
    setPage({ ...owner, mfa_enrollment_required: true }, '/security');
    const { rerender } = render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    expect(screen.queryByRole('link', { name: 'Orders' })).toBeNull();

    // What router.reload({ only: ['auth'] }) brings back after enrolment.
    setPage({ ...owner, mfa_enrollment_required: false }, '/security');
    rerender(<AuthenticatedLayout>content</AuthenticatedLayout>);

    expect(screen.getByRole('link', { name: 'Orders' })).toBeTruthy();
    expect(screen.queryByText(/One step before you can manage your store/)).toBeNull();
  });

  it('switches store explicitly and reloads the whole admin', async () => {
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign });
    const fetchMock = routeFetch({ 'POST /store/switch': () => json(200, { data: { active_store_id: 9 } }) });
    vi.stubGlobal('fetch', fetchMock);
    setPage({ ...owner, stores: [{ id: 5, name: 'Ittar Waly' }, { id: 9, name: 'Second Shop' }] });
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);

    fireEvent.change(screen.getByLabelText('Store you are managing'), { target: { value: '9' } });

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/'));
    expect(JSON.parse(fetchMock.mock.calls[0][1]?.body as string)).toEqual({ store_id: 9 });
  });
});

describe('step-up dialog', () => {
  it('asks for the password and the code, then lets the action continue', async () => {
    let confirmed = false;
    const fetchMock = routeFetch({
      'PUT /super-admin/settings/backup.retention_days': () => (confirmed ? json(200, { data: { key: 'backup.retention_days' } }) : json(403, { code: 'step_up_required', message: 'Confirm your password to continue.' })),
      'POST /auth/step-up': (_url, init) => {
        const body = JSON.parse(init.body as string);
        if (body.password !== 'right-password') return json(422, { message: 'The password is incorrect.', errors: { password: ['The password is incorrect.'] } });
        confirmed = true;

        return json(200, { data: { valid_for_minutes: 15 } });
      },
    });
    vi.stubGlobal('fetch', fetchMock);
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);

    let result: unknown;
    act(() => {
      void adminFetch('/super-admin/settings/backup.retention_days', { method: 'PUT', body: { value: 45 } }).then((body) => (result = body));
    });

    const dialog = await screen.findByRole('dialog', { name: 'Confirm it is you' });
    expect(dialog.textContent).toContain('we ask for your password again');

    // A wrong password is reported on its field; the dialog stays.
    fireEvent.change(within(dialog).getByLabelText('Password'), { target: { value: 'wrong' } });
    fireEvent.change(within(dialog).getByLabelText('Two-step code'), { target: { value: '123456' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirm and continue' }));
    expect(await within(dialog).findByText('The password is incorrect.')).toBeTruthy();
    expect(result).toBeUndefined();

    fireEvent.change(within(dialog).getByLabelText('Password'), { target: { value: 'right-password' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirm and continue' }));

    await waitFor(() => expect(result).toEqual({ data: { key: 'backup.retention_days' } }));
    expect(screen.queryByRole('dialog')).toBeNull();
    // The password went to the step-up endpoint only, never with the action itself.
    const settingsCalls = fetchMock.mock.calls.filter(([url]) => String(url).includes('/settings/'));
    expect(settingsCalls).toHaveLength(2);
    expect(settingsCalls.every(([, init]) => !String(init?.body).includes('right-password'))).toBe(true);
  });

  it('cancelling changes nothing and the action is not repeated', async () => {
    const fetchMock = routeFetch({ 'POST /backups/01JBACKUP/restore-request': () => json(403, { code: 'step_up_required' }) });
    vi.stubGlobal('fetch', fetchMock);
    setPage({ ...owner, user: { ...owner.user!, mfa_enabled: false } });
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);

    let error: unknown;
    act(() => {
      adminFetch('/backups/01JBACKUP/restore-request', { method: 'POST' }).catch((e) => (error = e));
    });

    const dialog = await screen.findByRole('dialog', { name: 'Confirm it is you' });
    expect(within(dialog).queryByLabelText('Two-step code')).toBeNull(); // only for accounts that have one
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));

    await waitFor(() => expect((error as { code?: string })?.code).toBe('step_up_cancelled'));
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
});

describe('product list', () => {
  const usage = () => json(200, { data: { max_products: { limit: 500, current: 2, unlimited: false, remaining: 498 } } });
  const page = (rows: unknown[], current = 1, last = 1) => json(200, { data: rows, meta: { current_page: current, last_page: last, total: rows.length, per_page: 25 } });

  it('asks the server for the filtered list and keeps the filters in the address', async () => {
    const fetchMock = routeFetch({
      '/products': (url) => (url.searchParams.get('status') === 'draft' ? page([product('01JB', 'Red Mug', 'draft')]) : page([product('01JA', 'Blue Shirt'), product('01JB', 'Red Mug', 'draft')])),
      '/subscription/usage': usage,
    });
    vi.stubGlobal('fetch', fetchMock);
    setPage(owner, '/products');
    render(<Products />);

    expect(await screen.findByRole('link', { name: 'Blue Shirt' })).toBeTruthy();
    expect(screen.getByText('Products on your package: 2 of 500 used')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'draft' } });

    await waitFor(() => expect(screen.queryByRole('link', { name: 'Blue Shirt' })).toBeNull());
    expect(screen.getByRole('link', { name: 'Red Mug' })).toBeTruthy();
    expect(window.location.search).toBe('?status=draft');
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('status=draft'))).toBe(true);
  });

  it('pages through the server one page at a time', async () => {
    const fetchMock = routeFetch({
      '/products': (url) => (url.searchParams.get('page') === '2' ? page([product('01JC', 'Page Two Item')], 2, 2) : page([product('01JA', 'Page One Item')], 1, 2)),
      '/subscription/usage': usage,
    });
    vi.stubGlobal('fetch', fetchMock);
    setPage(owner, '/products');
    render(<Products />);

    await screen.findByRole('link', { name: 'Page One Item' });
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));

    expect(await screen.findByRole('link', { name: 'Page Two Item' })).toBeTruthy();
    expect(window.location.search).toBe('?page=2');
  });

  it('shows an empty state with the action a permitted user can take', async () => {
    vi.stubGlobal('fetch', routeFetch({ '/products': () => page([]), '/subscription/usage': usage }));
    setPage(owner, '/products');
    render(<Products />);

    expect(await screen.findByText('No products yet')).toBeTruthy();
    expect(screen.getAllByRole('link', { name: 'Add product' }).length).toBeGreaterThan(0);
  });

  it('offers no "Add product" to a role that may not create one', async () => {
    vi.stubGlobal('fetch', routeFetch({ '/products': () => page([]), '/subscription/usage': usage }));
    setPage({ ...owner, is_owner: false, permissions: ['products.view'] }, '/products');
    render(<Products />);

    await screen.findByText('No products yet');
    expect(screen.queryByRole('link', { name: 'Add product' })).toBeNull();
  });

  it('turns a failed load into an error with "Try again" that loads again', async () => {
    let attempts = 0;
    vi.stubGlobal(
      'fetch',
      routeFetch({ '/products': () => (++attempts === 1 ? json(500, { message: 'SQLSTATE secret detail' }) : page([product('01JA', 'Blue Shirt')])), '/subscription/usage': usage }),
    );
    setPage(owner, '/products');
    render(<Products />);

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Something went wrong on our side');
    expect(alert.textContent).not.toContain('SQLSTATE');

    fireEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('link', { name: 'Blue Shirt' })).toBeTruthy();
  });

  it('does not stay on "Loading" when the server never answers', async () => {
    vi.useFakeTimers();
    vi.stubGlobal(
      'fetch',
      vi.fn((_url: string, init: RequestInit) => new Promise((_resolve, reject) => init.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError'))))),
    );
    setPage(owner, '/products');
    render(<Products />);

    expect(screen.getByRole('status').textContent).toContain('Loading');
    await act(async () => {
      await vi.advanceTimersByTimeAsync(15000);
    });

    expect(screen.getByRole('alert').textContent).toContain('took too long');
    expect(screen.getByRole('button', { name: 'Try again' })).toBeTruthy();
  });

  it('deletes only after the confirmation, which names the product and says it cannot be undone', async () => {
    const fetchMock = routeFetch({ '/products': () => page([product('01JA', 'Blue Shirt')]), '/subscription/usage': usage, 'DELETE /products/01JA': () => json(204) });
    vi.stubGlobal('fetch', fetchMock);
    setPage(owner, '/products');
    render(<Products />);

    fireEvent.click(await screen.findByRole('button', { name: 'Delete Blue Shirt' }));
    const dialog = screen.getByRole('dialog', { name: 'Delete this product?' });
    expect(dialog.textContent).toContain('Blue Shirt');
    expect(dialog.textContent).toContain('cannot be undone');
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'DELETE')).toBe(false);

    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete product' }));
    await waitFor(() => expect(fetchMock.mock.calls.some(([url, init]) => init?.method === 'DELETE' && String(url).endsWith('/products/01JA'))).toBe(true));
  });
});

describe('a form saved through the API', () => {
  it('shows the server validation message on its field and does not submit twice', async () => {
    let posts = 0;
    let release: (response: Response) => void = () => undefined;
    const fetchMock = routeFetch({
      '/brands': () => json(200, { data: [] }),
      'POST /brands': () => {
        posts++;

        return new Promise<Response>((resolve) => (release = resolve));
      },
    });
    vi.stubGlobal('fetch', fetchMock);
    setPage(owner, '/brands');
    render(<Brands />);

    fireEvent.click((await screen.findAllByRole('button', { name: 'Add brand' }))[0]);
    const dialog = screen.getByRole('dialog', { name: 'Add brand' });
    const save = within(dialog).getByRole('button', { name: 'Save brand' });
    fireEvent.click(save);
    fireEvent.click(save); // a double click while the first save is in flight

    await waitFor(() => expect(posts).toBe(1));
    await act(async () => release(json(422, { message: 'The name field is required.', errors: { name: ['The name field is required.'] } })));

    const field = within(dialog).getByLabelText('Name');
    await waitFor(() => expect(field.getAttribute('aria-invalid')).toBe('true'));
    expect(within(dialog).getByText('The name field is required.')).toBeTruthy();
    expect(posts).toBe(1);
  });
});

describe('dashboard', () => {
  const routes = {
    '/dashboard': () =>
      json(200, {
        data: {
          current: { period: { start: '', end: '' }, revenue_minor: 125000, average_order_value_minor: null, collected_amount_minor: 90000, order_count: 12, pending_orders: 3, completed_orders: 7, new_customers: 4, low_stock_products: 2 },
          previous: { period: { start: '', end: '' }, order_count: 10 },
          revenue_change_percent: 25,
          order_count_change_percent: 20,
        },
      }),
    '/storefront/setup': () => json(200, { data: { launched: false, availability: 'pending_setup', checks: [{ key: 'products', required: true, done: false, message: 'At least one active, public product with a price.' }] } }),
    '/orders': () => json(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } }),
    '/store/health': () => json(200, { data: { status: 'ok', checked_at: '2026-10-01T10:00:00Z', checks: [{ key: 'setup', status: 'ok', message: 'Fine.' }] } }),
    '/subscription/usage': () => json(200, { data: {} }),
  };

  it('shows the server figures as they are, and "Not available" for one it did not send', async () => {
    vi.stubGlobal('fetch', routeFetch(routes));
    render(<Dashboard />);

    expect(await screen.findByText('$1,250.00')).toBeTruthy();
    expect(screen.getByText('+25% against the period before')).toBeTruthy();
    expect(screen.getByText('Not available')).toBeTruthy(); // average order value was null
    expect(screen.getByText('Your store is not open to customers yet')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Launch store' }) as HTMLButtonElement).disabled).toBe(true); // a required step is open
    expect(await screen.findByText(/All 1 checks passed/)).toBeTruthy();
  });

  it('asks only for what the role may see', async () => {
    const fetchMock = routeFetch(routes);
    vi.stubGlobal('fetch', fetchMock);
    setPage({ ...owner, is_owner: false, permissions: ['orders.view'] });
    render(<Dashboard />);

    await screen.findByText('Recent orders');
    const asked = fetchMock.mock.calls.map(([url]) => new URL(String(url), 'http://localhost').pathname.replace('/api/v1', ''));
    expect(asked).toContain('/orders');
    expect(asked).not.toContain('/dashboard');
    expect(asked).not.toContain('/storefront/setup');
    expect(asked).not.toContain('/store/health');
    expect(screen.queryByText('Sales')).toBeNull();
  });
});

describe('dates entered in the store timezone', () => {
  it('shows a promotion start as store wall-clock time and sends it back without an offset', async () => {
    const promotion = {
      id: '01JPROMO', internal_id: 3, name: 'Eid sale', type: 'percentage', target_scope: 'order', status: 'active', percentage_value: 10, fixed_amount_minor: null, currency: null,
      min_order_value_minor: null, max_discount_minor: null, requires_coupon: false, priority: 0, usage_limit: null, used_count: 0, customer_usage_limit: null,
      starts_at: '2026-09-30T20:30:00+00:00', ends_at: null, target_ids: [],
    };
    const fetchMock = routeFetch({
      '/promotions': () => json(200, { data: [promotion], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } }),
      'PUT /promotions/01JPROMO': () => json(200, { data: promotion }),
    });
    vi.stubGlobal('fetch', fetchMock);
    setPage(owner, '/promotions'); // the store is in Asia/Karachi (UTC+5)
    render(<AuthenticatedLayout><span /></AuthenticatedLayout>); // sets the display timezone from the shared props
    cleanup();
    render(<Promotions />);

    fireEvent.click(await screen.findByRole('button', { name: 'Edit Eid sale' }));
    const dialog = screen.getByRole('dialog', { name: 'Edit Eid sale' });
    fireEvent.click(within(dialog).getByRole('tab', { name: 'Limits and dates' }));

    // 20:30 UTC on 30 September is 01:30 on 1 October in the store.
    expect((within(dialog).getByLabelText(/Starts/) as HTMLInputElement).value).toBe('2026-10-01T01:30');
    expect(dialog.textContent).toContain("your store's timezone (Asia/Karachi)");

    fireEvent.click(within(dialog).getByRole('button', { name: 'Save promotion' }));
    await waitFor(() => expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PUT')).toBe(true));
    const sent = JSON.parse(fetchMock.mock.calls.find(([, init]) => init?.method === 'PUT')![1]?.body as string);
    expect(sent.starts_at).toBe('2026-10-01T01:30'); // store-local, no offset: the server reads it in the store timezone
    expect(sent).not.toHaveProperty('fixed_amount_minor'); // only the value of the chosen type is sent
    expect(sent.percentage_value).toBe(10);
  });
});
