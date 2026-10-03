import { FormEvent, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { StatusBadge } from '@/Components/ui/Badge';
import { Card, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch, idempotencyKey } from '@/lib/adminApi';
import { options } from '@/lib/labels';
import type { Order } from '@/lib/orders';
import { REASON_LABELS, RESOLUTION_LABELS, RETURN_REASONS, RETURN_RESOLUTIONS, RETURN_STATUS_LABELS, type Returnable, type ReturnRecord } from '@/lib/returns';

/**
 * Module 09 §45, §47 (Phase B33): the returns of one order, and the
 * dialog with which staff record a return for the customer. What can be
 * returned (delivered, and not already in a return) is the server's
 * answer; the dialog only lets staff choose within it.
 */
function StartReturnDialog({ order, returnable, onClose }: { order: Order; returnable: Returnable; onClose: () => void }) {
  const lines = returnable.lines.filter((line) => line.returnable > 0);
  const [quantities, setQuantities] = useState<Record<number, string>>({});
  const form = useForm({ resolution: 'refund', reason: '', description: '' });
  // One key for this dialog: sending it twice records one return.
  const [key] = useState(idempotencyKey);

  async function save(event: FormEvent) {
    event.preventDefault();
    const items = lines
      .map((line) => ({ order_item_id: line.order_item_id, quantity: Number(quantities[line.order_item_id] ?? '0') }))
      .filter((item) => item.quantity > 0);
    if (items.length === 0) {
      form.setFormError('Enter how many of at least one item come back.');

      return;
    }
    if (items.some((item) => !Number.isInteger(item.quantity) || item.quantity > (lines.find((line) => line.order_item_id === item.order_item_id)?.returnable ?? 0))) {
      form.setFormError('A quantity is more than can be returned.');

      return;
    }
    if (form.values.reason === '') {
      form.setFormError('Choose the reason.');

      return;
    }
    const body = { items, ...form.values, description: form.values.description === '' ? null : form.values.description, idempotency_key: key };
    const saved = await form.submit(() => adminFetch<{ data: ReturnRecord }>(`/orders/${order.id}/returns`, { method: 'POST', body }), 'Return recorded.');
    if (saved) router.visit(`/returns/${saved.data.id}`);
  }

  return (
    <Dialog open wide title={`Start a return for order ${order.order_number}`} description="Only delivered items that are not already in a return can be chosen. Nothing is refunded or restocked yet." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} errors={form.errors} />
        <fieldset className="space-y-3">
          <legend className="text-sm font-medium text-slate-700">How many come back</legend>
          {lines.map((line) => (
            <div key={line.order_item_id} className="grid grid-cols-[1fr_7rem] items-end gap-3">
              <p className="text-sm text-slate-900">
                {line.name}
                <span className="block text-xs text-slate-600">Up to {line.returnable} (delivered {line.delivered}{line.in_returns > 0 ? `, ${line.in_returns} already in a return` : ''})</span>
              </p>
              <TextField label={`Quantity of ${line.name}`} type="number" min={0} max={line.returnable} value={quantities[line.order_item_id] ?? ''} onChange={(v) => setQuantities((current) => ({ ...current, [line.order_item_id]: v }))} />
            </div>
          ))}
        </fieldset>
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField label="The customer wants" value={form.values.resolution} onChange={(v) => form.set('resolution', v)} error={form.errors.resolution} options={options(RETURN_RESOLUTIONS, RESOLUTION_LABELS)} />
          <SelectField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} placeholder="Choose" options={options(RETURN_REASONS, REASON_LABELS)} />
        </div>
        <TextAreaField label="What the customer said" optional rows={3} value={form.values.description} onChange={(v) => form.set('description', v)} error={form.errors.description} maxLength={2000} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Record the return</Button>
        </div>
      </form>
    </Dialog>
  );
}

export default function ReturnsCard({ order }: { order: Order }) {
  const access = useAccess();
  const returns = usePagedApi<ReturnRecord>('/returns', { order: order.id, per_page: 50 });
  const returnable = useApi<{ data: Returnable }>(`/orders/${order.id}/returnable`);
  const [starting, setStarting] = useState(false);
  const info = returnable.data?.data ?? null;
  const anything = (info?.lines ?? []).some((line) => line.returnable > 0);

  return (
    <Card
      title="Returns"
      actions={access.can('returns.manage') && info !== null && info.blocked === null && anything ? <Button size="sm" onClick={() => setStarting(true)}>Start a return</Button> : undefined}
    >
      {returns.error ? (
        <ErrorPanel message={returns.error} onRetry={returns.reload} />
      ) : returns.rows === null ? (
        <Skeleton lines={2} />
      ) : returns.rows.length === 0 ? (
        <p className="text-sm text-slate-600">
          No returns for this order.
          {info !== null && info.blocked === null && !anything && ' Items can be returned once they are delivered.'}
          {info?.blocked && ` ${info.blocked}`}
        </p>
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {returns.rows.map((record) => (
            <li key={record.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <Link href={`/returns/${record.id}`} className={`rounded font-mono text-indigo-700 hover:underline ${FOCUS_RING}`}>{record.return_number}</Link>
              <span className="text-slate-700">{(record.items ?? []).reduce((sum, item) => sum + item.quantity, 0)} unit(s) · {RESOLUTION_LABELS[record.resolution]?.split(' (')[0]}</span>
              <StatusBadge status={record.status} label={RETURN_STATUS_LABELS[record.status]} />
            </li>
          ))}
        </ul>
      )}
      {starting && info && <StartReturnDialog order={order} returnable={info} onClose={() => setStarting(false)} />}
    </Card>
  );
}
