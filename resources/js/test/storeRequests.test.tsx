import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Welcome from '@/Pages/Welcome';
import StoreRequests, { type StoreRequestRow } from '@/Pages/SuperAdmin/StoreRequests';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase S1: the platform is closed to sign-up; businesses ask Umar Techy for a store. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const plans = [{ code: 'basic', name: 'Basic', price: null, limits: {}, features: [] }, { code: 'business', name: 'Business', price: null, limits: {}, features: [] }];

describe('the home page with sign-up closed', () => {
  it('asks for a store instead of signing up, takes the package from its card, and sends the request', async () => {
    setPage({ user: null } as never, '/');
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      'POST /public/store-requests': (_url, init) => {
        sent = JSON.parse(init.body as string);
        return json(202, { message: 'Thank you.' });
      },
    }));
    render(<Welcome plans={plans} signupOpen={false} contactWhatsapp="+92 300 1234567" businessCategories={[{ value: 'fashion', label: 'Fashion' }]} />);

    expect(screen.queryByRole('link', { name: 'Create your store' })).toBeNull();
    expect(screen.getAllByRole('link', { name: /Ask for your store/ }).length).toBeGreaterThan(0);
    expect(screen.getByText('Your store, built by our team')).toBeTruthy();
    expect(screen.getByRole('link', { name: /message us on WhatsApp/ }).getAttribute('href')).toBe('https://wa.me/923001234567');

    fireEvent.click(screen.getByRole('link', { name: /Ask for Business/ }));
    expect((screen.getByLabelText('Package (optional)') as HTMLSelectElement).value).toBe('business');
    fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Ayesha Khan' } });
    fireEvent.change(screen.getByLabelText('Business name'), { target: { value: 'Rose Attars' } });
    fireEvent.change(screen.getByLabelText('WhatsApp / phone'), { target: { value: '03001234567' } });
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'ayesha@example.com' } });
    fireEvent.change(screen.getByLabelText('Your domain (optional)'), { target: { value: 'roseattars.pk' } });
    fireEvent.click(screen.getByText('My own server'));
    fireEvent.click(screen.getByRole('button', { name: /Send my request/ }));

    await waitFor(() => expect(sent).toMatchObject({ contact_name: 'Ayesha Khan', business_name: 'Rose Attars', package_code: 'business', domain: 'roseattars.pk', hosting: 'own_server', city: null, website: null }));
    expect(await screen.findByText('Thank you — we received your request')).toBeTruthy();
  });

  it('keeps sign-up and shows no request form while sign-up is open', () => {
    setPage({ user: null } as never, '/');
    render(<Welcome plans={plans} signupOpen />);
    expect(screen.getAllByRole('link', { name: /Create your store/ }).length).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: /Send my request/ })).toBeNull();
  });
});

describe('Super Admin → Store requests', () => {
  const row = (more: Partial<StoreRequestRow> = {}): StoreRequestRow => ({
    id: '01JREQUEST0000000000000001', business_name: 'Rose Attars', contact_name: 'Ayesha Khan', email: 'ayesha@example.com', phone: '0300 1234567', city: 'Lahore',
    business_category: 'fashion', package_code: 'business', domain: 'roseattars.pk', hosting: 'own_server', message: 'About 200 products.', status: 'new',
    staff_note: null, handled_by: null, store: null, created_at: '2026-10-30T10:00:00Z', ...more,
  });

  it('shows who asked and how to reach them, and follows a request up', async () => {
    setPage({ ...owner, user: { ...owner.user!, is_platform_staff: true } }, '/super-admin/store-requests');
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/store-requests': () => json(200, { data: [row(), row({ id: '01JREQUEST0000000000000002', business_name: 'Done Shop', status: 'store_created', store: { id: 9, name: 'Done Shop', slug: 'done-shop' } })], meta: { new: 1 } }),
      '/super-admin/business-categories': () => json(200, { data: [{ value: 'fashion', label: 'Fashion' }] }),
      'PUT /super-admin/store-requests/01JREQUEST0000000000000001': (_url, init) => {
        sent = JSON.parse(init.body as string);
        return json(200, { message: 'Saved.' });
      },
      '/attention': () => json(200, { data: [] }),
    }));
    render(<StoreRequests />);

    expect(await screen.findByText('Rose Attars')).toBeTruthy();
    expect(screen.getByText('1 new request waiting')).toBeTruthy();
    expect(screen.getAllByRole('link', { name: '0300 1234567' })[0].getAttribute('href')).toBe('https://wa.me/923001234567');
    expect(screen.getAllByText('Their own server').length).toBe(2);
    expect(screen.getByRole('link', { name: 'Open Done Shop' }).getAttribute('href')).toBe('/super-admin/stores/9');

    fireEvent.click(screen.getByRole('button', { name: 'Follow up' }));
    fireEvent.change(await screen.findByLabelText(/Notes for the team/), { target: { value: 'Agreed Business.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(sent).toMatchObject({ status: 'contacted', staff_note: 'Agreed Business.' }));
  });
});
