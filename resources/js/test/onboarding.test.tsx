import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Register from '@/Pages/Auth/Register';
import Stores from '@/Pages/SuperAdmin/Stores';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, routerMock, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B44 (owner decision 13): sign-up and the Super Admin "create store for a customer". */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

describe('Register', () => {
  beforeEach(() => setPage(owner, '/register'));

  it('asks what the store will sell', () => {
    render(<Register signupOpen businessCategories={[{ value: 'fashion', label: 'Clothing & fashion' }]} />);
    expect(screen.getByLabelText('What will you sell?')).toBeTruthy();
    expect(screen.getByRole('option', { name: 'Clothing & fashion' })).toBeTruthy();
  });

  it('says whom to contact when sign-up is closed', () => {
    render(<Register signupOpen={false} />);
    expect(screen.getByText(/set up by the Umar Techy team/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Create store' })).toBeNull();
  });
});

describe('Super Admin stores', () => {
  beforeEach(() => setPage({ ...owner, is_owner: false }, '/super-admin/stores'));

  it('shows each store’s stage and owner, and creates a store for a customer once', async () => {
    let sent: { body: unknown; key: string | null } | null = null;
    let calls = 0;
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/stores': () => json(200, { data: [
        { id: 7, name: 'Karachi Mobiles', slug: 'karachi-mobiles-abc123', status: 'pending_setup', created_at: '2026-10-05T10:00:00Z', stage: 'awaiting_owner', business_category: 'electronics', created_via: 'platform', package_code: 'premium', owner_email: 'bilal@example.com', has_owner: false },
      ], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } }),
      '/super-admin/business-categories': () => json(200, { data: [{ value: 'electronics', label: 'Mobiles & electronics' }, { value: 'food', label: 'Food, bakery & sweets' }] }),
      '/super-admin/packages': () => json(200, { data: [{ code: 'basic', name: 'Basic', is_active: true }, { code: 'premium', name: 'Premium', is_active: true }] }),
      'POST /super-admin/stores': (_url, init) => {
        calls++;
        sent = { body: JSON.parse(String(init.body)), key: new Headers(init.headers).get('Idempotency-Key') };

        return json(201, { data: { id: 8 } });
      },
    }));
    render(<Stores />);

    expect(await screen.findByText('Waiting for the owner')).toBeTruthy();
    expect(screen.getAllByText('Mobiles & electronics').length).toBeGreaterThan(0);
    expect(screen.getByText('(invited)')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Create store for a customer' }));
    fireEvent.change(screen.getByLabelText('Store name'), { target: { value: 'Lahore Sweets' } });
    fireEvent.change(screen.getByLabelText('What it sells'), { target: { value: 'food' } });
    await screen.findByRole('option', { name: 'Basic' });
    fireEvent.change(screen.getByLabelText('Package'), { target: { value: 'basic' } });
    fireEvent.change(screen.getByLabelText(/Trial/), { target: { value: '0' } });
    fireEvent.change(screen.getByLabelText(/Owner’s email/), { target: { value: 'ali@example.com' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create store' }));

    await waitFor(() => expect(routerMock.visit).toHaveBeenCalledWith('/super-admin/stores/8'));
    expect(calls).toBe(1);
    expect(sent!.body).toEqual({ store_name: 'Lahore Sweets', business_category: 'food', package_code: 'basic', owner_email: 'ali@example.com', trial_days: 0 });
    expect(sent!.key).toMatch(/.{8,}/);
  });
});
