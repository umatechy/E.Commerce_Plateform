import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Login from '@/Pages/Auth/Login';
import Welcome from '@/Pages/Welcome';
import { passwordStrength } from '@/Components/Auth/PasswordField';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-1: the sign-in pages and the public home page. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('sign in', () => {
  it('shows and hides the password, then asks for the code and sends a pasted code at once', async () => {
    setPage({ ...owner, user: null }, '/login');
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign, search: '' });
    let sentCode: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      'POST /auth/login': () => json(200, { data: { mfa_required: true } }),
      'POST /auth/login/mfa': (_u, init) => {
        sentCode = JSON.parse(String(init.body)).code;
        return json(200, { data: {} });
      },
    }));
    render(<Login />);

    const password = screen.getByLabelText('Password') as HTMLInputElement;
    expect(password.type).toBe('password');
    fireEvent.click(screen.getByRole('button', { name: 'Show password' }));
    expect(password.type).toBe('text');

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'owner@example.com' } });
    fireEvent.change(password, { target: { value: 'secret-pass-123' } });
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    const code = await screen.findByLabelText('Code');
    fireEvent.paste(code, { clipboardData: { getData: () => '123 456' } });
    await waitFor(() => expect(sentCode).toBe('123456'));
    await waitFor(() => expect(assign).toHaveBeenCalledWith('/'));
  });

  it('offers a recovery code instead of the app code', async () => {
    setPage({ ...owner, user: null }, '/login');
    vi.stubGlobal('location', { ...window.location, assign: vi.fn(), search: '' });
    vi.stubGlobal('fetch', routeFetch({ 'POST /auth/login': () => json(200, { data: { mfa_required: true } }) }));
    render(<Login />);
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'owner@example.com' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret-pass-123' } });
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    fireEvent.click(await screen.findByRole('button', { name: /Use a recovery code/ }));
    expect(screen.getByLabelText('Recovery code')).toBeTruthy();
  });
});

describe('password strength hint', () => {
  it('grows with length and variety', () => {
    expect(passwordStrength('').score).toBe(0);
    expect(passwordStrength('short1!').label).toBe('Too weak');
    expect(passwordStrength('longer-password').score).toBeGreaterThanOrEqual(1);
    expect(passwordStrength('Longer-Password-2026!').label).toBe('Strong');
  });
});

describe('home page', () => {
  it('shows the packages with their prices and only real features', () => {
    setPage({ ...owner, user: null }, '/');
    render(
      <Welcome
        trialDays={14}
        plans={[
          { code: 'basic', name: 'Basic', price: { amount_minor: 250000, currency: 'PKR', interval: 'monthly' }, limits: { max_products: 500 }, features: ['payment.cod'] },
          { code: 'business', name: 'Business', price: null, limits: {}, features: ['affiliate.programs'] },
        ]}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Basic' })).toBeTruthy();
    expect(screen.getByText('Rs. 2,500')).toBeTruthy();
    expect(screen.getByText('Products: 500')).toBeTruthy();
    expect(screen.getByText('Affiliate program')).toBeTruthy();
    expect(screen.getByText('Price on request')).toBeTruthy();
    expect(screen.getByText('Free 14-day trial. No card needed.')).toBeTruthy();
  });

  it('points to sign in only when sign-up is closed', () => {
    setPage({ ...owner, user: null }, '/');
    render(<Welcome plans={[]} signupOpen={false} />);
    expect(screen.queryByRole('link', { name: /Create your store/ })).toBeNull();
    expect(screen.getByText('Packages are being prepared. Contact us for a price.')).toBeTruthy();
  });
});
