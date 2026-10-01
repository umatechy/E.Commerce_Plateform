import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import DataTable, { Pagination, type Column } from './DataTable';
import Dialog, { ConfirmDialog } from './Dialog';
import { FormError, SwitchField, TextField } from './Form';
import { StatusBadge, humanize, toneForStatus } from './Badge';
import { QueryState, StatCard, Tabs, UsageMeter } from './Page';
import { clearToasts, toast, Toaster } from './toast';

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

afterEach(() => {
  cleanup();
  clearToasts();
});

type Row = { id: number; name: string; status: string };
const columns: Column<Row>[] = [
  { key: 'name', header: 'Name', render: (row) => row.name },
  { key: 'status', header: 'Status', render: (row) => row.status },
  { key: 'actions', header: 'Actions', srOnlyHeader: true, priority: true, render: () => 'Edit' },
];

describe('DataTable', () => {
  it('shows a loading state before the first answer, announced to readers', () => {
    render(<DataTable caption="Products" columns={columns} rows={null} rowKey={(row) => row.id} empty={<p>Nothing</p>} />);

    expect(screen.getByRole('status').textContent).toContain('Loading');
    expect(screen.queryByText('Nothing')).toBeNull();
  });

  it('shows the empty state only for an empty answer', () => {
    render(<DataTable caption="Products" columns={columns} rows={[]} rowKey={(row) => row.id} empty={<p>No products yet</p>} />);

    expect(screen.getByText('No products yet')).toBeTruthy();
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('shows an error with a way to try again, instead of the table', () => {
    const retry = vi.fn();
    render(<DataTable caption="Products" columns={columns} rows={null} rowKey={(row) => row.id} error="Could not load." onRetry={retry} empty={<p>Nothing</p>} />);

    expect(screen.getByRole('alert').textContent).toContain('Could not load.');
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(retry).toHaveBeenCalledTimes(1);
  });

  it('renders rows under a caption, hiding secondary columns on small screens', () => {
    render(<DataTable caption="Products" columns={columns} rows={[{ id: 1, name: 'Shirt', status: 'active' }]} rowKey={(row) => row.id} empty={<p>Nothing</p>} />);

    expect(screen.getByRole('table', { name: 'Products' })).toBeTruthy();
    expect(screen.getByText('Shirt')).toBeTruthy();
    const headers = screen.getAllByRole('columnheader');
    expect(headers[0].className).not.toContain('hidden'); // the first column stays
    expect(headers[1].className).toContain('hidden sm:table-cell'); // a secondary column is dropped on phones
    expect(headers[2].className).not.toContain('hidden'); // a priority column stays
  });
});

describe('Pagination', () => {
  it('moves one server page at a time and stops at both ends', () => {
    const onPage = vi.fn();
    const { rerender } = render(<Pagination meta={{ page: 1, lastPage: 3, total: 60, perPage: 25 }} onPage={onPage} />);

    expect((screen.getByRole('button', { name: 'Previous' }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(onPage).toHaveBeenCalledWith(2);

    rerender(<Pagination meta={{ page: 3, lastPage: 3, total: 60, perPage: 25 }} onPage={onPage} />);
    expect((screen.getByRole('button', { name: 'Next' }) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByText(/Page 3 of 3/)).toBeTruthy();
  });

  it('shows no buttons for a single page', () => {
    render(<Pagination meta={{ page: 1, lastPage: 1, total: 4, perPage: 25 }} onPage={vi.fn()} />);

    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.getByText('4 in total')).toBeTruthy();
  });
});

describe('Dialog', () => {
  it('is a labelled modal that takes the focus and closes on Escape', () => {
    const onClose = vi.fn();
    render(
      <Dialog open title="Edit brand" description="Change its name." onClose={onClose}>
        <input aria-label="Name" />
      </Dialog>,
    );

    const dialog = screen.getByRole('dialog', { name: 'Edit brand' });
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    expect(dialog.contains(document.activeElement)).toBe(true);

    fireEvent.keyDown(dialog, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('cannot be dismissed while a request is in flight', () => {
    const onClose = vi.fn();
    render(<Dialog open busy title="Saving" onClose={onClose} />);

    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(onClose).not.toHaveBeenCalled();
  });

  it('renders nothing while closed', () => {
    render(<Dialog open={false} title="Hidden" onClose={vi.fn()} />);

    expect(screen.queryByRole('dialog')).toBeNull();
  });
});

describe('ConfirmDialog', () => {
  it('confirms a destructive action only on the confirm button', () => {
    const onConfirm = vi.fn();
    const onClose = vi.fn();
    render(
      <ConfirmDialog open title="Delete this product?" confirmLabel="Delete product" onConfirm={onConfirm} onClose={onClose}>
        <p>This cannot be undone.</p>
      </ConfirmDialog>,
    );

    expect(screen.getByText('This cannot be undone.')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(onConfirm).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Delete product' }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('for the riskiest actions, works only after the exact text is typed back', () => {
    const onConfirm = vi.fn();
    render(
      <ConfirmDialog open title="Request a restore?" confirmLabel="File the request" confirmText="AB12CD34" onConfirm={onConfirm} onClose={vi.fn()}>
        <p>Data would be lost.</p>
      </ConfirmDialog>,
    );

    const confirm = screen.getByRole('button', { name: 'File the request' }) as HTMLButtonElement;
    expect(confirm.disabled).toBe(true);

    fireEvent.change(screen.getByLabelText('Type AB12CD34 to confirm'), { target: { value: 'AB12' } });
    expect(confirm.disabled).toBe(true);
    fireEvent.change(screen.getByLabelText('Type AB12CD34 to confirm'), { target: { value: 'AB12CD34' } });
    expect(confirm.disabled).toBe(false);
  });

  it('shows the server refusal inside the dialog', () => {
    render(
      <ConfirmDialog open title="Delete role?" confirmLabel="Delete role" error="Give the people with this role another role first." onConfirm={vi.fn()} onClose={vi.fn()}>
        <p>Deleted for good.</p>
      </ConfirmDialog>,
    );

    expect(screen.getByRole('alert').textContent).toContain('another role first');
  });
});

describe('form fields', () => {
  it('ties a field to its label and its error', () => {
    render(<TextField label="Name" value="" onChange={vi.fn()} error="The name field is required." />);

    const input = screen.getByLabelText('Name');
    expect(input.getAttribute('aria-invalid')).toBe('true');
    const described = document.getElementById(input.getAttribute('aria-describedby') as string);
    expect(described?.textContent).toContain('The name field is required.');
    expect(described?.getAttribute('role')).toBe('alert');
  });

  it('ties a field to its hint when there is no error', () => {
    render(<TextField label="SKU" value="" onChange={vi.fn()} hint="Up to 64 characters." />);

    const input = screen.getByLabelText('SKU');
    expect(input.getAttribute('aria-invalid')).toBeNull();
    expect(document.getElementById(input.getAttribute('aria-describedby') as string)?.textContent).toBe('Up to 64 characters.');
  });

  it('lists the messages of a failed save', () => {
    render(<FormError message="Check the highlighted fields." errors={{ name: 'Name is required.', sku: 'SKU is taken.' }} />);

    const alert = screen.getByRole('alert');
    expect(alert.textContent).toContain('Check the highlighted fields.');
    expect(alert.textContent).toContain('SKU is taken.');
  });

  it('has a switch that says its state in words', () => {
    const onChange = vi.fn();
    render(<SwitchField label="Maintenance mode" checked={false} onChange={onChange} />);

    const control = screen.getByRole('switch', { name: 'Maintenance mode' });
    expect(screen.getByText('Off')).toBeTruthy();
    fireEvent.click(control);
    expect(onChange).toHaveBeenCalledWith(true);
  });
});

describe('status and figures', () => {
  it('writes a status out in words, with a tone that only supports it', () => {
    render(<StatusBadge status="pending_confirmation" />);

    expect(screen.getByText('Pending confirmation')).toBeTruthy();
    expect(humanize('store.timezone')).toBe('Store timezone');
    expect(toneForStatus('paid')).toBe('green');
    expect(toneForStatus('failed')).toBe('red');
    expect(toneForStatus('something_new')).toBe('neutral');
  });

  it('says "Not available" for a figure the server did not send, never zero', () => {
    render(<StatCard label="Average order" value={null} />);

    expect(screen.getByText('Not available')).toBeTruthy();
    expect(screen.queryByText('0')).toBeNull();
  });

  it('shows usage as numbers and marks a reached limit', () => {
    const { rerender } = render(<UsageMeter label="Products" current={120} limit={500} />);
    expect(screen.getByText('Products: 120 of 500 used')).toBeTruthy();

    rerender(<UsageMeter label="Products" current={500} limit={500} />);
    expect(screen.getByText(/limit reached/)).toBeTruthy();

    rerender(<UsageMeter label="Products" current={900} limit={null} />);
    expect(screen.getByText(/no limit on your package/)).toBeTruthy();
  });
});

describe('QueryState', () => {
  const base = { data: null, loading: false, error: null as string | null, reload: vi.fn() };

  it('offers "Try again" for a failure and explains a refusal as a permission matter', () => {
    const reload = vi.fn();
    const { rerender } = render(<QueryState state={{ ...base, error: 'Could not load.', errorStatus: 500, reload }}>{() => <p>ready</p>}</QueryState>);

    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(reload).toHaveBeenCalledTimes(1);

    rerender(<QueryState state={{ ...base, error: 'You do not have permission to do this.', errorStatus: 403, reload }}>{() => <p>ready</p>}</QueryState>);
    expect(screen.getByText('You cannot open this.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();
  });

  it('points to Security when two-step sign-in is the reason', () => {
    render(<QueryState state={{ ...base, error: 'Turn on two-step sign-in to use the platform administration.', errorStatus: 403, errorCode: 'mfa_enrollment_required' }}>{() => <p>ready</p>}</QueryState>);

    expect(screen.getByRole('link', { name: 'Turn on two-step sign-in' }).getAttribute('href')).toBe('/security');
  });

  it('renders the data once it is there', () => {
    render(<QueryState state={{ ...base, data: { name: 'Shirt' } }}>{(data) => <p>{data.name}</p>}</QueryState>);

    expect(screen.getByText('Shirt')).toBeTruthy();
  });
});

describe('Tabs', () => {
  it('moves between tabs with the arrow keys', () => {
    const onChange = vi.fn();
    render(<Tabs label="Sections" tabs={[{ id: 'a', label: 'Details' }, { id: 'b', label: 'Pricing' }]} active="a" onChange={onChange} />);

    expect(screen.getByRole('tab', { name: 'Details' }).getAttribute('aria-selected')).toBe('true');
    fireEvent.keyDown(screen.getByRole('tablist'), { key: 'ArrowRight' });
    expect(onChange).toHaveBeenCalledWith('b');
    fireEvent.keyDown(screen.getByRole('tablist'), { key: 'ArrowLeft' });
    expect(onChange).toHaveBeenCalledWith('b'); // wraps round from the first to the last
  });
});

describe('toasts', () => {
  it('announces a result and can be dismissed', () => {
    render(<Toaster />);

    act(() => toast.success('Product saved.'));
    expect(screen.getByRole('status').textContent).toContain('Product saved.');

    act(() => toast.error('Could not save.'));
    expect(screen.getByRole('alert').textContent).toContain('Could not save.');

    fireEvent.click(screen.getAllByRole('button', { name: 'Dismiss' })[0]);
    expect(screen.queryByText(/Product saved/)).toBeNull();
  });
});
