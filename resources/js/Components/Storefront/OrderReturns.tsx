import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { formatDate } from '@/lib/datetime';
import { formatMoney } from '@/lib/money';
import { errorMessage, storefrontFetch, validationErrors } from '@/Storefront/api';
import type { Shell } from '@/Storefront/types';
import { REASON_LABELS, RESOLUTION_LABELS, RETURN_REASONS, RETURN_RESOLUTIONS, RETURN_STATUS_LABELS, type Returnable, type ReturnRecord } from '@/lib/returns';

/**
 * Module 09 §45 (Phase B33): returns on the customer's order page.
 * What can be returned, whether the store takes requests here, and the
 * return period all come from the server; so does every amount. The
 * request form is shown only when the store allows it (Settings), else
 * the customer is pointed to the store's support.
 */
function newKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function RequestForm({ shell, orderId, info, onDone }: { shell: Shell; orderId: string; info: Returnable; onDone: () => void }) {
  const lines = info.lines.filter((line) => line.returnable > 0);
  const [quantities, setQuantities] = useState<Record<number, string>>({});
  const [resolution, setResolution] = useState('refund');
  const [reason, setReason] = useState('');
  const [description, setDescription] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [key] = useState(newKey);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    const items = lines.map((line) => ({ order_item_id: line.order_item_id, quantity: Number(quantities[line.order_item_id] ?? '0') })).filter((item) => item.quantity > 0);
    if (items.length === 0) {
      setError('Enter how many of at least one item you want to return.');

      return;
    }
    if (reason === '') {
      setError('Please choose a reason.');

      return;
    }
    setBusy(true);
    setError(null);
    try {
      await storefrontFetch(shell, `/customer/orders/${encodeURIComponent(orderId)}/returns`, {
        method: 'POST',
        body: { items, resolution, reason, description: description === '' ? null : description, idempotency_key: key },
      });
      onDone();
    } catch (e) {
      const fields = validationErrors(e);
      setError(Object.values(fields)[0]?.[0] ?? errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4 rounded-sf border border-sf-border p-4" noValidate>
      <h3 className="font-semibold">Request a return</h3>
      <p className="text-sm text-sf-muted">You can ask within {info.window_days} days of delivery. The store checks your request and tells you how to send the items back.</p>
      {error && <p className="rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">{error}</p>}
      {lines.map((line) => (
        <label key={line.order_item_id} className="flex items-center justify-between gap-3 text-sm">
          <span>
            {line.name}
            <span className="block text-sf-muted">Up to {line.returnable}</span>
          </span>
          <input type="number" min={0} max={line.returnable} inputMode="numeric" aria-label={`How many of ${line.name} to return`} value={quantities[line.order_item_id] ?? ''} onChange={(e) => setQuantities((current) => ({ ...current, [line.order_item_id]: e.target.value }))} className="w-24 rounded-sf border border-sf-border px-3 py-2" />
        </label>
      ))}
      <label className="block text-sm">
        <span className="mb-1 block text-sf-muted">What would you like?</span>
        <select value={resolution} onChange={(e) => setResolution(e.target.value)} className="w-full rounded-sf border border-sf-border px-3 py-2">
          {RETURN_RESOLUTIONS.map((value) => <option key={value} value={value}>{RESOLUTION_LABELS[value]}</option>)}
        </select>
      </label>
      <label className="block text-sm">
        <span className="mb-1 block text-sf-muted">Reason</span>
        <select value={reason} onChange={(e) => setReason(e.target.value)} required className="w-full rounded-sf border border-sf-border px-3 py-2">
          <option value="">Choose a reason</option>
          {RETURN_REASONS.map((value) => <option key={value} value={value}>{REASON_LABELS[value]}</option>)}
        </select>
      </label>
      <label className="block text-sm">
        <span className="mb-1 block text-sf-muted">Tell us more (optional)</span>
        <textarea value={description} onChange={(e) => setDescription(e.target.value)} maxLength={2000} rows={3} className="w-full rounded-sf border border-sf-border px-3 py-2" />
      </label>
      <button type="submit" disabled={busy} className="rounded-sf bg-sf-primary px-4 py-2 font-semibold text-white disabled:opacity-60">
        {busy ? 'Sending…' : 'Send request'}
      </button>
    </form>
  );
}

function ReturnCard({ shell, record, onChanged }: { shell: Shell; record: ReturnRecord; onChanged: () => void }) {
  const [carrier, setCarrier] = useState('');
  const [tracking, setTracking] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  function act(step: 'shipped' | 'cancel', body: Record<string, unknown> = {}) {
    setBusy(step);
    setError(null);
    storefrontFetch(shell, `/customer/returns/${record.id}/${step}`, { method: 'POST', body })
      .then(onChanged)
      .catch((e) => setError(errorMessage(e)))
      .finally(() => setBusy(null));
  }

  return (
    <li className="rounded-sf border border-sf-border p-4 text-sm">
      <p className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-medium">Return {record.return_number}</span>
        <span>{RETURN_STATUS_LABELS[record.status] ?? record.status}</span>
      </p>
      <p className="mt-1 text-sf-muted">
        {(record.items ?? []).map((item) => `${item.quantity} × ${item.name}`).join(', ')} · {RESOLUTION_LABELS[record.resolution]?.split(' (')[0]} · asked {formatDate(record.created_at)}
      </p>
      {record.decision_note && <p className="mt-2">From the store: {record.decision_note}</p>}
      {record.refunded_at && record.refunded_minor > 0 && <p className="mt-2 font-medium">Refunded: {formatMoney(record.refunded_minor, record.currency)}</p>}
      {record.replacement_order && <p className="mt-2">Your new order: {record.replacement_order.order_number}</p>}
      {error && <p className="mt-2 text-sf-error" role="alert">{error}</p>}

      {record.status === 'approved' && (
        <div className="mt-3 space-y-2 border-t border-sf-border pt-3">
          <p>Sent the items back? Tell the store, with the courier and tracking number if you have them.</p>
          <div className="grid gap-2 sm:grid-cols-2">
            <input aria-label="Courier" placeholder="Courier (optional)" value={carrier} onChange={(e) => setCarrier(e.target.value)} maxLength={64} className="rounded-sf border border-sf-border px-3 py-2" />
            <input aria-label="Tracking number" placeholder="Tracking number (optional)" value={tracking} onChange={(e) => setTracking(e.target.value)} maxLength={128} className="rounded-sf border border-sf-border px-3 py-2" />
          </div>
          <button type="button" disabled={busy !== null} onClick={() => act('shipped', { carrier: carrier || null, tracking_number: tracking || null })} className="rounded-sf bg-sf-primary px-4 py-2 font-semibold text-white disabled:opacity-60">
            {busy === 'shipped' ? 'Saving…' : 'I have sent the items'}
          </button>
        </div>
      )}
      {record.can.cancel && (
        <button type="button" disabled={busy !== null} onClick={() => window.confirm('Withdraw this return request?') && act('cancel')} className="mt-3 text-sf-accent underline disabled:opacity-60">
          {busy === 'cancel' ? 'Withdrawing…' : 'Withdraw this request'}
        </button>
      )}
    </li>
  );
}

export default function OrderReturns({ shell, orderId }: { shell: Shell; orderId: string }) {
  const [info, setInfo] = useState<Returnable | null>(null);
  const [sent, setSent] = useState(false);
  const base = shell.base_path;

  const load = useCallback(() => {
    storefrontFetch<{ data: Returnable }>(shell, `/customer/orders/${encodeURIComponent(orderId)}/returnable`)
      .then((res) => setInfo(res.data))
      .catch(() => setInfo(null)); // the order page itself already reports a load failure
  }, [shell, orderId]);
  useEffect(load, [load]);

  if (info === null) return null;
  const returns = info.returns ?? [];
  const delivered = info.lines.some((line) => line.delivered > 0);
  const returnable = info.lines.some((line) => line.returnable > 0);
  if (returns.length === 0 && !delivered) return null;

  return (
    <div>
      <h2 className="mb-2 font-semibold">Returns</h2>
      {sent && <p className="mb-3 rounded-sf bg-sf-surface p-3 text-sm" role="status">Your request was sent. The store will answer by email.</p>}
      {returns.length > 0 && (
        <ul className="mb-4 space-y-3">
          {returns.map((record) => <ReturnCard key={record.id} shell={shell} record={record} onChanged={load} />)}
        </ul>
      )}
      {info.blocked === null && info.enabled && returnable && (
        <RequestForm
          shell={shell}
          orderId={orderId}
          info={info}
          onDone={() => {
            setSent(true);
            load();
          }}
        />
      )}
      {info.blocked === null && delivered && !(info.enabled && returnable) && returns.every((record) => ['rejected', 'cancelled', 'completed'].includes(record.status)) && (
        <p className="text-sm text-sf-muted">
          {info.enabled ? `Items can be returned within ${info.window_days} days of delivery.` : 'To return an item,'}{' '}
          <Link href={`${base}/account/support/new?order=${encodeURIComponent(orderId)}`} className="text-sf-accent">
            {info.enabled ? 'Contact the store about a later return' : 'contact the store'}
          </Link>
          .
        </p>
      )}
    </div>
  );
}
