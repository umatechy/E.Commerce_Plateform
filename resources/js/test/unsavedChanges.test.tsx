import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import Dialog from '@/Components/ui/Dialog';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useUnsavedWarning } from '@/lib/useForm';
import { owner, routerMock, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-13: changes are not lost by a stray Escape, backdrop click or menu link. */
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('a dialog with changes', () => {
  it('asks before Escape throws the changes away; untouched it closes at once', () => {
    const onClose = vi.fn();
    render(
      <Dialog open title="Edit brand" onClose={onClose}>
        <input aria-label="Name" />
      </Dialog>,
    );

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Khaadi' } });
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(onClose).not.toHaveBeenCalled();
    expect(screen.getByText('Discard your changes in this form?')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
    expect(screen.queryByText('Discard your changes in this form?')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    fireEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});

function DirtyPage() {
  useUnsavedWarning(true);

  return <AuthenticatedLayout>A form with changes</AuthenticatedLayout>;
}

describe('leaving a page with changes', () => {
  it('holds a menu link and goes on only when the user chooses to leave', () => {
    setPage(owner, '/products/new');
    render(<DirtyPage />);
    const before = routerMock.on.mock.calls.find(([name]) => name === 'before')?.[1] as (event: unknown) => void;
    const preventDefault = vi.fn();

    act(() => before({ preventDefault, detail: { visit: { url: new URL('http://localhost/orders'), method: 'get', only: [] } } }));
    expect(preventDefault).toHaveBeenCalled();
    expect(screen.getByRole('dialog', { name: 'Leave without saving?' })).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Leave page' }));
    expect(routerMock.visit).toHaveBeenCalledWith('/orders', expect.anything());
  });

  it('never holds a partial reload or the page saving itself', () => {
    setPage(owner, '/products/new');
    render(<DirtyPage />);
    const before = routerMock.on.mock.calls.find(([name]) => name === 'before')?.[1] as (event: unknown) => void;
    const preventDefault = vi.fn();

    act(() => before({ preventDefault, detail: { visit: { url: new URL('http://localhost/orders'), method: 'get', only: ['auth'] } } }));
    act(() => before({ preventDefault, detail: { visit: { url: new URL('http://localhost/products'), method: 'post', only: [] } } }));
    expect(preventDefault).not.toHaveBeenCalled();
  });
});
