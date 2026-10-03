import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink } from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import { FilterBar } from '@/Components/ui/Filters';
import Badge, { humanize } from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import ProductPicker, { type PickedProduct } from '@/Components/ProductPicker';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { useForm } from '@/lib/useForm';
import { adminFetch, idempotencyKey } from '@/lib/adminApi';
import { formatDateTime } from '@/lib/datetime';
import { variantLabel } from '@/lib/catalog';

/**
 * Module 08 "Inventory": stock per product and warehouse
 * (/api/v1/inventory). Every balance on this page is the server's; the
 * page never adds or subtracts stock itself. An adjustment is sent to
 * the server, which records the movement and returns the new balance.
 *
 * No stock value is shown: the platform keeps no inventory costing
 * (owner decision 2026-09-30).
 */
type StockRow = {
  id: string;
  warehouse?: { id: string; name: string } | null;
  product?: { id: string; name: string; sku: string | null } | null;
  variant?: { id: string; sku: string | null; option_values: Record<string, string> | null } | null;
  on_hand: number;
  reserved: number;
  available: number;
  incoming: number;
  /** Module 08 §47: counted apart from on hand; never sellable. */
  damaged?: number;
  reorder_point: number | null;
  is_low_stock: boolean;
  is_out_of_stock: boolean;
};

type Movement = { type: string; quantity: number; previous_on_hand: number; new_on_hand: number; reason: string | null; created_at: string };
type Warehouse = { id: string; internal_id: number; name: string; status: string; is_default: boolean };

const FILTER_DEFAULTS = { stock: '', page: '1' };

function itemName(row: StockRow): string {
  const product = row.product?.name ?? 'Product';

  return row.variant ? `${product} — ${variantLabel(row.variant)}` : product;
}

function StockState({ row }: { row: StockRow }) {
  if (row.is_out_of_stock) return <Badge tone="red">Out of stock</Badge>;
  if (row.is_low_stock) return <Badge tone="amber">Low stock</Badge>;

  return <Badge tone="green">In stock</Badge>;
}

type DialogMode = 'adjust' | 'opening' | 'damage' | 'writeoff';

/** What each stock dialog does: its API path, its words, and what counts as a valid quantity. */
const MODES: Record<DialogMode, { path: string; title: string; label: string; hint?: string; success: string; valid: (quantity: number) => boolean; invalid: string }> = {
  adjust: { path: 'adjust', title: 'Adjust stock', label: 'Change in quantity', hint: 'For example 5 to add five, -2 to remove two.', success: 'Stock adjusted.', valid: (q) => q !== 0, invalid: 'Enter a whole number other than 0. Use a minus sign to take stock away.' },
  opening: { path: 'opening-stock', title: 'Set opening stock', label: 'Quantity on hand', success: 'Opening stock recorded.', valid: (q) => q >= 0, invalid: 'Enter a whole number, 0 or more.' },
  // Module 08 §47 (Phase B34): damaged units are counted apart and can never be sold.
  damage: { path: 'damaged', title: 'Mark stock as damaged', label: 'Damaged units', hint: 'They leave the stock you can sell and are counted as damaged.', success: 'Marked as damaged.', valid: (q) => q > 0, invalid: 'Enter a whole number of 1 or more.' },
  writeoff: { path: 'damaged/write-off', title: 'Write off damaged stock', label: 'Units to write off', hint: 'For damaged units that were thrown away or sent back to the supplier.', success: 'Damaged stock written off.', valid: (q) => q > 0, invalid: 'Enter a whole number of 1 or more.' },
};

function AdjustDialog({ row, mode, onClose, onDone }: { row: StockRow; mode: DialogMode; onClose: () => void; onDone: () => void }) {
  const form = useForm({ quantity: '', reason: '' });
  // One key per opened dialog: pressing Save twice records one movement.
  const [key] = useState(idempotencyKey);
  const [local, setLocal] = useState<string | null>(null);
  const words = MODES[mode];

  async function save(event: FormEvent) {
    event.preventDefault();
    const quantity = Number(form.values.quantity);
    if (form.values.quantity.trim() === '' || !Number.isInteger(quantity) || !words.valid(quantity)) {
      setLocal(words.invalid);

      return;
    }
    setLocal(null);
    const saved = await form.submit(
      () => adminFetch(`/inventory/${row.id}/${words.path}`, { method: 'POST', body: { quantity, reason: form.values.reason, idempotency_key: key } }),
      words.success,
    );
    if (saved !== undefined) onDone();
  }

  return (
    <Dialog
      open
      title={words.title}
      description={
        <>
          {itemName(row)} at {row.warehouse?.name ?? 'warehouse'}. On hand now: {row.on_hand}{mode === 'damage' ? `, of which ${row.available} can be marked (the rest is reserved for orders)` : ''}{mode === 'writeoff' ? `; damaged: ${row.damaged ?? 0}` : ''}.
          {mode === 'opening' && ' Opening stock can be set once per stock record.'}
        </>
      }
      onClose={onClose}
      busy={form.busy}
    >
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField
          label={words.label}
          inputMode="numeric"
          value={form.values.quantity}
          onChange={(v) => form.set('quantity', v)}
          error={local ?? form.errors.quantity}
          hint={words.hint}
          required
          data-autofocus
        />
        <TextField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={255} required hint="Kept in the movement history." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save</Button>
        </div>
      </form>
    </Dialog>
  );
}

function MovementsDrawer({ row, onClose }: { row: StockRow; onClose: () => void }) {
  const [page, setPage] = useState(1);
  const list = usePagedApi<Movement>(`/inventory/${row.id}/movements`, { page });
  const columns: Column<Movement>[] = [
    { key: 'when', header: 'When', render: (m) => formatDateTime(m.created_at) },
    { key: 'type', header: 'Type', priority: true, render: (m) => humanize(m.type) },
    { key: 'quantity', header: 'Change', align: 'right', priority: true, render: (m) => (m.quantity > 0 ? `+${m.quantity}` : m.quantity) },
    { key: 'balance', header: 'On hand after', align: 'right', render: (m) => m.new_on_hand },
    { key: 'reason', header: 'Reason', render: (m) => m.reason ?? '—' },
  ];

  return (
    <Dialog open side title="Stock movements" description={`${itemName(row)} at ${row.warehouse?.name ?? 'warehouse'}`} onClose={onClose}>
      <DataTable
        caption="Stock movements"
        columns={columns}
        rows={list.rows}
        rowKey={(m) => `${m.created_at}-${m.type}-${m.new_on_hand}-${m.quantity}`}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<p className="text-sm text-slate-600">No movements yet.</p>}
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />
    </Dialog>
  );
}

function AddStockDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const warehouses = useApi<{ data: Warehouse[] }>('/warehouses');
  const [picked, setPicked] = useState<PickedProduct | null>(null);
  const form = useForm({ warehouse_id: '', reorder_point: '', reorder_quantity: '' });
  const list = warehouses.data?.data ?? [];

  async function save(event: FormEvent) {
    event.preventDefault();
    if (picked === null) {
      form.setFormError('Choose a product first.');

      return;
    }
    const v = form.values;
    const body = {
      warehouse_id: v.warehouse_id === '' ? null : Number(v.warehouse_id),
      product_id: picked.variant === null ? picked.product.internal_id : null,
      product_variant_id: picked.variant?.internal_id ?? null,
      reorder_point: v.reorder_point === '' ? null : Number(v.reorder_point),
      reorder_quantity: v.reorder_quantity === '' ? null : Number(v.reorder_quantity),
    };
    const saved = await form.submit(() => adminFetch('/inventory', { method: 'POST', body }), 'Stock record ready.');
    if (saved !== undefined) onDone();
  }

  return (
    <Dialog open wide title="Track stock for a product" description="Creates the stock record for one product (or variant) in one warehouse. If it already exists, nothing is duplicated." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        {picked === null ? (
          <ProductPicker onPick={setPicked} />
        ) : (
          <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
            <span>
              <span className="font-medium">{picked.product.name}</span>
              {picked.variant && <span className="text-slate-600"> — {variantLabel(picked.variant)}</span>}
            </span>
            <Button size="sm" onClick={() => setPicked(null)}>Change</Button>
          </div>
        )}
        {(form.errors.product_id || form.errors.product_variant_id) && <p role="alert" className="text-xs font-medium text-red-700">{form.errors.product_id ?? form.errors.product_variant_id}</p>}
        <SelectField
          label="Warehouse"
          value={form.values.warehouse_id}
          onChange={(v) => form.set('warehouse_id', v)}
          error={form.errors.warehouse_id ?? (warehouses.error ? 'Warehouses could not be loaded.' : undefined)}
          placeholder="Choose a warehouse"
          options={list.map((warehouse) => ({ value: String(warehouse.internal_id), label: warehouse.is_default ? `${warehouse.name} (default)` : warehouse.name }))}
          required
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Reorder point" optional type="number" min={0} value={form.values.reorder_point} onChange={(v) => form.set('reorder_point', v)} error={form.errors.reorder_point} hint="At or under this, the item counts as low on stock." />
          <TextField label="Reorder quantity" optional type="number" min={0} value={form.values.reorder_quantity} onChange={(v) => form.set('reorder_quantity', v)} error={form.errors.reorder_quantity} />
        </div>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Track stock</Button>
        </div>
      </form>
    </Dialog>
  );
}

export default function Index() {
  const access = useAccess();
  const canAdjust = access.can('inventory.adjust');
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const list = usePagedApi<StockRow>('/inventory', { stock: filters.stock, page: filters.page });
  const [dialog, setDialog] = useState<{ row: StockRow; mode: DialogMode | 'movements' } | null>(null);
  const [adding, setAdding] = useState(false);

  const columns: Column<StockRow>[] = [
    {
      key: 'item',
      header: 'Item',
      render: (row) => (
        <div>
          <span className="font-medium">{row.product?.name ?? '—'}</span>
          {row.variant && <p className="text-xs text-slate-600">{variantLabel(row.variant)}</p>}
          <p className="text-xs text-slate-500">{row.warehouse?.name}</p>
        </div>
      ),
    },
    { key: 'on_hand', header: 'On hand', align: 'right', render: (row) => row.on_hand },
    { key: 'reserved', header: 'Reserved', align: 'right', render: (row) => row.reserved },
    { key: 'available', header: 'Available', align: 'right', priority: true, render: (row) => <span className="font-medium">{row.available}</span> },
    { key: 'damaged', header: 'Damaged', align: 'right', render: (row) => ((row.damaged ?? 0) > 0 ? <span className="font-medium text-red-800">{row.damaged}</span> : 0) },
    { key: 'reorder', header: 'Reorder point', align: 'right', render: (row) => row.reorder_point ?? '—' },
    { key: 'state', header: 'Status', priority: true, render: (row) => <StockState row={row} /> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (row) => (
        <span className="flex flex-wrap justify-end gap-1">
          {canAdjust && <Button size="sm" variant="ghost" onClick={() => setDialog({ row, mode: 'adjust' })}>Adjust<span className="sr-only"> {itemName(row)}</span></Button>}
          {canAdjust && row.on_hand === 0 && <Button size="sm" variant="ghost" onClick={() => setDialog({ row, mode: 'opening' })}>Opening stock</Button>}
          {canAdjust && row.available > 0 && <Button size="sm" variant="ghost" onClick={() => setDialog({ row, mode: 'damage' })}>Mark damaged<span className="sr-only"> {itemName(row)}</span></Button>}
          {canAdjust && (row.damaged ?? 0) > 0 && <Button size="sm" variant="ghost" onClick={() => setDialog({ row, mode: 'writeoff' })}>Write off<span className="sr-only"> damaged {itemName(row)}</span></Button>}
          <Button size="sm" variant="ghost" onClick={() => setDialog({ row, mode: 'movements' })}>History<span className="sr-only"> of {itemName(row)}</span></Button>
        </span>
      ),
    },
  ];

  return (
    <AdminPage
      title="Inventory"
      description="Stock per product and warehouse. Available is on hand minus reserved. Damaged units are counted apart and are never sold."
      actions={
        <>
          <ButtonLink href="/warehouses">Warehouses</ButtonLink>
          {canAdjust && <Button variant="primary" onClick={() => setAdding(true)}>Track a product</Button>}
        </>
      }
    >
      <FilterBar>
        <div className="w-52">
          <SelectField
            label="Show"
            value={filters.stock}
            onChange={(stock) => setFilters({ stock, page: '1' })}
            placeholder="All stock"
            options={[{ value: 'low', label: 'Low on stock' }, { value: 'out', label: 'Out of stock' }]}
          />
        </div>
      </FilterBar>

      <DataTable
        caption="Stock levels"
        columns={columns}
        rows={list.rows}
        rowKey={(row) => row.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={
          filters.stock !== '' ? (
            <EmptyPanel title={filters.stock === 'low' ? 'Nothing is low on stock' : 'Nothing is out of stock'} action={<Button onClick={() => setFilters(FILTER_DEFAULTS)}>Show all stock</Button>} />
          ) : (
            <EmptyPanel title="No stock is tracked yet" description="Choose a product and a warehouse to start tracking its stock." action={canAdjust ? <Button variant="primary" onClick={() => setAdding(true)}>Track a product</Button> : undefined} />
          )
        }
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />

      {dialog && dialog.mode !== 'movements' && (
        <AdjustDialog
          row={dialog.row}
          mode={dialog.mode}
          onClose={() => setDialog(null)}
          onDone={() => {
            setDialog(null);
            list.reload();
          }}
        />
      )}
      {dialog && dialog.mode === 'movements' && <MovementsDrawer row={dialog.row} onClose={() => setDialog(null)} />}
      {adding && (
        <AddStockDialog
          onClose={() => setAdding(false)}
          onDone={() => {
            setAdding(false);
            list.reload();
          }}
        />
      )}
    </AdminPage>
  );
}
