import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import Badge, { type Tone } from '@/Components/ui/Badge';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import { FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { FilterBar } from '@/Components/ui/Filters';
import { Card, EmptyPanel, ErrorPanel, PackageNotice, Skeleton, Tabs } from '@/Components/ui/Page';
import ProductPicker, { type PickedProduct } from '@/Components/ProductPicker';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch, idempotencyKey } from '@/lib/adminApi';
import { formatDateTime } from '@/lib/datetime';
import { variantLabel } from '@/lib/catalog';

/**
 * Phase P4a — Products → Stock planning (Business and Premium; Module 08
 * §71). What to order again, worked out from real sales; stock received from
 * suppliers; stock sent between locations. Quantities only: the platform
 * keeps no stock costing.
 */
type Warehouse = { id: string; internal_id: number; name: string; is_default: boolean };
export type PlanRow = {
  product_id: number; product_variant_id: number | null; product: string; variant: string | null; sku: string | null;
  available: number; incoming: number; sold: number; per_day: number; days_left: number | null; reorder_point: number | null; suggested: number;
  state: 'out' | 'below_point' | 'running_low' | 'ok';
};
type Doc = { id: string; number: string; by: string | null; note: string | null; lines: { product: string | null; quantity: number }[] };
type Receipt = Doc & { warehouse: string | null; supplier: string; reference: string | null; received_at: string | null };
type Transfer = Doc & { status: 'in_transit' | 'received' | 'cancelled'; from: string | null; to: string | null; sent_at: string | null; closed_at: string | null };
type Line = { picked: PickedProduct; quantity: string };

const STATE: Record<PlanRow['state'], [string, Tone]> = {
  out: ['Out of stock', 'red'],
  below_point: ['At reorder point', 'amber'],
  running_low: ['Runs out soon', 'amber'],
  ok: ['Enough', 'green'],
};
const TRANSFER: Record<Transfer['status'], [string, Tone]> = { in_transit: ['On its way', 'blue'], received: ['Received', 'green'], cancelled: ['Cancelled', 'neutral'] };
const items = (lines: Doc['lines']) => lines.map((l) => `${l.quantity} × ${l.product ?? 'Removed product'}`).join(', ');

export default function Planning() {
  const access = useAccess();
  const included = access.feature('inventory.advanced') === true;
  const [filters, setFilters] = useUrlState({ tab: 'reorder' });

  return (
    <AdminPage title="Stock planning" description="What to order again, stock you receive from suppliers, and stock you move between locations.">
      {!included ? (
        <PackageNotice title="Stock planning is part of the Business and Premium packages" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
          See what to order again from your real sales, record stock received from suppliers, and move stock between your locations.
        </PackageNotice>
      ) : (
        <>
          <Tabs label="Stock planning" active={filters.tab} onChange={(tab) => setFilters({ tab })} tabs={[{ id: 'reorder', label: 'What to order' }, { id: 'receive', label: 'Received stock' }, { id: 'transfers', label: 'Transfers' }]} />
          {filters.tab === 'reorder' && <ReorderTab />}
          {filters.tab === 'receive' && <ReceiveTab canManage={access.can('inventory.adjust')} />}
          {filters.tab === 'transfers' && <TransfersTab canManage={access.can('inventory.adjust')} />}
        </>
      )}
    </AdminPage>
  );
}

function ReorderTab() {
  const [range, setRange] = useState({ sales_days: '30', cover_days: '30' });
  const state = useApi<{ data: PlanRow[] }>('/stock/reorder', range);
  const columns: Column<PlanRow>[] = [
    { key: 'product', header: 'Product', render: (r) => <span><span className="font-medium text-slate-900">{r.product}</span>{r.variant && <span className="text-slate-600"> — {r.variant}</span>}{r.sku && <span className="block text-xs text-slate-500">{r.sku}</span>}</span> },
    { key: 'state', header: 'State', priority: true, render: (r) => <Badge tone={STATE[r.state][1]}>{STATE[r.state][0]}</Badge> },
    { key: 'available', header: 'Available', align: 'right', render: (r) => <span className="tabular-nums">{r.available.toLocaleString()}{r.incoming > 0 && <span className="block text-xs text-slate-500">+{r.incoming} on the way</span>}</span> },
    { key: 'sold', header: `Sold (${range.sales_days} days)`, align: 'right', render: (r) => <span className="tabular-nums">{r.sold.toLocaleString()}<span className="block text-xs text-slate-500">{r.per_day} a day</span></span> },
    { key: 'left', header: 'Days left', align: 'right', render: (r) => <span className="tabular-nums">{r.days_left === null ? '—' : Math.floor(r.days_left).toLocaleString()}</span> },
    { key: 'suggested', header: 'Order', align: 'right', priority: true, render: (r) => <span className="text-base font-semibold tabular-nums text-brand-700">{r.suggested > 0 ? r.suggested.toLocaleString() : '—'}</span> },
  ];

  return (
    <div className="space-y-4">
      <FilterBar>
        <div className="w-52"><SelectField label="Sales of the last" value={range.sales_days} onChange={(v) => setRange({ ...range, sales_days: v })} options={['14', '30', '60', '90'].map((d) => ({ value: d, label: `${d} days` }))} /></div>
        <div className="w-52"><SelectField label="Have stock for" value={range.cover_days} onChange={(v) => setRange({ ...range, cover_days: v })} options={['14', '30', '45', '60', '90'].map((d) => ({ value: d, label: `${d} days` }))} /></div>
      </FilterBar>
      <DataTable
        caption="What to order"
        columns={columns}
        rows={state.data?.data ?? null}
        rowKey={(r) => `${r.product_id}:${r.product_variant_id ?? ''}`}
        loading={state.loading}
        error={state.error}
        onRetry={state.reload}
        empty={<EmptyPanel icon="box" title="Nothing to order" description="Every product has enough stock for the days you chose, at the rate it sells." />}
      />
      <Card title="How is this worked out?" icon="question">
        <ul className="list-disc space-y-1 pl-5 text-sm text-slate-600">
          <li><strong>A day’s sales</strong> = units sold in the chosen period (orders not cancelled) ÷ its days, over all your locations.</li>
          <li><strong>Days left</strong> = available (on hand, less what orders hold) ÷ a day’s sales. No sales: no rate, so “—”.</li>
          <li><strong>Order</strong> = enough for the days you want covered, less what is available and on its way — and at least the item’s own reorder quantity when it reached its reorder point.</li>
        </ul>
      </Card>
    </div>
  );
}

/** Products and quantities for a receipt or a transfer. */
function LinesEditor({ lines, setLines, errors }: { lines: Line[]; setLines: (l: Line[]) => void; errors: Record<string, string> }) {
  return (
    <div className="space-y-3">
      {lines.map((line, i) => (
        <div key={i} className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 p-3">
          <p className="min-w-0 flex-1 text-sm">
            <span className="font-medium">{line.picked.product.name}</span>
            {line.picked.variant && <span className="text-slate-600"> — {variantLabel(line.picked.variant)}</span>}
            {(errors[`lines.${i}.product_id`] || errors[`lines.${i}.product_variant_id`]) && <span role="alert" className="block text-xs font-medium text-red-700">{errors[`lines.${i}.product_id`] ?? errors[`lines.${i}.product_variant_id`]}</span>}
          </p>
          <div className="w-32"><TextField label="Quantity" type="number" min={1} value={line.quantity} onChange={(v) => setLines(lines.map((l, j) => (j === i ? { ...l, quantity: v } : l)))} error={errors[`lines.${i}.quantity`]} /></div>
          <Button size="sm" variant="ghost" onClick={() => setLines(lines.filter((_, j) => j !== i))}>Remove</Button>
        </div>
      ))}
      <ProductPicker label={lines.length ? 'Add another product' : 'Find a product'} onPick={(picked) => setLines([...lines, { picked, quantity: '1' }])} />
      {errors.lines && <p role="alert" className="text-xs font-medium text-red-700">{errors.lines}</p>}
    </div>
  );
}

const linesBody = (lines: Line[]) => lines.map((l) => ({ product_id: l.picked.product.internal_id, product_variant_id: l.picked.variant?.internal_id ?? null, quantity: Number(l.quantity) }));

function ReceiveDialog({ warehouses, onClose, onDone }: { warehouses: Warehouse[]; onClose: () => void; onDone: () => void }) {
  const form = useForm({ warehouse_id: String(warehouses.find((w) => w.is_default)?.internal_id ?? ''), supplier: '', reference: '', note: '' });
  const [lines, setLines] = useState<Line[]>([]);
  const [key] = useState(idempotencyKey);

  async function save(event: FormEvent) {
    event.preventDefault();
    const v = form.values;
    const body = { warehouse_id: Number(v.warehouse_id), supplier: v.supplier, reference: v.reference || null, note: v.note || null, lines: linesBody(lines), idempotency_key: key };
    const saved = await form.submit(() => adminFetch('/stock/receipts', { method: 'POST', body }), 'Stock received.');
    if (saved !== undefined) onDone();
  }

  return (
    <Dialog open wide title="Receive stock" description="Stock that arrived from a supplier. It is added to the location you choose." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Supplier" value={form.values.supplier} onChange={(v) => form.set('supplier', v)} error={form.errors.supplier} maxLength={120} required data-autofocus />
          <TextField label="Delivery note or invoice no." optional value={form.values.reference} onChange={(v) => form.set('reference', v)} error={form.errors.reference} maxLength={80} />
          <SelectField label="Received at" value={form.values.warehouse_id} onChange={(v) => form.set('warehouse_id', v)} error={form.errors.warehouse_id} options={warehouses.map((w) => ({ value: String(w.internal_id), label: w.name }))} required />
        </div>
        <LinesEditor lines={lines} setLines={setLines} errors={form.errors} />
        <TextAreaField label="Note" optional value={form.values.note} onChange={(v) => form.set('note', v)} rows={2} maxLength={500} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={lines.length === 0 || form.values.supplier.trim() === ''}>Receive {lines.reduce((n, l) => n + (Number(l.quantity) || 0), 0)} units</Button>
        </div>
      </form>
    </Dialog>
  );
}

function ReceiveTab({ canManage }: { canManage: boolean }) {
  const receipts = useApi<{ data: Receipt[] }>('/stock/receipts');
  const warehouses = useApi<{ data: Warehouse[] }>('/warehouses');
  const [open, setOpen] = useState(false);
  const columns: Column<Receipt>[] = [
    { key: 'number', header: 'Receipt', render: (r) => <span><span className="font-medium text-slate-900">{r.number}</span><span className="block text-xs text-slate-500">{r.received_at ? formatDateTime(r.received_at) : ''}{r.by ? ` · ${r.by}` : ''}</span></span> },
    { key: 'supplier', header: 'Supplier', priority: true, render: (r) => <span>{r.supplier}{r.reference && <span className="block text-xs text-slate-500">{r.reference}</span>}</span> },
    { key: 'where', header: 'At', render: (r) => r.warehouse ?? '—' },
    { key: 'items', header: 'Items', priority: true, render: (r) => <span className="text-sm">{items(r.lines)}</span> },
  ];

  return (
    <div className="space-y-4">
      {canManage && <div className="flex justify-end"><Button variant="primary" onClick={() => setOpen(true)} disabled={!warehouses.data}>Receive stock</Button></div>}
      <DataTable caption="Received stock" columns={columns} rows={receipts.data?.data ?? null} rowKey={(r) => r.id} loading={receipts.loading} error={receipts.error} onRetry={receipts.reload}
        empty={<EmptyPanel icon="truck" title="No stock received yet" description="When a supplier delivers, record it here: the stock is added and the delivery is kept on record." />} />
      {open && warehouses.data && <ReceiveDialog warehouses={warehouses.data.data} onClose={() => setOpen(false)} onDone={() => { setOpen(false); receipts.reload(); }} />}
    </div>
  );
}

function SendDialog({ warehouses, onClose, onDone }: { warehouses: Warehouse[]; onClose: () => void; onDone: () => void }) {
  const first = warehouses.find((w) => w.is_default) ?? warehouses[0];
  const form = useForm({ from: String(first?.internal_id ?? ''), to: String(warehouses.find((w) => w.id !== first?.id)?.internal_id ?? ''), note: '' });
  const [lines, setLines] = useState<Line[]>([]);
  const [key] = useState(idempotencyKey);

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = { from_warehouse_id: Number(form.values.from), to_warehouse_id: Number(form.values.to), note: form.values.note || null, lines: linesBody(lines), idempotency_key: key };
    const saved = await form.submit(() => adminFetch('/stock/transfers', { method: 'POST', body }), 'Transfer sent.');
    if (saved !== undefined) onDone();
  }
  const options = warehouses.map((w) => ({ value: String(w.internal_id), label: w.name }));

  return (
    <Dialog open wide title="Send stock" description="The stock leaves the first location now and shows as on its way at the second until you mark it received." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField label="From" value={form.values.from} onChange={(v) => form.set('from', v)} error={form.errors.from_warehouse_id} options={options} required />
          <SelectField label="To" value={form.values.to} onChange={(v) => form.set('to', v)} error={form.errors.to_warehouse_id} options={options} required />
        </div>
        <LinesEditor lines={lines} setLines={setLines} errors={form.errors} />
        <TextAreaField label="Note" optional value={form.values.note} onChange={(v) => form.set('note', v)} rows={2} maxLength={500} hint="For example: who carries it." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Sending…" disabled={lines.length === 0 || form.values.from === form.values.to}>Send</Button>
        </div>
      </form>
    </Dialog>
  );
}

function TransfersTab({ canManage }: { canManage: boolean }) {
  const transfers = useApi<{ data: Transfer[] }>('/stock/transfers');
  const warehouses = useApi<{ data: Warehouse[] }>('/warehouses');
  const { busy, run } = useAction();
  const [open, setOpen] = useState(false);
  const [cancelling, setCancelling] = useState<Transfer | null>(null);
  const list = warehouses.data?.data ?? [];
  const columns: Column<Transfer>[] = [
    { key: 'number', header: 'Transfer', render: (t) => <span><span className="font-medium text-slate-900">{t.number}</span><span className="block text-xs text-slate-500">{t.sent_at ? formatDateTime(t.sent_at) : ''}{t.by ? ` · ${t.by}` : ''}</span></span> },
    { key: 'route', header: 'From → to', priority: true, render: (t) => `${t.from ?? '—'} → ${t.to ?? '—'}` },
    { key: 'items', header: 'Items', render: (t) => <span className="text-sm">{items(t.lines)}</span> },
    { key: 'status', header: 'State', priority: true, render: (t) => <Badge tone={TRANSFER[t.status][1]}>{TRANSFER[t.status][0]}</Badge> },
    {
      key: 'actions', header: '', align: 'right',
      render: (t) => canManage && t.status === 'in_transit' ? (
        <span className="flex justify-end gap-2">
          <Button size="sm" variant="primary" busy={busy === t.id} onClick={() => run(t.id, () => adminFetch(`/stock/transfers/${t.id}/receive`, { method: 'POST' }), { success: `${t.number} received.` }).then((r) => r && transfers.reload())}>Mark received</Button>
          <Button size="sm" onClick={() => setCancelling(t)}>Cancel</Button>
        </span>
      ) : null,
    },
  ];

  return (
    <div className="space-y-4">
      {canManage && (
        <div className="flex flex-wrap items-center justify-end gap-3">
          {warehouses.data && list.length < 2 && <span className="text-sm text-slate-600">Add a second stock location to move stock between them.</span>}
          <Button variant="primary" onClick={() => setOpen(true)} disabled={list.length < 2}>Send stock</Button>
        </div>
      )}
      {transfers.error ? <ErrorPanel message={transfers.error} onRetry={transfers.reload} /> : transfers.data === null ? <Skeleton lines={4} /> : (
        <DataTable caption="Transfers" columns={columns} rows={transfers.data.data} rowKey={(t) => t.id}
          empty={<EmptyPanel icon="truck" title="No transfers yet" description="Move stock from one location to another; it shows as on its way until it arrives." />} />
      )}
      {open && <SendDialog warehouses={list} onClose={() => setOpen(false)} onDone={() => { setOpen(false); transfers.reload(); }} />}
      <ConfirmDialog
        open={cancelling !== null}
        title={`Cancel ${cancelling?.number ?? ''}?`}
        confirmLabel="Cancel transfer"
        busy={busy === 'cancel'}
        onClose={() => setCancelling(null)}
        onConfirm={() => cancelling && run('cancel', () => adminFetch(`/stock/transfers/${cancelling.id}/cancel`, { method: 'POST' }), { success: 'Transfer cancelled.' }).then((r) => { setCancelling(null); if (r) transfers.reload(); })}
      >
        The stock goes back to {cancelling?.from}. Use this when it never left or came back.
      </ConfirmDialog>
    </div>
  );
}
