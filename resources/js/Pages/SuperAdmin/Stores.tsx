import { FormEvent, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import Badge from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { useUrlState } from '@/lib/useUrlState';
import { adminFetch, idempotencyKey } from '@/lib/adminApi';
import { formatDate } from '@/lib/datetime';
import { STAGE_LABEL, STAGE_TONE } from '@/lib/stores';

/**
 * Module 30 store administration: every store on the platform
 * (GET /api/v1/super-admin/stores), searched and filtered by the server.
 * Platform staff only. Opening a store's details is recorded in the audit
 * trail by the server (cross-tenant access, ADR-001 Layer 7).
 *
 * Phase B44 (owner decision 13, Module 03 §5A, §38): staff create a store
 * for a customer here — what it sells, the package for their budget, the
 * trial — and the customer gets an email to take it over. Each row shows
 * the store's stage, package, owner (or who was invited) and how it was made.
 */
type Store = {
  id: number;
  name: string;
  slug: string;
  status: string;
  created_at: string;
  stage?: string;
  business_category?: string | null;
  created_via?: string;
  package_code?: string | null;
  owner_email?: string | null;
  has_owner?: boolean;
};
type Option = { value: string; label: string };

export default function Stores() {
  const [filters, setFilters] = useUrlState({ search: '', business_category: '', created_via: '', page: '1' });
  const list = usePagedApi<Store>('/super-admin/stores', filters);
  const categories = useApi<{ data: Option[] }>('/super-admin/business-categories');
  const [creating, setCreating] = useState(false);
  const categoryLabel = (key?: string | null) => (categories.data?.data ?? []).find((c) => c.value === key)?.label ?? key ?? '—';

  const columns: Column<Store>[] = [
    {
      key: 'name',
      header: 'Store',
      render: (store) => (
        <div>
          <Link href={`/super-admin/stores/${store.id}`} className={`rounded font-medium text-indigo-700 hover:underline ${FOCUS_RING}`}>
            {store.name}
          </Link>
          <p className="font-mono text-xs text-slate-500">{store.slug}</p>
        </div>
      ),
    },
    { key: 'stage', header: 'Stage', priority: true, render: (store) => <Badge tone={STAGE_TONE[store.stage ?? ''] ?? 'neutral'}>{STAGE_LABEL[store.stage ?? ''] ?? store.status}</Badge> },
    { key: 'package', header: 'Package', render: (store) => store.package_code ?? '—' },
    { key: 'category', header: 'Sells', render: (store) => categoryLabel(store.business_category) },
    {
      key: 'owner', header: 'Owner',
      render: (store) => (store.owner_email ? <span className="break-all">{store.owner_email}{!store.has_owner && <span className="text-xs text-amber-700"> (invited)</span>}</span> : '—'),
    },
    { key: 'via', header: 'Created by', render: (store) => (store.created_via === 'platform' ? 'Umar Techy team' : 'Customer') },
    { key: 'created', header: 'Created', render: (store) => formatDate(store.created_at, 'UTC') },
  ];

  return (
    <AdminPage title="Stores" description="Every store on the platform. Dates are UTC." actions={<Button variant="primary" onClick={() => setCreating(true)}>Create store for a customer</Button>}>
      <FilterBar>
        <SearchField label="Search" placeholder="Store name or address" value={filters.search} onChange={(search) => setFilters({ search, page: '1' })} />
        <div className="w-52">
          <SelectField label="Sells" value={filters.business_category} onChange={(v) => setFilters({ business_category: v, page: '1' })} placeholder="Anything" options={categories.data?.data ?? []} />
        </div>
        <div className="w-48">
          <SelectField label="Created by" value={filters.created_via} onChange={(v) => setFilters({ created_via: v, page: '1' })} placeholder="Anyone" options={[{ value: 'platform', label: 'Umar Techy team' }, { value: 'self_service', label: 'Customer' }]} />
        </div>
      </FilterBar>
      <DataTable caption="Stores" columns={columns} rows={list.rows} rowKey={(store) => store.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title={filters.search ? 'No store matches' : 'No stores yet'} />} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />

      {creating && <CreateStoreDialog categories={categories.data?.data ?? []} onClose={() => setCreating(false)} />}
    </AdminPage>
  );
}

type Values = { store_name: string; business_category: string; package_code: string; trial_days: string; owner_email: string };

function CreateStoreDialog({ categories, onClose }: { categories: Option[]; onClose: () => void }) {
  const packages = useApi<{ data: { code: string; name: string; is_active?: boolean }[] }>('/super-admin/packages');
  const form = useForm<Values>({ store_name: '', business_category: '', package_code: '', trial_days: '', owner_email: '' });
  // One key per dialog: a double click or a retry never makes two stores.
  const [key] = useState(() => idempotencyKey());
  const { values: v, set } = form;

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = { store_name: v.store_name, business_category: v.business_category, package_code: v.package_code, owner_email: v.owner_email, trial_days: v.trial_days === '' ? null : Number(v.trial_days) };
    const created = await form.submit(
      () => adminFetch<{ data: { id: number } }>('/super-admin/stores', { method: 'POST', body, headers: { 'Idempotency-Key': key } }),
      'Store created. The owner invitation is on its way.',
    );
    if (created) router.visit(`/super-admin/stores/${created.data.id}`);
  }

  return (
    <Dialog open title="Create a store for a customer" onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Store name" value={v.store_name} onChange={(x) => set('store_name', x)} error={form.errors.store_name} required maxLength={255} data-autofocus />
        <SelectField label="What it sells" value={v.business_category} onChange={(x) => set('business_category', x)} error={form.errors.business_category} placeholder="Choose" options={categories} />
        <SelectField
          label="Package"
          value={v.package_code}
          onChange={(x) => set('package_code', x)}
          error={form.errors.package_code}
          placeholder={packages.loading ? 'Loading…' : 'Choose by the customer’s budget'}
          options={(packages.data?.data ?? []).filter((p) => p.is_active !== false).map((p) => ({ value: p.code, label: p.name }))}
        />
        <TextField label="Trial (days)" optional inputMode="numeric" value={v.trial_days} onChange={(x) => set('trial_days', x)} error={form.errors.trial_days} hint="0 to 90. Empty: the platform’s trial length. 0: the first invoice is due now." />
        <TextField label="Owner’s email" type="email" value={v.owner_email} onChange={(x) => set('owner_email', x)} error={form.errors.owner_email} required hint="They get an email to set a password, turn on two-step sign-in and take over the store." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Creating…">Create store</Button>
        </div>
      </form>
    </Dialog>
  );
}
