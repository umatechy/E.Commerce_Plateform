import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import { FilterBar } from '@/Components/ui/Filters';
import Badge, { StatusBadge, humanize } from '@/Components/ui/Badge';
import { EmptyPanel, Tabs } from '@/Components/ui/Page';
import { ShipmentDrawer } from '@/Components/Orders/ShipmentDialogs';
import { useAccess } from '@/lib/access';
import { usePagedApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { useUrlState } from '@/lib/useUrlState';
import { dateTimeOrDash } from '@/lib/datetime';
import { money } from '@/lib/money';
import { options } from '@/lib/labels';
import { CARRIER_LABELS, SHIPMENT_STATUSES, type Shipment } from '@/lib/orders';

/**
 * Module 13 §75–76 "Admin Shipping View / Dashboard": the store's
 * shipments (GET /api/v1/shipments). A shipment is created from its
 * order's page.
 *
 * Phase B49 views: what needs someone (a booking the courier did not take,
 * a failed delivery, a parcel back at the store not yet in stock), the
 * cash couriers owe the store (cash on delivery, recorded on the orders'
 * payments when it arrives), and return parcels.
 */
const FILTER_DEFAULTS = { view: 'all', status: '', page: '1' };

function attentionReason(shipment: Shipment): string | null {
  if (shipment.booking_status === 'failed') return 'Courier did not take it';
  if (shipment.booking_status === 'unknown') return 'No answer from the courier';
  if (shipment.status === 'delivery_failed') return 'Delivery failed';
  if (shipment.actions?.restock) return 'Back at the store — put in stock';

  return null;
}

export default function Shipments() {
  const access = useAccess();
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const view = filters.view === 'all' ? '' : filters.view;
  const list = usePagedApi<Shipment>('/shipments', { status: filters.status, page: filters.page, view });
  const [open, setOpen] = useState<string | null>(null);
  const [selected, setSelected] = useState<string[]>([]);
  const [remitting, setRemitting] = useState(false);
  const rows = list.rows ?? [];
  const selectedRows = rows.filter((s) => selected.includes(s.id));
  const selectedTotal = selectedRows.reduce((sum, s) => sum + (s.cod_amount_minor ?? 0), 0);
  const currency = selectedRows[0]?.currency ?? rows[0]?.currency ?? access.currency;

  const columns: Column<Shipment>[] = [
    ...(filters.view === 'cod' ? [{
      key: 'pick', header: 'Select', srOnlyHeader: true,
      render: (s: Shipment) => (
        <input type="checkbox" aria-label={`Select ${s.order?.order_number ?? s.id}`} checked={selected.includes(s.id)} onChange={(e) => setSelected((cur) => (e.target.checked ? [...cur, s.id] : cur.filter((id) => id !== s.id)))} />
      ),
    }] : []),
    {
      key: 'order',
      header: 'Order',
      render: (shipment) =>
        shipment.order ? (
          <Link href={`/orders/${shipment.order.id}`} className={`rounded font-mono text-brand-700 hover:underline ${FOCUS_RING}`}>
            {shipment.order.order_number}
          </Link>
        ) : '—',
    },
    { key: 'created', header: 'Created', render: (shipment) => dateTimeOrDash(shipment.created_at) },
    { key: 'carrier', header: 'Carrier', render: (shipment) => <span>{shipment.carrier_label ?? CARRIER_LABELS[shipment.carrier] ?? humanize(shipment.carrier)}{shipment.direction === 'return' && <Badge tone="blue">Return</Badge>}</span> },
    { key: 'tracking', header: 'Tracking', render: (shipment) => <span className="font-mono text-xs">{shipment.tracking_number ?? '—'}</span> },
    filters.view === 'cod'
      ? { key: 'cod', header: 'Cash owed', align: 'right' as const, priority: true, render: (shipment: Shipment) => <span className="font-medium">{money(shipment.cod_amount_minor ?? 0, shipment.currency)}</span> }
      : { key: 'status', header: 'Status', priority: true, render: (shipment: Shipment) => <span className="space-y-0.5"><StatusBadge status={shipment.status} />{attentionReason(shipment) && <span className="block text-xs text-amber-800">{attentionReason(shipment)}</span>}</span> },
    { key: 'open', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (shipment) => <Button size="sm" variant="ghost" onClick={() => setOpen(shipment.id)}>Open</Button> },
  ];

  return (
    <AdminPage title="Shipments" description="Deliveries of your orders and parcels coming back. Open one for its label, tracking and actions.">
      <Tabs
        label="Shipment views"
        active={filters.view}
        onChange={(next) => { setSelected([]); setFilters({ view: next, page: '1', status: '' }); }}
        tabs={[{ id: 'all', label: 'All' }, { id: 'attention', label: 'Needs attention' }, { id: 'cod', label: 'Cash to collect' }, { id: 'returns', label: 'Return parcels' }]}
      />
      <div className="mt-4 space-y-4">
        {filters.view === 'all' && (
          <FilterBar>
            <div className="w-52">
              <SelectField label="Status" value={filters.status} onChange={(status) => setFilters({ status, page: '1' })} options={options(SHIPMENT_STATUSES)} placeholder="Any status" />
            </div>
            {filters.status !== '' && <Button onClick={() => setFilters({ status: '', page: '1' })}>Clear filter</Button>}
          </FilterBar>
        )}
        {filters.view === 'cod' && (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-slate-200 bg-white p-3 text-sm">
            <p className="text-slate-700">Parcels delivered with cash on delivery. When the courier pays you, select them and record the payment — each order is then marked paid.</p>
            {access.can('payments.manage') && (
              <Button variant="primary" disabled={selected.length === 0} onClick={() => setRemitting(true)}>
                {selected.length === 0 ? 'Record cash received' : `Record ${money(selectedTotal, currency)} received`}
              </Button>
            )}
          </div>
        )}

        <DataTable
          caption="Shipments"
          columns={columns}
          rows={list.rows}
          rowKey={(shipment) => shipment.id}
          loading={list.loading}
          error={list.error}
          onRetry={list.reload}
          empty={
            filters.view === 'attention' ? <EmptyPanel title="Nothing needs attention" description="Every parcel is booked and on its way, or delivered." />
              : filters.view === 'cod' ? <EmptyPanel title="No cash owed" description="Delivered cash-on-delivery parcels appear here until the courier pays you." />
                : filters.view === 'returns' ? <EmptyPanel title="No return parcels" description="Arrange one from a return’s page." />
                  : filters.status !== '' ? <EmptyPanel title="No shipments with this status" action={<Button onClick={() => setFilters({ status: '', page: '1' })}>Clear filter</Button>} />
                    : <EmptyPanel title="No shipments yet" description="Create a shipment from an order's page when you send it out." />
          }
        />
        <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />
      </div>

      {open && <ShipmentDrawer shipmentId={open} onClose={() => setOpen(null)} onChanged={list.reload} />}
      {remitting && <RemittanceDialog shipments={selectedRows} total={selectedTotal} currency={currency} onClose={() => setRemitting(false)} onDone={() => { setRemitting(false); setSelected([]); list.reload(); }} />}
    </AdminPage>
  );
}

function RemittanceDialog({ shipments, total, currency, onClose, onDone }: { shipments: Shipment[]; total: number; currency: string; onClose: () => void; onDone: () => void }) {
  const form = useForm({ reference: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = { shipment_ids: shipments.map((s) => s.id), reference: form.values.reference };
    if ((await form.submit(() => adminFetch('/shipments/cod-remittance', { method: 'POST', body }), 'Cash recorded; the orders are paid.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={`Record ${money(total, currency)} received`} description={`${shipments.length} parcel${shipments.length === 1 ? '' : 's'}: the cash is recorded on each order’s payment, once.`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Payment reference" value={form.values.reference} onChange={(v) => form.set('reference', v)} error={form.errors.reference} maxLength={100} required data-autofocus hint="The courier’s payment or settlement number, or your bank transfer reference." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={form.values.reference.trim() === ''}>Record cash received</Button>
        </div>
      </form>
    </Dialog>
  );
}
