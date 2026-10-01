import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { FormError, TextAreaField, TextField } from '@/Components/ui/Form';
import Badge, { StatusBadge } from '@/Components/ui/Badge';
import { EmptyPanel, PackageNotice } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';

/**
 * Module 08 "Warehouses" (/api/v1/warehouses). Every store has one
 * warehouse; more than one is part of the `inventory.multi_warehouse`
 * package feature, which the server enforces. The API has no delete for
 * warehouses, so none is offered.
 */
type Warehouse = { id: string; internal_id: number; name: string; code: string; address: string | null; contact: string | null; status: string; is_default: boolean; fulfillment_priority: number };
type Values = { name: string; code: string; address: string; contact: string; fulfillment_priority: string };

const BLANK: Values = { name: '', code: '', address: '', contact: '', fulfillment_priority: '0' };

export default function Warehouses() {
  const access = useAccess();
  const canManage = access.can('warehouses.manage');
  const list = useApi<{ data: Warehouse[] }>('/warehouses');
  const [editing, setEditing] = useState<Warehouse | 'new' | null>(null);
  const form = useForm<Values>(BLANK);

  const warehouses = list.data?.data ?? null;
  // The package feature as the server shared it; the server decides again on save.
  const packageBlocksMore = warehouses !== null && warehouses.length >= 1 && access.feature('inventory.multi_warehouse') === false;

  function open(target: Warehouse | 'new') {
    form.reset(
      target === 'new'
        ? BLANK
        : { name: target.name, code: target.code, address: target.address ?? '', contact: target.contact ?? '', fulfillment_priority: String(target.fulfillment_priority ?? 0) },
    );
    setEditing(target);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const target = editing;
    const v = form.values;
    const body = {
      name: v.name,
      code: v.code,
      address: v.address === '' ? null : v.address,
      contact: v.contact === '' ? null : v.contact,
      fulfillment_priority: v.fulfillment_priority === '' ? 0 : Number(v.fulfillment_priority),
    };
    const saved = await form.submit(
      () => adminFetch(target === 'new' ? '/warehouses' : `/warehouses/${(target as Warehouse).id}`, { method: target === 'new' ? 'POST' : 'PUT', body }),
      target === 'new' ? 'Warehouse added.' : 'Warehouse saved.',
    );
    if (saved !== undefined) {
      setEditing(null);
      list.reload();
    }
  }

  const columns: Column<Warehouse>[] = [
    {
      key: 'name',
      header: 'Warehouse',
      render: (warehouse) => (
        <div>
          <span className="font-medium">{warehouse.name}</span> {warehouse.is_default && <Badge tone="blue">Default</Badge>}
          <p className="font-mono text-xs text-slate-500">{warehouse.code}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (warehouse) => <StatusBadge status={warehouse.status} /> },
    { key: 'address', header: 'Address', render: (warehouse) => warehouse.address ?? '—' },
    { key: 'priority', header: 'Fulfilment order', align: 'right', render: (warehouse) => warehouse.fulfillment_priority },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (warehouse) => canManage && <Button size="sm" variant="ghost" onClick={() => open(warehouse)}>Edit<span className="sr-only"> {warehouse.name}</span></Button>,
    },
  ];

  return (
    <AdminPage
      title="Warehouses"
      description="Where your stock is kept and shipped from."
      actions={
        canManage && (
          <Button variant="primary" onClick={() => open('new')} disabled={packageBlocksMore} title={packageBlocksMore ? 'Not included in your package' : undefined}>
            Add warehouse
          </Button>
        )
      }
    >
      {packageBlocksMore && (
        <div className="mb-4">
          <PackageNotice title="More than one warehouse is not included in your package" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
            Your store can keep stock in one warehouse.
          </PackageNotice>
        </div>
      )}

      <DataTable
        caption="Warehouses"
        columns={columns}
        rows={warehouses}
        rowKey={(warehouse) => warehouse.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No warehouses" />}
      />

      <Dialog open={editing !== null} title={editing === 'new' ? 'Add warehouse' : 'Edit warehouse'} onClose={() => setEditing(null)} busy={form.busy}>
        <form onSubmit={save} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} data-autofocus />
          <TextField label="Code" value={form.values.code} onChange={(v) => form.set('code', v)} error={form.errors.code} required maxLength={32} hint="A short code of letters, numbers, dashes or underscores." />
          <TextAreaField label="Address" optional rows={2} value={form.values.address} onChange={(v) => form.set('address', v)} error={form.errors.address} />
          <TextField label="Contact" optional value={form.values.contact} onChange={(v) => form.set('contact', v)} error={form.errors.contact} maxLength={255} />
          <TextField label="Fulfilment order" type="number" min={0} value={form.values.fulfillment_priority} onChange={(v) => form.set('fulfillment_priority', v)} error={form.errors.fulfillment_priority} hint="Lower numbers are used first." />
          <div className="flex justify-end gap-2">
            <Button onClick={() => setEditing(null)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save warehouse</Button>
          </div>
        </form>
      </Dialog>
    </AdminPage>
  );
}
