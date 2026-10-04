import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink, FOCUS_RING } from '@/Components/ui/Button';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { StatusBadge } from '@/Components/ui/Badge';
import { AccessNotice, Card, Details, EmptyPanel, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import ProductPicker, { type PickedProduct } from '@/Components/ProductPicker';
import ReturnPhotos from '@/Components/Orders/ReturnPhotos';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { fromMinor, money, toMinor } from '@/lib/money';
import { formatDateTime } from '@/lib/datetime';
import { options } from '@/lib/labels';
import { PAYMENT_METHOD_LABELS } from '@/lib/orders';
import { variantLabel } from '@/lib/catalog';
import { METHOD_LABELS, REASON_LABELS, RESOLUTION_LABELS, RETURN_METHODS, RETURN_STATUS_LABELS, returnSteps, type ReturnRecord } from '@/lib/returns';

/**
 * Module 09 §45–54 (Phase B33, gap G8): one return, and the next step
 * the server allows for it. Which steps exist comes from the return's
 * state (`can`); who may take them from the permissions (approve,
 * manage and refund are separate). Every amount is the server's: this
 * page sends quantities and the two staff choices (shipping refund,
 * restocking fee), never a refund amount.
 */
type Done = (record: ReturnRecord) => void;
type Warehouse = { id: string; internal_id: number; name: string; is_default: boolean };

function post(id: string, step: string, body: Record<string, unknown> = {}) {
  return adminFetch<{ data: ReturnRecord }>(`/returns/${id}/${step}`, { method: 'POST', body });
}

function ApproveDialog({ record, onClose, onDone }: { record: ReturnRecord; onClose: () => void; onDone: Done }) {
  const form = useForm<{ note: string; return_method: string; return_shipping_paid_by: string }>({ note: '', return_method: record.return_method ?? 'customer_ships', return_shipping_paid_by: record.return_shipping_paid_by ?? 'customer' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const saved = await form.submit(() => post(record.id, 'approve', { ...form.values, note: form.values.note === '' ? null : form.values.note }), 'Return approved.');
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open title="Approve this return?" description="The customer is told by email and may send the items back. Nothing is refunded yet." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} errors={form.errors} />
        <SelectField label="How the items come back" value={form.values.return_method} onChange={(v) => form.set('return_method', v)} options={options(RETURN_METHODS, METHOD_LABELS)} />
        <SelectField label="Who pays the return shipping" value={form.values.return_shipping_paid_by} onChange={(v) => form.set('return_shipping_paid_by', v)} options={[{ value: 'customer', label: 'The customer' }, { value: 'store', label: 'The store' }]} />
        <TextAreaField label="Message to the customer" optional rows={3} value={form.values.note} onChange={(v) => form.set('note', v)} maxLength={1000} hint="For example where to send the parcel." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Approve return</Button>
        </div>
      </form>
    </Dialog>
  );
}

function NoteDialog({ record, step, title, description, button, required, success, onClose, onDone }: { record: ReturnRecord; step: 'reject' | 'cancel'; title: string; description: string; button: string; required: boolean; success: string; onClose: () => void; onDone: Done }) {
  const form = useForm({ note: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const saved = await form.submit(() => post(record.id, step, { note: form.values.note === '' ? null : form.values.note }), success);
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open title={title} description={description} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextAreaField label="Reason" optional={!required} rows={3} value={form.values.note} onChange={(v) => form.set('note', v)} error={form.errors.note} maxLength={1000} hint="The customer sees this." data-autofocus />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Back</Button>
          <Button type="submit" variant="danger" busy={form.busy} busyLabel="Saving…" disabled={required && form.values.note.trim() === ''}>{button}</Button>
        </div>
      </form>
    </Dialog>
  );
}

function InTransitDialog({ record, onClose, onDone }: { record: ReturnRecord; onClose: () => void; onDone: Done }) {
  const form = useForm({ carrier: '', tracking_number: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const saved = await form.submit(() => post(record.id, 'in-transit', { carrier: form.values.carrier || null, tracking_number: form.values.tracking_number || null }), 'Marked as on its way back.');
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open title="The items are on their way back" onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} errors={form.errors} />
        <TextField label="Courier" optional value={form.values.carrier} onChange={(v) => form.set('carrier', v)} maxLength={64} />
        <TextField label="Tracking number" optional value={form.values.tracking_number} onChange={(v) => form.set('tracking_number', v)} maxLength={128} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save</Button>
        </div>
      </form>
    </Dialog>
  );
}

function ReceiveDialog({ record, onClose, onDone }: { record: ReturnRecord; onClose: () => void; onDone: Done }) {
  const warehouses = useApi<{ data: Warehouse[] }>('/warehouses');
  const list = warehouses.data?.data ?? [];
  const form = useForm({ warehouse_id: '' });
  const chosen = form.values.warehouse_id !== '' ? form.values.warehouse_id : String(list.find((w) => w.is_default)?.internal_id ?? '');

  async function save(event: FormEvent) {
    event.preventDefault();
    const saved = await form.submit(() => post(record.id, 'receive', { warehouse_id: chosen === '' ? null : Number(chosen) }), 'Items received.');
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open title="The items have arrived" description="Stock does not change yet: that happens when you inspect them." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <SelectField label="Received at" value={chosen} onChange={(v) => form.set('warehouse_id', v)} error={form.errors.warehouse_id ?? (warehouses.error ? 'Warehouses could not be loaded.' : undefined)} options={list.map((w) => ({ value: String(w.internal_id), label: w.name }))} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={chosen === ''}>Mark as received</Button>
        </div>
      </form>
    </Dialog>
  );
}

function InspectDialog({ record, onClose, onDone }: { record: ReturnRecord; onClose: () => void; onDone: Done }) {
  const lines = record.items ?? [];
  const [rows, setRows] = useState(() => lines.map((line) => ({ id: line.order_item_id, resalable: String(line.quantity), damaged: '0', rejected: '0', note: '' })));
  const form = useForm({ note: '' });
  const set = (index: number, change: Partial<(typeof rows)[number]>) => setRows((current) => current.map((row, i) => (i === index ? { ...row, ...change } : row)));
  const number = (text: string) => (/^\d+$/.test(text.trim()) ? Number(text) : NaN);
  const sums = rows.map((row) => number(row.resalable) + number(row.damaged) + number(row.rejected));
  const valid = sums.every((sum, index) => sum === lines[index].quantity);
  const nothingAccepted = valid && rows.every((row) => number(row.resalable) + number(row.damaged) === 0);

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = {
      items: rows.map((row) => ({ order_item_id: row.id, resalable: Number(row.resalable), damaged: Number(row.damaged), rejected: Number(row.rejected), note: row.note === '' ? null : row.note })),
      note: form.values.note === '' ? null : form.values.note,
    };
    const saved = await form.submit(() => post(record.id, 'inspect', body), 'Inspection saved.');
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open wide title="Inspect the returned items" description="Say what became of every unit. Good ones go back into stock; damaged ones are counted as damaged stock, which is never sold; rejected ones are not accepted and go back to the customer. This cannot be changed afterwards." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} errors={form.errors} />
        {lines.map((line, index) => (
          <fieldset key={line.order_item_id} className="rounded-md border border-slate-200 p-3">
            <legend className="px-1 text-sm font-medium text-slate-900">{line.name} — {line.quantity} returned</legend>
            <div className="grid gap-3 sm:grid-cols-3">
              <TextField label="Good, back into stock" inputMode="numeric" value={rows[index].resalable} onChange={(v) => set(index, { resalable: v })} />
              <TextField label="Damaged" inputMode="numeric" value={rows[index].damaged} onChange={(v) => set(index, { damaged: v })} />
              <TextField label="Not accepted" inputMode="numeric" value={rows[index].rejected} onChange={(v) => set(index, { rejected: v })} />
            </div>
            {sums[index] !== line.quantity && <p role="alert" className="mt-2 text-xs font-medium text-red-700">The three numbers must add up to {line.quantity}.</p>}
            <div className="mt-3">
              <TextField label="Note" optional value={rows[index].note} onChange={(v) => set(index, { note: v })} maxLength={500} hint="For your team only." />
            </div>
          </fieldset>
        ))}
        {nothingAccepted && (
          <TextAreaField label="Why nothing is accepted" rows={2} value={form.values.note} onChange={(v) => form.set('note', v)} maxLength={1000} hint="The return ends as not accepted, and the customer sees this." />
        )}
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={!valid}>Save inspection</Button>
        </div>
      </form>
    </Dialog>
  );
}

function ApproveRefundDialog({ record, onClose, onDone }: { record: ReturnRecord; onClose: () => void; onDone: Done }) {
  const form = useForm({ shipping: '', fee: '' });
  const [asCredit, setAsCredit] = useState(false);
  const [local, setLocal] = useState<string | null>(null);
  const shipping = form.values.shipping.trim() === '' ? 0 : toMinor(form.values.shipping, record.currency);
  const fee = form.values.fee.trim() === '' ? 0 : toMinor(form.values.fee, record.currency);
  const total = shipping === null || fee === null ? null : record.items_refund_minor + shipping - fee;
  const orderShipping = record.order?.shipping_total_minor ?? 0;

  async function save(event: FormEvent) {
    event.preventDefault();
    if (shipping === null || fee === null) {
      setLocal(`Enter amounts such as ${fromMinor(5000, record.currency)}.`);

      return;
    }
    setLocal(null);
    const saved = await form.submit(() => post(record.id, 'approve-refund', { shipping_refund_minor: shipping, restocking_fee_minor: fee, as_store_credit: asCredit }), 'Refund approved.');
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open title="Approve the refund" description="This decides the amount. The money is paid back in the next step, by someone who may issue refunds." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={local ?? form.formError} />
        <Details items={[{ label: 'Accepted items (what the customer paid for them)', value: money(record.items_refund_minor, record.currency) }]} />
        {orderShipping > 0 && (
          <TextField label={`Shipping to refund (${record.currency})`} optional inputMode="decimal" value={form.values.shipping} onChange={(v) => form.set('shipping', v)} error={form.errors.shipping_refund_minor} hint={`The order's shipping was ${money(orderShipping, record.currency)}.`} />
        )}
        <TextField label={`Restocking fee to deduct (${record.currency})`} optional inputMode="decimal" value={form.values.fee} onChange={(v) => form.set('fee', v)} error={form.errors.restocking_fee_minor} hint="Only where your return policy and the law allow one." />
        <p className="text-sm font-medium text-slate-900" aria-live="polite">Refund: {total === null ? '—' : money(total, record.currency)}</p>
        {record.store_credit_possible && (
          <CheckboxField label="Give it as store credit instead of money" checked={asCredit} onChange={setAsCredit} hint="The amount is added to the customer's store credit, which they can use at checkout. No money is paid back." />
        )}
        {(record.order?.store_credit_minor ?? 0) > 0 && !asCredit && (
          <p className="text-xs text-slate-600">Part of this order was paid with store credit ({money(record.order?.store_credit_minor ?? 0, record.currency)}). The refund goes to the payment first; what was paid with credit goes back to the customer's credit.</p>
        )}
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Approve refund</Button>
        </div>
      </form>
    </Dialog>
  );
}

type Line = PickedProduct & { quantity: string };

function ReplacementDialog({ record, onClose, onDone }: { record: ReturnRecord; onClose: () => void; onDone: Done }) {
  const access = useAccess();
  const form = useForm({ resolution: record.resolution === 'exchange' ? 'exchange' : 'replacement', payment_method: '' });
  const [lines, setLines] = useState<Line[]>([]);
  const exchange = form.values.resolution === 'exchange';
  const methods = (['cod', 'bank_transfer'] as const).filter((method) => access.feature(`payment.${method}`) === true);
  // The catalog's current prices, for orientation: the server prices the new order.
  const chosen = lines.reduce((sum, line) => sum + (line.variant?.effective_price_minor ?? line.product.effective_price_minor ?? 0) * (Number(line.quantity) || 0), 0);
  const difference = chosen - record.items_refund_minor;

  async function save(event: FormEvent) {
    event.preventDefault();
    if (exchange && lines.length === 0) {
      form.setFormError('Choose the products the customer gets instead.');

      return;
    }
    const body = {
      resolution: form.values.resolution,
      ...(exchange ? { items: lines.map((line) => ({ product_id: line.variant === null ? line.product.internal_id : null, product_variant_id: line.variant?.internal_id ?? null, quantity: Number(line.quantity) })) } : {}),
      payment_method: form.values.payment_method === '' ? null : form.values.payment_method,
    };
    const saved = await form.submit(() => post(record.id, 'replacement', body), 'New order created.');
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open wide title="Send the customer new items" description="A new order is created and linked to this return. It is sent without a shipping charge." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} errors={form.errors} />
        <SelectField label="What the customer gets" value={form.values.resolution} onChange={(v) => form.set('resolution', v)} options={[{ value: 'replacement', label: 'The same items again, free of charge' }, { value: 'exchange', label: 'Other items; the returned value counts towards them' }]} />
        {exchange && (
          <>
            <p className="text-sm text-slate-700">Value of the accepted items: <strong>{money(record.items_refund_minor, record.currency)}</strong></p>
            <ProductPicker label="Add a product" onPick={(picked) => setLines((current) => [...current, { ...picked, quantity: '1' }])} />
            {lines.length > 0 && (
              <ul className="divide-y divide-slate-100 rounded-md border border-slate-200">
                {lines.map((line, index) => (
                  <li key={`${line.product.id}:${line.variant?.id ?? ''}:${index}`} className="grid grid-cols-[1fr_6rem_auto] items-end gap-3 p-3">
                    <div className="text-sm">
                      <span className="font-medium">{line.product.name}</span>
                      {line.variant && <span className="text-slate-600"> — {variantLabel(line.variant)}</span>}
                    </div>
                    <TextField label="Quantity" type="number" min={1} value={line.quantity} onChange={(quantity) => setLines((current) => current.map((item, i) => (i === index ? { ...item, quantity } : item)))} />
                    <Button variant="ghost" size="sm" onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>Remove<span className="sr-only"> {line.product.name}</span></Button>
                  </li>
                ))}
              </ul>
            )}
            {lines.length > 0 && (
              <p className="text-sm text-slate-700" aria-live="polite">
                {difference > 0
                  ? `About ${money(difference, record.currency)} more than what was returned: the customer pays the difference.`
                  : difference < 0
                    ? `About ${money(-difference, record.currency)} less than what was returned: the rest stays on this return to be refunded.`
                    : 'The same value as what was returned.'}
              </p>
            )}
            {difference > 0 && (
              <SelectField label="How the customer pays the difference" value={form.values.payment_method} onChange={(v) => form.set('payment_method', v)} error={form.errors.payment_method} placeholder="Choose" options={methods.map((method) => ({ value: method, label: PAYMENT_METHOD_LABELS[method] }))} />
            )}
          </>
        )}
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Creating…">Create the new order</Button>
        </div>
      </form>
    </Dialog>
  );
}

export default function Show({ returnId }: { returnId: string }) {
  const access = useAccess();
  const state = useApi<{ data: ReturnRecord }>(`/returns/${returnId}`);
  const [dialog, setDialog] = useState<'approve' | 'reject' | 'cancel' | 'transit' | 'receive' | 'inspect' | 'approve-refund' | 'replacement' | 'refund' | null>(null);
  const { busy, run } = useAction();
  const record = state.data?.data ?? null;
  const done: Done = (next) => {
    state.setData({ data: next });
    setDialog(null);
  };

  if (state.error) {
    return (
      <AdminPage title="Return" trail={[{ label: 'Return' }]}>
        {state.errorStatus === 403 ? <AccessNotice message={state.error} /> : state.errorStatus === 404 ? (
          <EmptyPanel title="Return not found" description="It is not a return of this store." action={<ButtonLink href="/returns">Back to returns</ButtonLink>} />
        ) : <ErrorPanel message={state.error} onRetry={state.reload} />}
      </AdminPage>
    );
  }
  if (record === null) {
    return <AdminPage title="Return" trail={[{ label: 'Return' }]}><Skeleton lines={6} /></AdminPage>;
  }

  const manage = access.can('returns.manage');
  const approve = access.can('returns.approve');
  const refund = access.can('payments.refund');
  const can = record.can;
  const inspected = record.inspected_at !== null;

  return (
    <AdminPage
      title={`Return ${record.return_number}`}
      trail={[{ label: record.return_number }]}
      description={<StatusBadge status={record.status} label={RETURN_STATUS_LABELS[record.status]} />}
      actions={
        <>
          <ButtonLink href="/returns">All returns</ButtonLink>
          {approve && can.review && <Button busy={busy === 'review'} onClick={() => void run('review', () => post(record.id, 'review'), { success: 'Marked as under review.' }).then((r) => r && done(r.data))}>Start review</Button>}
          {approve && can.approve && <Button variant="primary" onClick={() => setDialog('approve')}>Approve</Button>}
          {approve && can.reject && <Button variant="danger" onClick={() => setDialog('reject')}>Reject</Button>}
          {manage && can.mark_in_transit && <Button onClick={() => setDialog('transit')}>Items sent back</Button>}
          {manage && can.receive && <Button variant="primary" onClick={() => setDialog('receive')}>Items received</Button>}
          {manage && can.inspect && <Button variant="primary" onClick={() => setDialog('inspect')}>Inspect items</Button>}
          {approve && can.approve_refund && <Button variant="primary" onClick={() => setDialog('approve-refund')}>Approve refund</Button>}
          {manage && can.replace && <Button onClick={() => setDialog('replacement')}>Send new items</Button>}
          {refund && can.refund && <Button variant="primary" onClick={() => setDialog('refund')}>{record.refund_method === 'store_credit' ? 'Give the store credit' : 'Pay the refund'}</Button>}
          {manage && can.cancel && <Button onClick={() => setDialog('cancel')}>Cancel return</Button>}
        </>
      }
    >
      <div className="space-y-4">
        {record.status === 'approved_for_refund' && !refund && (
          <div role="status" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">The refund of {money(record.refund_total_minor, record.currency)} is approved. Someone who may issue refunds has to pay it.</div>
        )}
        {record.decision_note && (
          <div role="note" className="rounded-md border border-slate-300 bg-slate-50 p-3 text-sm text-slate-800"><strong>Told to the customer:</strong> {record.decision_note}</div>
        )}

        <div className="grid gap-4 lg:grid-cols-3">
          <div className="space-y-4 lg:col-span-2">
            <Card title="Items">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <caption className="sr-only">Items of this return</caption>
                  <thead className="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                      <th scope="col" className="py-2 pr-3">Item</th>
                      <th scope="col" className="py-2 pr-3 text-right">Returned</th>
                      {inspected && <><th scope="col" className="py-2 pr-3 text-right">Good</th><th scope="col" className="py-2 pr-3 text-right">Damaged</th><th scope="col" className="py-2 pr-3 text-right">Not accepted</th><th scope="col" className="py-2 text-right">Value</th></>}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {(record.items ?? []).map((line) => (
                      <tr key={line.order_item_id}>
                        <td className="py-2 pr-3">
                          <span className="font-medium text-slate-900">{line.name}</span>
                          {line.sku && <span className="block font-mono text-xs text-slate-500">{line.sku}</span>}
                          {line.inspection_note && <span className="block text-xs text-slate-600">Note: {line.inspection_note}</span>}
                        </td>
                        <td className="py-2 pr-3 text-right">{line.quantity} × {money(line.unit_price_minor, record.currency)}</td>
                        {inspected && <><td className="py-2 pr-3 text-right">{line.resalable_quantity}</td><td className="py-2 pr-3 text-right">{line.damaged_quantity}</td><td className="py-2 pr-3 text-right">{line.rejected_quantity}</td><td className="py-2 text-right">{money(line.refund_minor, record.currency)}</td></>}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {inspected && <p className="mt-2 text-xs text-slate-600">Value is what the customer paid for the accepted units, after discounts.</p>}
            </Card>

            <ReturnPhotos record={record} canManage={manage} onChanged={done} />

            <Card title="Request">
              <Details
                items={[
                  { label: 'Order', value: record.order ? <Link href={`/orders/${record.order.id}`} className={`rounded font-mono text-indigo-700 hover:underline ${FOCUS_RING}`}>{record.order.order_number}</Link> : '—' },
                  { label: 'Customer', value: record.order?.customer_name ?? '—' },
                  { label: 'Asked by', value: record.requested_by === 'customer' ? 'The customer' : record.requested_by === 'guest' ? 'The customer, by the link sent to the order\x27s email' : 'Your team, for the customer' },
                  { label: 'Wants', value: RESOLUTION_LABELS[record.resolution] ?? record.resolution },
                  { label: 'Reason', value: REASON_LABELS[record.reason] ?? record.reason },
                  { label: 'In their words', value: record.description ?? '—' },
                  { label: 'How the items come back', value: record.return_method ? `${METHOD_LABELS[record.return_method]}${record.return_shipping_paid_by ? ` — return shipping paid by the ${record.return_shipping_paid_by}` : ''}` : 'Not agreed yet' },
                  { label: 'Tracking', value: record.return_tracking_number ? `${record.return_carrier ?? ''} ${record.return_tracking_number}`.trim() : '—' },
                  { label: 'Received at', value: record.warehouse?.name ?? '—' },
                ]}
              />
            </Card>
          </div>

          <div className="space-y-4">
            <Card title="Money">
              <Details
                items={[
                  { label: 'Accepted items', value: inspected ? money(record.items_refund_minor, record.currency) : 'After inspection' },
                  { label: 'Shipping refund', value: money(record.shipping_refund_minor, record.currency) },
                  { label: 'Restocking fee', value: record.restocking_fee_minor > 0 ? `− ${money(record.restocking_fee_minor, record.currency)}` : money(0, record.currency) },
                  { label: 'Refund approved', value: record.status === 'approved_for_refund' || record.refunded_at ? money(record.refund_total_minor, record.currency) : '—' },
                  { label: 'Paid back', value: record.refunded_at ? money(record.refund_method === 'store_credit' ? 0 : record.refunded_minor, record.currency) : '—' },
                  ...((record.refunded_credit_minor ?? 0) > 0 || record.refund_method === 'store_credit'
                    ? [{ label: 'To store credit', value: record.refunded_at ? money(record.refunded_credit_minor ?? 0, record.currency) : 'When the refund is paid' }]
                    : []),
                ]}
              />
              {record.refunded_at && (record.refund_method === 'store_credit' ? (record.refunded_credit_minor ?? 0) : record.refunded_minor + (record.refunded_credit_minor ?? 0)) < record.refund_total_minor && (
                <p className="mt-2 text-xs text-amber-800">Less was given back than approved: the order was not paid for more (for example cash on delivery that was never collected).</p>
              )}
              {record.replacement_order && (
                <p className="mt-3 text-sm">
                  New order:{' '}
                  <Link href={`/orders/${record.replacement_order.id}`} className={`rounded font-mono text-indigo-700 hover:underline ${FOCUS_RING}`}>{record.replacement_order.order_number}</Link>
                  {' '}({record.replacement_order.grand_total_minor === 0 ? 'free of charge' : `${money(record.replacement_order.grand_total_minor, record.currency)} to pay`})
                </p>
              )}
            </Card>
            <Card title="Progress">
              <ol className="space-y-2 text-sm">
                {returnSteps(record).map((step) => (
                  <li key={step.label} className="border-l-2 border-slate-200 pl-3">
                    <span className="font-medium text-slate-900">{step.label}</span>
                    <span className="block text-xs text-slate-500">{step.at ? formatDateTime(step.at) : ''}</span>
                  </li>
                ))}
                {record.cancelled_at && <li className="border-l-2 border-red-200 pl-3"><span className="font-medium text-slate-900">Cancelled</span><span className="block text-xs text-slate-500">{formatDateTime(record.cancelled_at)}</span></li>}
              </ol>
            </Card>
          </div>
        </div>
      </div>

      {dialog === 'approve' && <ApproveDialog record={record} onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'reject' && <NoteDialog record={record} step="reject" title="Reject this return?" description="The customer is told by email, with your reason. The items stay theirs." button="Reject return" required success="Return rejected." onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'cancel' && <NoteDialog record={record} step="cancel" title="Cancel this return?" description="Use this when the customer no longer wants to return the items." button="Cancel return" required={false} success="Return cancelled." onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'transit' && <InTransitDialog record={record} onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'receive' && <ReceiveDialog record={record} onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'inspect' && <InspectDialog record={record} onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'approve-refund' && <ApproveRefundDialog record={record} onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'replacement' && <ReplacementDialog record={record} onClose={() => setDialog(null)} onDone={done} />}
      <ConfirmDialog
        open={dialog === 'refund'}
        title={record.refund_method === 'store_credit' ? `Give ${money(record.refund_total_minor, record.currency)} as store credit?` : `Pay back ${money(record.refund_total_minor, record.currency)}?`}
        confirmLabel={record.refund_method === 'store_credit' ? 'Give the store credit' : 'Pay the refund'}
        busy={busy === 'refund'}
        onClose={() => setDialog(null)}
        onConfirm={() => void run('refund', () => post(record.id, 'refund'), { success: 'Refund recorded.' }).then((r) => r && done(r.data))}
      >
        {record.refund_method === 'store_credit' ? (
          <p>The amount is added to the customer's store credit and recorded on the order's payment, so it cannot also be paid back as money. The return is completed. This cannot be undone.</p>
        ) : (
          <p>The refund is recorded on the order's payment, and the return is completed. For cash on delivery or a bank transfer, hand over or send the money yourself: no gateway does it for you. This cannot be undone.</p>
        )}
      </ConfirmDialog>
    </AdminPage>
  );
}
