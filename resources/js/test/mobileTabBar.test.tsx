import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { owner, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-19: the phone tab bar. */
afterEach(cleanup);

describe('phone tab bar', () => {
  it('offers the main places, adding, and the full menu', () => {
    setPage(owner, '/orders');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    const bar = screen.getByRole('navigation', { name: 'Quick menu' });

    expect(within(bar).getByRole('link', { name: 'Orders' }).getAttribute('aria-current')).toBe('page');
    expect(within(bar).getByRole('link', { name: 'Products' }).getAttribute('href')).toBe('/products');

    fireEvent.click(within(bar).getByRole('button', { name: 'Add a product or an order' }));
    const sheet = screen.getByRole('dialog', { name: 'Add a product or an order' });
    expect(within(sheet).getByRole('link', { name: 'Add product' }).getAttribute('href')).toBe('/products/new');
    expect(within(sheet).getByRole('link', { name: 'New order' }).getAttribute('href')).toBe('/orders/new');

    fireEvent.click(within(bar).getByRole('button', { name: 'More' }));
    expect(screen.getByRole('dialog', { name: 'Menu' })).toBeTruthy();
  });

  it('is not shown while two-step sign-in still has to be turned on', () => {
    setPage({ ...owner, mfa_enrollment_required: true }, '/security');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    expect(screen.queryByRole('navigation', { name: 'Quick menu' })).toBeNull();
  });
});
