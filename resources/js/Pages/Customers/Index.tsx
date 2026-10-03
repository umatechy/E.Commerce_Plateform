import { FormEvent, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink, FOCUS_RING } from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import Badge, { StatusBadge } from '@/Components/ui/Badge';
import { EmptyPanel, QueryState, StatCard } from '@/Components/ui/Page';
import { toast } from '@/Components/ui/toast';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { useForm } from '@/lib/useForm';
import { adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { formatDate } from '@/lib/datetime';
import { DATE_FILTERS, options } from '@/lib/labels';
import { downloadFile } from '@/lib/download';
import { CUSTOMER_SORTS, CUSTOMER_STATUSES, IMPORT_TEMPLATE, type CustomerGroup, type CustomerRow, type CustomerTag, type ImportPreview } from '@/lib/customers';

/**
 * Module 10 §51–54, §96 (Phase B32, gap G7): the store's customers.
 *
 * Search, filters and sorting are done by the server
 * (GET /api/v1/customers) and kept in the address. Each customer's
 * order figures are computed by the server from the store's orders.
 * Export and import are separate permissions: the file holds personal
 * data.
 */
const FILTER_DEFAULTS = { search: '', status: '', group: '', tag: '', consent: '', account: '', min_orders: '', registered_from: '', registered_to: '', sort: 'newest', page: '1' };

type Report = { new_customers: number; customers_with_orders: number; repeat_customers: number; repeat_customer_rate_percent: number | null };

function Figures() {
  const [range, setRange] = useState('this_month');
  const state = useApi<{ data: Report }>('/reports/customers', { date_filter: range });

  return (
    <section aria-labelledby="figures" className="mb-6">
      <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
        <h2 id="figures" className="text-sm font-semibold text-slate-900">How your customers are doing</h2>
        <div className="w-44">
          <SelectField label="Period" value={range} onChange={setRange} options={DATE_FILTERS.filter((option) => option.value !== 'custom')} />
        </div>
      </div>
      <QueryState state={state} lines={2}>
        {({ data }) => (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="New customers" value={data.new_customers} />
            <StatCard label="Customers who ordered" value={data.customers_with_orders} />
            <StatCard label="Ordered more than once" value={data.repeat_customers} />
            <StatCard label="Repeat rate" value={data.repeat_customer_rate_percent === null ? null : `${data.repeat_customer_rate_percent}%`} />
          </div>
        )}
      </QueryState>
    </section>
  );
}

function AddCustomerDialog({ groups, onClose }: { groups: CustomerGroup[]; onClose: () => void }) {
  const form = useForm({ name: '', email: '', phone: '', group: '', tags: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const v = form.values;
    const body = {
      name: v.name,
      email: v.email,
      phone: v.phone === '' ? null : v.phone,
      group: v.group === '' ? null : v.group,
      tags: v.tags.split(',').map((t) => t.trim()).filter((t) => t !== ''),
    };
    const saved = await form.submit(() => adminFetch<{ data: CustomerRow }>('/customers', { method: 'POST', body }), 'Customer added.');
    if (saved) router.visit(`/customers/${saved.data.id}`);
  }

  return (
    <Dialog open title="Add a customer" description="For someone who buys from you by phone or in person. They get no account: they can register on your storefront themselves later." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} data-autofocus />
        <TextField label="Email" type="email" value={form.values.email} onChange={(v) => form.set('email', v)} error={form.errors.email} required maxLength={255} />
        <TextField label="Phone" optional value={form.values.phone} onChange={(v) => form.set('phone', v)} error={form.errors.phone} maxLength={32} />
        <SelectField label="Group" optional value={form.values.group} onChange={(v) => form.set('group', v)} error={form.errors.group} placeholder="No group" options={groups.map((g) => ({ value: g.id, label: g.name }))} />
        <TextField label="Tags" optional value={form.values.tags} onChange={(v) => form.set('tags', v)} error={form.errors.tags} hint="Separate tags with commas, e.g. VIP, Wholesale." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Adding…">Add customer</Button>
        </div>
      </form>
    </Dialog>
  );
}

function ImportDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const file = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function check(event: FormEvent) {
    event.preventDefault();
    const chosen = file.current?.files?.[0];
    if (!chosen) {
      setError('Choose a CSV file first.');

      return;
    }
    setBusy(true);
    setError(null);
    const body = new FormData();
    body.append('file', chosen);
    try {
      const result = await adminFetch<{ data: ImportPreview }>('/customers/import', { method: 'POST', body, timeoutMs: 60000 });
      setPreview(result.data);
    } catch (e) {
      setError(adminErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  async function confirm() {
    if (!preview) return;
    setBusy(true);
    setError(null);
    try {
      const result = await adminFetch<{ data: { created: number; skipped: number } }>(`/customers/import/${preview.id}/confirm`, { method: 'POST', timeoutMs: 60000 });
      toast.success(`${result.data.created} customer${result.data.created === 1 ? '' : 's'} imported${result.data.skipped ? `, ${result.data.skipped} skipped` : ''}.`);
      onDone();
    } catch (e) {
      setError(adminErrorMessage(e));
      setBusy(false);
    }
  }

  const problems = preview?.rows.filter((row) => row.status !== 'ready') ?? [];

  return (
    <Dialog open wide title="Import customers" onClose={onClose} busy={busy}>
      {preview === null ? (
        <form onSubmit={check} className="space-y-4" noValidate>
          <p className="text-sm text-slate-700">
            A CSV file with a first row naming the columns: <span className="font-mono">name</span> and <span className="font-mono">email</span> are needed;{' '}
            <span className="font-mono">phone</span>, <span className="font-mono">group</span> and <span className="font-mono">tags</span> (separated by ;) may be added. Up to 2,000 customers, 2 MB.
            Nothing is saved until you confirm the check.
          </p>
          <a href={`data:text/csv;charset=utf-8,${encodeURIComponent(IMPORT_TEMPLATE)}`} download="customers-template.csv" className={`inline-block rounded text-sm font-medium text-indigo-700 underline ${FOCUS_RING}`}>
            Download an example file
          </a>
          <div>
            <label htmlFor="customer-import" className="block text-sm font-medium text-slate-700">CSV file</label>
            <input id="customer-import" ref={file} type="file" accept=".csv,text/csv" className="mt-1 block w-full text-sm" />
          </div>
          {error && <FormError message={error} />}
          <div className="flex justify-end gap-2">
            <Button onClick={onClose} disabled={busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={busy} busyLabel="Checking…">Check the file</Button>
          </div>
        </form>
      ) : (
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-4">
            <StatCard label="Rows" value={preview.totals.rows} />
            <StatCard label="Will be added" value={preview.totals.ready} />
            <StatCard label="Already customers" value={preview.totals.existing} hint="Skipped, never merged." />
            <StatCard label="With problems" value={preview.totals.invalid} hint="Skipped." />
          </div>
          {problems.length > 0 && (
            <div className="max-h-72 overflow-y-auto rounded-md border border-slate-200">
              <table className="w-full text-left text-sm">
                <caption className="sr-only">Rows that will not be added</caption>
                <thead className="bg-slate-50 text-xs uppercase text-slate-600">
                  <tr><th scope="col" className="px-3 py-2">Row</th><th scope="col" className="px-3 py-2">Email</th><th scope="col" className="px-3 py-2">Why</th></tr>
                </thead>
                <tbody>
                  {problems.map((row) => (
                    <tr key={row.row} className="border-t border-slate-100">
                      <td className="px-3 py-2">{row.row}</td>
                      <td className="break-all px-3 py-2">{row.email || '—'}</td>
                      <td className="px-3 py-2">{row.messages.join(' ')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {error && <FormError message={error} />}
          <div className="flex justify-end gap-2">
            <Button onClick={() => setPreview(null)} disabled={busy}>Choose another file</Button>
            <Button variant="primary" onClick={confirm} busy={busy} busyLabel="Importing…" disabled={preview.totals.ready === 0}>
              Add {preview.totals.ready} customer{preview.totals.ready === 1 ? '' : 's'}
            </Button>
          </div>
        </div>
      )}
    </Dialog>
  );
}

export default function Index() {
  const access = useAccess();
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const list = usePagedApi<CustomerRow>('/customers', filters);
  const groups = useApi<{ data: CustomerGroup[] }>('/customer-groups');
  const tags = useApi<{ data: CustomerTag[] }>('/customer-tags');
  const [dialog, setDialog] = useState<'add' | 'import' | null>(null);
  const filtered = Object.entries(filters).some(([key, value]) => !['page', 'sort'].includes(key) && value !== '');
  // The export takes the list's filters (not its page).
  const exportFilters = Object.fromEntries(Object.entries(filters).filter(([key]) => key !== 'page'));
  const [exporting, setExporting] = useState(false);

  const columns: Column<CustomerRow>[] = [
    {
      key: 'name',
      header: 'Customer',
      render: (customer) => (
        <div>
          <Link href={`/customers/${customer.id}`} className={`rounded font-medium text-indigo-700 hover:underline ${FOCUS_RING}`}>{customer.name}</Link>
          <p className="break-all text-xs text-slate-600">{customer.email}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (customer) => (customer.erased ? <Badge>Erased</Badge> : <StatusBadge status={customer.status} />) },
    { key: 'group', header: 'Group', render: (customer) => customer.group?.name ?? '—' },
    {
      key: 'tags',
      header: 'Tags',
      render: (customer) => ((customer.tags ?? []).length === 0 ? '—' : <span className="flex flex-wrap gap-1">{(customer.tags ?? []).map((tag) => <Badge key={tag.id} tone="blue">{tag.name}</Badge>)}</span>),
    },
    { key: 'orders', header: 'Orders', align: 'right', render: (customer) => customer.orders_count ?? 0 },
    { key: 'spent', header: 'Spent', align: 'right', priority: true, render: (customer) => money(customer.total_spent_minor ?? 0, access.currency) },
    { key: 'last', header: 'Last order', render: (customer) => (customer.last_order_at ? formatDate(customer.last_order_at) : '—') },
  ];

  return (
    <AdminPage
      title="Customers"
      description="Everyone who has an account with your store or was added by your team. Guests who never registered appear with their orders."
      actions={
        <>
          <ButtonLink href="/customers/groups">Groups and tags</ButtonLink>
          {access.can('customers.export') && (
            <Button
              busy={exporting}
              busyLabel="Preparing…"
              onClick={() => {
                setExporting(true);
                downloadFile('/customers/export', exportFilters, 'customers.csv')
                  .then(() => toast.success('Export downloaded.'))
                  .catch((e) => toast.error(adminErrorMessage(e)))
                  .finally(() => setExporting(false));
              }}
            >
              Export CSV
            </Button>
          )}
          {access.can('customers.import') && <Button onClick={() => setDialog('import')}>Import</Button>}
          {access.can('customers.manage') && <Button variant="primary" onClick={() => setDialog('add')}>Add customer</Button>}
        </>
      }
    >
      {access.can('analytics.view') && <Figures />}

      <FilterBar>
        <SearchField label="Search" placeholder="Name, email, phone or order number" value={filters.search} onChange={(search) => setFilters({ search, page: '1' })} />
        <div className="w-36"><SelectField label="Status" value={filters.status} onChange={(status) => setFilters({ status, page: '1' })} options={options(CUSTOMER_STATUSES)} placeholder="Any" /></div>
        <div className="w-40">
          <SelectField label="Group" value={filters.group} onChange={(group) => setFilters({ group, page: '1' })} placeholder="Any" options={[{ value: 'none', label: 'In no group' }, ...(groups.data?.data ?? []).map((g) => ({ value: g.id, label: g.name }))]} />
        </div>
        <div className="w-40"><SelectField label="Tag" value={filters.tag} onChange={(tag) => setFilters({ tag, page: '1' })} placeholder="Any" options={(tags.data?.data ?? []).map((t) => ({ value: t.id, label: t.name }))} /></div>
        <div className="w-40"><SelectField label="Account" value={filters.account} onChange={(account) => setFilters({ account, page: '1' })} placeholder="Any" options={[{ value: 'registered', label: 'Has an account' }, { value: 'no_account', label: 'No account' }]} /></div>
        <div className="w-40"><SelectField label="Marketing email" value={filters.consent} onChange={(consent) => setFilters({ consent, page: '1' })} placeholder="Any" options={[{ value: 'yes', label: 'Agreed' }, { value: 'no', label: 'Not agreed' }]} /></div>
        <div className="w-32"><TextField label="Min. orders" type="number" min={0} value={filters.min_orders} onChange={(min_orders) => setFilters({ min_orders, page: '1' })} /></div>
        <div className="w-40"><TextField label="Joined from" type="date" value={filters.registered_from} onChange={(registered_from) => setFilters({ registered_from, page: '1' })} /></div>
        <div className="w-40"><TextField label="Joined until" type="date" value={filters.registered_to} onChange={(registered_to) => setFilters({ registered_to, page: '1' })} /></div>
        <div className="w-40"><SelectField label="Sort" value={filters.sort} onChange={(sort) => setFilters({ sort, page: '1' })} options={CUSTOMER_SORTS} /></div>
        {filtered && <Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filters</Button>}
      </FilterBar>

      <DataTable
        caption="Customers"
        columns={columns}
        rows={list.rows}
        rowKey={(customer) => customer.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={
          filtered ? (
            <EmptyPanel title="No customers match" action={<Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filters</Button>} />
          ) : (
            <EmptyPanel title="No customers yet" description="Customers appear here when they register on your storefront, or when you add or import them." action={access.can('customers.manage') ? <Button variant="primary" onClick={() => setDialog('add')}>Add customer</Button> : undefined} />
          )
        }
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />
      <p className="mt-2 text-xs text-slate-600">Spent is the total of a customer's orders that were not cancelled, in your store currency ({access.currency}).</p>

      {dialog === 'add' && <AddCustomerDialog groups={groups.data?.data ?? []} onClose={() => setDialog(null)} />}
      {dialog === 'import' && <ImportDialog onClose={() => setDialog(null)} onDone={() => { setDialog(null); list.reload(); tags.reload(); }} />}
    </AdminPage>
  );
}
