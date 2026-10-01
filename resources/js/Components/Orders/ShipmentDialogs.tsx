import { FormEvent, useState } from 'react';
import Dialog from '@/Components/ui/Dialog';
import Button from '@/Components/ui/Button';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { Details, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch, idempotencyKey } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { dateTimeOrDash, formatDateTime } from '@/lib/datetime';
import { options } from '@/lib/labels';
import { CARRIER_LABELS, SHIPMENT_STATUS_UPDATES, type Order, type Shipment } from '@/lib/orders';

/**
 * Module 13 §75 "Admin Shipping View": a shipment with its tracking, a
 * status update, and creating a shipment for an order. The server's
 * shipment state machine decides which status may follow which; an
 * invalid step comes back as its message. The carriers are the ones the
 * backend has; no courier is connected yet and none is pretended.
 */
export function ShipmentDrawer({ shipmentId, onClose, onChanged }: { shipmentId: string; onClose: () => void; onChanged: () => void }) {
  const access = useAccess();
  const state = useApi<{ data: Shipment }>(`/shipments/${shipmentId}`);
  const form = useForm({ status: '', description: '' });
  const shipment = state.data?.data;

  async function update(event: FormEvent) {
    event.preventDefault();
    const saved = await form.submit(
      () => adminFetch(`/shipments/${shipmentId}/status`, { method: 'POST', body: { status: form.values.status, description: form.values.description === '' ? null : form.values.description } }),
      'Shipment updated.',
    );
    if (saved !== undefined) {
      form.reset({ status: '', description: '' });
      state.reload();
      onChanged();
    }
  }

  return (
    <Dialog open side title="Shipment" description={shipment?.order ? `Order ${shipment.order.order_number}` : undefined} onClose={onClose} busy={form.busy}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : !shipment ? (
        <Skeleton lines={4} />
      ) : (
        <div className="space-y-5">
          <Details
            items={[
              { label: 'Status', value: <StatusBadge status={shipment.status} /> },
              { label: 'Carrier', value: CARRIER_LABELS[shipment.carrier] ?? humanize(shipment.carrier) },
              { label: 'Tracking number', value: shipment.tracking_number ?? '—' },
              { label: 'Shipping cost', value: shipment.shipping_cost_minor === null ? '—' : money(shipment.shipping_cost_minor, shipment.currency) },
              { label: 'Shipped', value: dateTimeOrDash(shipment.shipped_at) },
              { label: 'Delivered', value: dateTimeOrDash(shipment.delivered_at) },
            ]}
          />

          <div>
            <h3 className="mb-2 text-sm font-semibold text-slate-900">Items</h3>
            <ul className="divide-y divide-slate-100 rounded-md border border-slate-200 text-sm">
              {(shipment.items ?? []).map((item) => (
                <li key={item.order_item_id} className="flex justify-between gap-3 px-3 py-2">
                  <span>{item.product_name}</span>
                  <span className="text-slate-600">× {item.quantity}</span>
                </li>
              ))}
            </ul>
          </div>

          {access.can('shipments.fulfill') && (
            <form onSubmit={update} className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4" noValidate>
              <h3 className="text-sm font-semibold text-slate-900">Update status</h3>
              <FormError message={form.formError} />
              <SelectField label="New status" value={form.values.status} onChange={(v) => form.set('status', v)} error={form.errors.status} placeholder="Choose a status" options={options(SHIPMENT_STATUS_UPDATES)} required />
              <TextField label="Note" optional value={form.values.description} onChange={(v) => form.set('description', v)} error={form.errors.description} maxLength={500} hint="Shown in the tracking history." />
              <div className="flex justify-end">
                <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={form.values.status === ''}>Update shipment</Button>
              </div>
            </form>
          )}

          <div>
            <h3 className="mb-2 text-sm font-semibold text-slate-900">Tracking history</h3>
            {(shipment.tracking_events ?? []).length === 0 ? (
              <p className="text-sm text-slate-600">No tracking events yet.</p>
            ) : (
              <ol className="space-y-2 text-sm">
                {(shipment.tracking_events ?? []).map((event, index) => (
                  <li key={`${event.occurred_at}-${index}`} className="rounded-md border border-slate-200 p-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <StatusBadge status={event.status} />
                      <span className="text-xs text-slate-500">{formatDateTime(event.occurred_at)}</span>
                    </div>
                    {(event.description || event.location) && <p className="mt-1 text-slate-700">{[event.description, event.location].filter(Boolean).join(' · ')}</p>}
                  </li>
                ))}
              </ol>
            )}
          </div>
        </div>
      )}
    </Dialog>
  );
}

type Warehouse = { id: string; internal_id: number; name: string; is_default: boolean };

export function CreateShipmentDialog({ order, onClose, onDone }: { order: Order; onClose: () => void; onDone: () => void }) {
  const warehouses = useApi<{ data: Warehouse[] }>('/warehouses');
  const items = order.items ?? [];
  const form = useForm({ warehouse_id: '', carrier: 'local_delivery' });
  const [quantities, setQuantities] = useState<Record<number, string>>(() => Object.fromEntries(items.map((item) => [item.id, String(item.quantity)])));
  const [key] = useState(idempotencyKey);
  const list = warehouses.data?.data ?? [];

  async function save(event: FormEvent) {
    event.preventDefault();
    const lines = items.map((item) => ({ order_item_id: item.id, quantity: Number(quantities[item.id] ?? 0) })).filter((line) => Number.isInteger(line.quantity) && line.quantity > 0);
    if (lines.length === 0) {
      form.setFormError('Enter a quantity for at least one item.');

      return;
    }
    const body = { order_id: order.id, warehouse_id: form.values.warehouse_id === '' ? null : Number(form.values.warehouse_id), carrier: form.values.carrier, items: lines, idempotency_key: key };
    const saved = await form.submit(() => adminFetch('/shipments', { method: 'POST', body }), 'Shipment created.');
    if (saved !== undefined) onDone();
  }

  return (
    <Dialog open wide title="Create shipment" description={`Order ${order.order_number}. The server checks that no more is shipped than was ordered.`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField
            label="Ship from"
            value={form.values.warehouse_id}
            onChange={(v) => form.set('warehouse_id', v)}
            error={form.errors.warehouse_id ?? (warehouses.error ? 'Warehouses could not be loaded.' : undefined)}
            placeholder="Choose a warehouse"
            options={list.map((warehouse) => ({ value: String(warehouse.internal_id), label: warehouse.is_default ? `${warehouse.name} (default)` : warehouse.name }))}
            required
          />
          <SelectField label="Carrier" value={form.values.carrier} onChange={(v) => form.set('carrier', v)} error={form.errors.carrier} options={Object.entries(CARRIER_LABELS).map(([value, label]) => ({ value, label }))} />
        </div>
        <fieldset>
          <legend className="text-sm font-medium text-slate-700">Items in this shipment</legend>
          <ul className="mt-2 space-y-2">
            {items.map((item) => (
              <li key={item.id} className="grid grid-cols-[1fr_6rem] items-end gap-3">
                <span className="text-sm">
                  {item.product_name} <span className="text-slate-500">(ordered {item.quantity})</span>
                </span>
                <TextField label="Quantity" type="number" min={0} max={item.quantity} value={quantities[item.id] ?? ''} onChange={(v) => setQuantities((current) => ({ ...current, [item.id]: v }))} />
              </li>
            ))}
          </ul>
          {form.errors.items && <p role="alert" className="mt-1 text-xs font-medium text-red-700">{form.errors.items}</p>}
        </fieldset>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Creating…">Create shipment</Button>
        </div>
      </form>
    </Dialog>
  );
}
