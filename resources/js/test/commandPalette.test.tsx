import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { owner, routerMock, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-2: "Go to…" (Ctrl+K), menu icons and folding groups. */
afterEach(() => {
  cleanup();
  routerMock.visit.mockClear();
  window.localStorage.clear();
});

describe('Go to…', () => {
  it('opens with Ctrl+K, finds a page or a quick action and goes there', () => {
    setPage(owner, '/');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);

    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    const dialog = screen.getByRole('dialog', { name: 'Go to a page' });
    const search = within(dialog).getByLabelText('Search pages');
    fireEvent.change(search, { target: { value: 'add product' } });
    expect(within(dialog).getByRole('option', { name: /Add a product/ })).toBeTruthy();
    fireEvent.keyDown(search, { key: 'Enter' });
    expect(routerMock.visit).toHaveBeenCalledWith('/products/new');
    expect(screen.queryByRole('dialog', { name: 'Go to a page' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: /Go to…/ }));
    fireEvent.change(screen.getByLabelText('Search pages'), { target: { value: 'nothing like this' } });
    expect(screen.getByText(/Nothing matches/)).toBeTruthy();
  });

  it('offers only pages the menu shows this user', () => {
    setPage({ ...owner, is_owner: false, permissions: ['orders.view'] }, '/');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    const search = screen.getByLabelText('Search pages');
    fireEvent.change(search, { target: { value: 'products' } });
    expect(screen.queryByRole('option', { name: /^Products/ })).toBeNull();
    fireEvent.change(search, { target: { value: 'orders' } });
    expect(screen.getByRole('option', { name: /Orders/ })).toBeTruthy();
  });
});

describe('menu groups', () => {
  it('fold away and remember it, but the group of the open page stays open', () => {
    setPage(owner, '/');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    const nav = screen.getByRole('navigation', { name: 'Admin' });
    fireEvent.click(within(nav).getByRole('button', { name: 'Catalog' }));
    expect(within(nav).queryByRole('link', { name: 'Products' })).toBeNull();
    expect(window.localStorage.getItem('admin.sidebar.folded')).toBe('["Catalog"]');
    cleanup();

    setPage(owner, '/products');
    render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    expect(within(screen.getByRole('navigation', { name: 'Admin' })).getByRole('link', { name: 'Products' })).toBeTruthy();
  });
});
