import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Security from '@/Pages/Security/Index';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-6: turning on two-step sign-in is a guided flow that ends on the dashboard. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const off = { enabled: false, confirmed_at: null, recovery_codes_remaining: 0, required: true };
const on = { enabled: true, confirmed_at: '2026-10-17T05:18:00Z', recovery_codes_remaining: 10, required: true };

describe('security page', () => {
  it('walks an owner through turning it on and then opens the dashboard', async () => {
    setPage({ ...owner, mfa_enrollment_required: true }, '/security');
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign });
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/auth/mfa': () => json(200, { data: off }),
      '/auth/sessions': () => json(200, { data: [] }),
      'POST /auth/mfa/setup': () => json(200, { data: { secret: 'ABCDEF123456', otpauth_uri: 'otpauth://totp/Umar%20Techy:o@example.com?secret=ABCDEF123456' } }),
      'POST /auth/mfa/confirm': (_u, init) => {
        sent = JSON.parse(String(init.body)).code;
        return json(200, { data: { ...on, recovery_codes: ['aaaa-bbbb', 'cccc-dddd'] } });
      },
    }));
    render(<Security />);

    expect(await screen.findByText('One step before you manage your store')).toBeTruthy();
    fireEvent.change(screen.getByLabelText(/Your password/), { target: { value: 'secret-pass-123' } });
    fireEvent.click(screen.getByRole('button', { name: /Continue/ }));

    expect(await screen.findByText('ABCDEF123456')).toBeTruthy();
    fireEvent.paste(screen.getByLabelText(/Enter the 6-digit code/), { clipboardData: { getData: () => '123456' } });
    await waitFor(() => expect(sent).toBe('123456'));

    expect(await screen.findByText('aaaa-bbbb')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: /I have saved them — go to my dashboard/ }));
    expect(assign).toHaveBeenCalledWith('/');
  });

  it('keeps the management form closed until asked, and says it is not a sign-in', async () => {
    setPage(owner, '/security');
    vi.stubGlobal('fetch', routeFetch({ '/auth/mfa': () => json(200, { data: on }), '/auth/sessions': () => json(200, { data: [] }) }));
    render(<Security />);

    expect(await screen.findByText(/Nothing to do here/)).toBeTruthy();
    expect(screen.queryByLabelText('Current 6-digit code')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Manage/ }));
    expect(screen.getByLabelText('Current 6-digit code')).toBeTruthy();
    expect(screen.getByText('This is not a sign-in')).toBeTruthy();
  });
});
