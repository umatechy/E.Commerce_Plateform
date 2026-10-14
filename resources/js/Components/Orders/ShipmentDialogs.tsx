import { FormEvent, useState } from 'react';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import Button from '@/Components/ui/Button';
import Badge, { StatusBadge, humanize } from '@/Components/ui/Badge';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import { Details, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch, idempotencyKey } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { dateTimeOrDash, formatDateTime } from '@/lib/datetime';
import { options } from '@/lib/labels';
import { CARRIER_LABELS, SHIPMENT_STATUS_UPDATES, type Order, type Shipment } from '@/lib/orders';

/**
 * Module 13 §75 "Admin Shipping View": a shipment with its tracking, and
 * creating a shipment for an order. The server decides every step (the
 * shipment state machine, the courier's answers, the cash to collect).
 *
 * Phase B49: couriers. A connected courier books the parcel, prints its
 * label and reports tracking by itself; with another courier the store
 * books in the courier's portal and types the number. Labels are printed
 * for every parcel. A shipment cancelled before pickup puts its units back.
 */
const COD_LABELS: Record<string, { label: string; tone: 'neutral' | 'amber' | 'green' }> = {
  pending: { label: 'To collect on delivery', tone: 'neutral' },
  to_remit: { label: 'Collected — the courier owes you', tone: 'amber' },
  remitted: { label: 'Received by the store', tone: 'green' },
};

export function weightText(grams: number | null | undefined): string {
  if (!grams) return '—';

  return `${Number((grams / 1000).toFixed(3))} kg`;
}

export function ShipmentDrawer({ shipmentId, onClose, onChanged }: { shipmentId: string; onClose: () => void; onChanged: () => void }) {
  const access = useAccess();
  const state = useApi<{ data: Shipment }>(`/shipments/${shipmentId}`);
  const form = useForm({ status: '', description: '' });
  const tracking = useForm({ carrier_name: '', tracking_number: '' });
  const { busy, run } = useAction();
  const [confirm, setConfirm] = useState<'cancel' | 'book' | null>(null);
  const shipment = state.data?.data;
  const canManage = access.can('shipments.fulfill');

  function refreshAll() {
    state.reload();
    onChanged();
  }

  async function update(event: FormEvent) {
    event.preventDefault();
    const saved = await form.submit(
      () => adminFetch(`/shipments/${shipmentId}/status`, { method: 'POST', body: { status: form.values.status, description: form.values.description === '' ? null : form.values.description } }),
      'Shipment updated.',
    );
    if (saved !== undefined) {
      form.reset({ status: '', description: '' });
      refreshAll();
    }
  }

  async function saveTracking(event: FormEvent) {
    event.preventDefault();
    const body = { carrier_name: tracking.values.carrier_name || shipment?.carrier_name || '', tracking_number: tracking.values.tracking_number };
    if ((await tracking.submit(() => adminFetch(`/shipments/${shipmentId}/tracking`, { method: 'PUT', body }), 'Tracking number saved.')) !== undefined) {
      tracking.reset({ carrier_name: '', tracking_number: '' });
      refreshAll();
    }
  }

  const act = (key: string, path: string, success: string, body?: unknown) =>
    run(key, () => adminFetch(`/shipments/${shipmentId}/${path}`, { method: 'POST', body }), { success }).then((done) => {
      setConfirm(null);
      if (done !== undefined) refreshAll();
    });

  const courierManaged = shipment?.booking_status === 'booked';

  return (
    <Dialog open side title={shipment?.direction === 'return' ? 'Return parcel' : 'Shipment'} description={shipment?.order ? `Order ${shipment.order.order_number}` : undefined} onClose={onClose} busy={form.busy || busy !== null}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : !shipment ? (
        <Skeleton lines={4} />
      ) : (
        <div className="space-y-5">
          {shipment.booking_status === 'failed' && (
            <div role="alert" className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900">
              <p className="font-semibold">The courier did not take this parcel</p>
              <p className="mt-1">{shipment.booking_error}</p>
              {canManage && shipment.actions?.book && <Button size="sm" className="mt-2" busy={busy === 'book'} busyLabel="Booking…" onClick={() => act('book', 'book', 'Sent to the courier.')}>Book again</Button>}
            </div>
          )}
          {shipment.booking_status === 'unknown' && (
            <div role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
              <p className="font-semibold">No answer from the courier</p>
              <p className="mt-1">The parcel may or may not be booked. Look it up in the courier’s portal before booking again — booking twice could send two riders.</p>
              {canManage && <Button size="sm" className="mt-2" onClick={() => setConfirm('book')}>It is not booked — book again</Button>}
            </div>
          )}
          {(shipment.booking_status === 'booking' || shipment.booking_status === 'requesting') && <p role="status" className="rounded-md bg-slate-50 p-3 text-sm text-slate-700">Booking with the courier…</p>}

          <Details
            items={[
              { label: 'Status', value: <StatusBadge status={shipment.status} /> },
              { label: 'Carrier', value: shipment.carrier_label ?? CARRIER_LABELS[shipment.carrier] ?? humanize(shipment.carrier) },
              {
                label: 'Tracking number',
                value: shipment.tracking_number ? (
                  shipment.tracking_url ? <a href={shipment.tracking_url} target="_blank" rel="noreferrer noopener" className="font-mono text-indigo-700 hover:underline">{shipment.tracking_number}</a> : <span className="font-mono">{shipment.tracking_number}</span>
                ) : '—',
              },
              { label: 'Parcel', value: `${shipment.pieces ?? 1} piece${(shipment.pieces ?? 1) === 1 ? '' : 's'} · ${weightText(shipment.weight_grams)}` },
              { label: 'Shipped', value: dateTimeOrDash(shipment.shipped_at) },
              { label: 'Delivered', value: dateTimeOrDash(shipment.delivered_at) },
            ]}
          />

          {(shipment.cod_amount_minor ?? 0) > 0 && (
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 p-3 text-sm">
              <span>Cash on delivery <strong>{money(shipment.cod_amount_minor ?? 0, shipment.currency)}</strong></span>
              {shipment.cod_status && COD_LABELS[shipment.cod_status] && <Badge tone={COD_LABELS[shipment.cod_status].tone}>{COD_LABELS[shipment.cod_status].label}</Badge>}
              {shipment.cod_remittance_reference && <span className="w-full text-xs text-slate-500">Reference {shipment.cod_remittance_reference} · {dateTimeOrDash(shipment.cod_remitted_at ?? null)}</span>}
            </div>
          )}

          <div className="flex flex-wrap gap-2">
            {canManage && shipment.status !== 'cancelled' && (
              <a href={`/api/v1/shipments/${shipment.id}/label`} target="_blank" rel="noreferrer" className="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-50">
                Print label
              </a>
            )}
            {shipment.actions?.refresh && <Button size="sm" busy={busy === 'refresh'} busyLabel="Checking…" onClick={() => act('refresh', 'refresh', 'Tracking checked.')}>Check tracking now</Button>}
            {canManage && shipment.actions?.restock && <Button size="sm" variant="primary" busy={busy === 'restock'} busyLabel="Saving…" onClick={() => act('restock', 'restock', 'Back in stock.')}>Put back in stock</Button>}
            {canManage && shipment.actions?.cancel && <Button size="sm" variant="danger" onClick={() => setConfirm('cancel')}>Cancel shipment</Button>}
          </div>
          {shipment.last_polled_at && <p className="text-xs text-slate-500">Courier last asked {formatDateTime(shipment.last_polled_at)}. Updates arrive by themselves.</p>}

          {canManage && shipment.actions?.set_tracking && (
            <form onSubmit={saveTracking} className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4" noValidate>
              <h3 className="text-sm font-semibold text-slate-900">{shipment.tracking_number ? 'Change the tracking number' : 'Booked in the courier’s portal? Add its tracking number'}</h3>
              <FormError message={tracking.formError} />
              <div className="grid gap-3 sm:grid-cols-2">
                <TextField label="Courier" value={tracking.values.carrier_name} placeholder={shipment.carrier_name ?? 'e.g. Leopards'} onChange={(v) => tracking.set('carrier_name', v)} error={tracking.errors.carrier_name} maxLength={64} />
                <TextField label="Tracking number" value={tracking.values.tracking_number} onChange={(v) => tracking.set('tracking_number', v)} error={tracking.errors.tracking_number} maxLength={64} required />
              </div>
              <div className="flex justify-end">
                <Button type="submit" variant="primary" busy={tracking.busy} busyLabel="Saving…" disabled={tracking.values.tracking_number.trim() === ''}>Save tracking number</Button>
              </div>
            </form>
          )}

          {canManage && !courierManaged && !['delivered', 'cancelled', 'returned', 'lost'].includes(shipment.status) && (
            <form onSubmit={update} className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4" noValidate>
              <h3 className="text-sm font-semibold text-slate-900">Update status</h3>
              <FormError message={form.formError} />
              <SelectField label="New status" value={form.values.status} onChange={(v) => form.set('status', v)} error={form.errors.status} placeholder="Choose a status" options={options(SHIPMENT_STATUS_UPDATES.filter((s) => s !== 'cancelled'))} required />
              <TextField label="Note" optional value={form.values.description} onChange={(v) => form.set('description', v)} error={form.errors.description} maxLength={500} hint="Shown in the tracking history." />
              <div className="flex justify-end">
                <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={form.values.status === ''}>Update shipment</Button>
              </div>
            </form>
          )}

          {(shipment.items ?? []).length > 0 && (
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
          )}

          <div>
            <h3 className="mb-2 text-sm font-semibold text-slate-900">Tracking history</h3>
            {(shipment.tracking_events ?? []).length === 0 ? (
              <p className="text-sm text-slate-600">No tracking events yet.</p>
            ) : (
              <ol className="relative space-y-3 border-s border-slate-200 ps-4 text-sm">
                {[...(shipment.tracking_events ?? [])].sort((a, b) => b.occurred_at.localeCompare(a.occurred_at)).map((event, index) => (
                  <li key={`${event.occurred_at}-${index}`}>
                    <span aria-hidden="true" className={`absolute -start-1.5 mt-1 h-3 w-3 rounded-full border-2 border-white ${index === 0 ? 'bg-indigo-600' : 'bg-slate-300'}`} />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <StatusBadge status={event.status} />
                      <span className="text-xs text-slate-500">{formatDateTime(event.occurred_at)}{event.source ? ` · ${event.source === 'carrier' ? 'courier' : event.source}` : ''}</span>
                    </div>
                    {(event.description || event.location) && <p className="mt-1 text-slate-700">{[event.description, event.location].filter(Boolean).join(' · ')}</p>}
                  </li>
                ))}
              </ol>
            )}
          </div>
        </div>
      )}

      <ConfirmDialog
        open={confirm === 'cancel'}
        title="Cancel this shipment?"
        confirmLabel="Cancel shipment"
        busy={busy === 'cancel'}
        onClose={() => setConfirm(null)}
        onConfirm={() => act('cancel', 'cancel', 'Shipment cancelled.', { note: null })}
      >
        <p>{shipment?.booking_status === 'booked' ? 'The courier is asked to cancel the booking first. ' : ''}The items go back in stock and stay held for this order, so you can ship it again.</p>
      </ConfirmDialog>
      <ConfirmDialog
        open={confirm === 'book'}
        title="Book this parcel again?"
        confirmLabel="Book again"
        busy={busy === 'book'}
        onClose={() => setConfirm(null)}
        onConfirm={() => act('book', 'book', 'Sent to the courier.')}
      >
        <p>Only if the courier’s portal shows no booking for this order. Otherwise the parcel would be booked twice.</p>
      </ConfirmDialog>
    </Dialog>
  );
}

type Warehouse = { id: string; internal_id: number; name: string; is_default: boolean };
type CourierSettings = { default_weight_grams: number; couriers: { provider: string; label: string; account: { is_enabled: boolean } | null }[] };

export function CreateShipmentDialog({ order, onClose, onDone }: { order: Order; onClose: () => void; onDone: () => void }) {
  const warehouses = useApi<{ data: Warehouse[] }>('/warehouses');
  const courierSettings = useApi<{ data: CourierSettings }>('/courier-settings');
  const items = order.items ?? [];
  const form = useForm({ warehouse_id: '', carrier: 'local_delivery', carrier_name: '', tracking_number: '', weight: '', pieces: '1' });
  const [quantities, setQuantities] = useState<Record<number, string>>(() => Object.fromEntries(items.map((item) => [item.id, String(item.quantity)])));
  const [key] = useState(idempotencyKey);
  const list = warehouses.data?.data ?? [];
  const defaultWeight = courierSettings.data?.data.default_weight_grams ?? 500;
  const connected = (courierSettings.data?.data.couriers ?? []).filter((c) => c.account?.is_enabled);
  const choices = [
    { value: 'local_delivery', title: 'Own delivery', text: 'Your rider takes it. You update the status.' },
    { value: 'store_pickup', title: 'Store pickup', text: 'The customer collects it from you.' },
    ...connected.map((c) => ({ value: c.provider, title: c.label, text: 'Booked with the courier now; the label and tracking follow by themselves.' })),
    { value: 'manual_courier', title: 'Another courier', text: 'Book in the courier’s portal (Leopards, TCS, M&P …) and type its tracking number.' },
  ];
  const v = form.values;

  async function save(event: FormEvent) {
    event.preventDefault();
    const lines = items.map((item) => ({ order_item_id: item.id, quantity: Number(quantities[item.id] ?? 0) })).filter((line) => Number.isInteger(line.quantity) && line.quantity > 0);
    if (lines.length === 0) {
      form.setFormError('Enter a quantity for at least one item.');

      return;
    }
    const kg = v.weight.trim() === '' ? null : Number(v.weight.replace(',', '.'));
    const body = {
      order_id: order.id, warehouse_id: v.warehouse_id === '' ? null : Number(v.warehouse_id), carrier: v.carrier, items: lines, idempotency_key: key,
      weight_grams: kg !== null && Number.isFinite(kg) && kg > 0 ? Math.round(kg * 1000) : null, pieces: Number(v.pieces) || 1,
      ...(v.carrier === 'manual_courier' ? { carrier_name: v.carrier_name, tracking_number: v.tracking_number.trim() || null } : {}),
    };
    const saved = await form.submit(() => adminFetch<{ data: Shipment }>('/shipments', { method: 'POST', body }), 'Shipment created.');
    if (saved !== undefined) onDone();
  }

  return (
    <Dialog open wide title="Create shipment" description={`Order ${order.order_number}. Cash on delivery to collect is worked out from the order’s payment and printed on the label.`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-5" noValidate>
        <FormError message={form.formError} />
        <fieldset>
          <legend className="text-sm font-medium text-slate-700">How does it go?</legend>
          <div className="mt-2 grid gap-2 sm:grid-cols-2">
            {choices.map((choice) => (
              <label key={choice.value} className={`flex cursor-pointer gap-2 rounded-md border p-3 text-sm ${v.carrier === choice.value ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-slate-300'}`}>
                <input type="radio" name="carrier" value={choice.value} checked={v.carrier === choice.value} onChange={() => form.set('carrier', choice.value)} className="mt-0.5" />
                <span>
                  <span className="block font-medium">{choice.title}</span>
                  <span className="block text-xs text-slate-500">{choice.text}</span>
                </span>
              </label>
            ))}
          </div>
          {form.errors.carrier && <p role="alert" className="mt-1 text-xs font-medium text-red-700">{form.errors.carrier}</p>}
        </fieldset>
        {v.carrier === 'manual_courier' && (
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Courier" value={v.carrier_name} onChange={(x) => form.set('carrier_name', x)} error={form.errors.carrier_name} maxLength={64} placeholder="e.g. Leopards" required />
            <TextField label="Tracking number" optional value={v.tracking_number} onChange={(x) => form.set('tracking_number', x)} error={form.errors.tracking_number} maxLength={64} hint="You can add it later." />
          </div>
        )}
        <div className="grid gap-4 sm:grid-cols-3">
          <SelectField
            label="Ship from"
            value={v.warehouse_id}
            onChange={(x) => form.set('warehouse_id', x)}
            error={form.errors.warehouse_id ?? (warehouses.error ? 'Warehouses could not be loaded.' : undefined)}
            placeholder="Choose a warehouse"
            options={list.map((warehouse) => ({ value: String(warehouse.internal_id), label: warehouse.is_default ? `${warehouse.name} (default)` : warehouse.name }))}
            required
          />
          <TextField label="Weight (kg)" optional inputMode="decimal" value={v.weight} placeholder={String(defaultWeight / 1000)} onChange={(x) => form.set('weight', x)} error={form.errors.weight_grams} hint={`Empty: ${defaultWeight / 1000} kg`} />
          <TextField label="Pieces" type="number" min={1} max={99} value={v.pieces} onChange={(x) => form.set('pieces', x)} error={form.errors.pieces} />
        </div>
        <fieldset>
          <legend className="text-sm font-medium text-slate-700">Items in this shipment</legend>
          <ul className="mt-2 space-y-2">
            {items.map((item) => (
              <li key={item.id} className="grid grid-cols-[1fr_6rem] items-end gap-3">
                <span className="text-sm">
                  {item.product_name} <span className="text-slate-500">(ordered {item.quantity})</span>
                </span>
                <TextField label="Quantity" type="number" min={0} max={item.quantity} value={quantities[item.id] ?? ''} onChange={(x) => setQuantities((current) => ({ ...current, [item.id]: x }))} />
              </li>
            ))}
          </ul>
          {form.errors.items && <p role="alert" className="mt-1 text-xs font-medium text-red-700">{form.errors.items}</p>}
        </fieldset>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Creating…">{connected.some((c) => c.provider === v.carrier) ? 'Create and book' : 'Create shipment'}</Button>
        </div>
      </form>
    </Dialog>
  );
}
