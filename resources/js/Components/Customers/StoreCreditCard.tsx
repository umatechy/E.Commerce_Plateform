import { FormEvent, useState } from 'react';
import Button from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import { Card, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch, idempotencyKey } from '@/lib/adminApi';
import { money, toMinor } from '@/lib/money';
import { formatDate, formatDateTime } from '@/lib/datetime';

/**
 * Module 09 §52 (Phase B34): a customer's store credit — the balance and
 * its ledger, as the server holds them. Giving or taking credit by hand
 * needs its own permission, a reason and the password again; the server
 * decides all three and never lets the balance go below zero.
 */
export type StoreCreditEntry = { id: string; type: string; amount_minor: number; balance_after_minor: number; currency: string; note: string | null; created_at: string; by?: string | null };
export type StoreCredit = {
  currency: string;
  balance_minor: number;
  // Phase B35: the next credit to expire, where the store turned expiry on.
  next_expiry?: { amount_minor: number; expires_at: string } | null;
  other_balances: { currency: string; balance_minor: number }[];
  entries: StoreCreditEntry[];
  can_adjust?: boolean;
};

export const STORE_CREDIT_TYPES: Record<string, string> = {
  return_refund: 'Refund of a return',
  adjustment: 'Changed by the store',
  spent: 'Used on an order',
  order_cancelled: 'Order cancelled',
  erased: 'Ended with the erasure of personal data',
  expired: 'Expired',
};

function AdjustDialog({ customerId, credit, onClose, onDone }: { customerId: string; credit: StoreCredit; onClose: () => void; onDone: (credit: StoreCredit) => void }) {
  const form = useForm({ direction: 'give', amount: '', note: '' });
  const [local, setLocal] = useState<string | null>(null);
  // One key per opened dialog: pressing Save twice changes the balance once.
  const [key] = useState(idempotencyKey);

  async function save(event: FormEvent) {
    event.preventDefault();
    const minor = toMinor(form.values.amount, credit.currency);
    if (minor === null || minor <= 0) {
      setLocal(`Enter an amount above 0 (${credit.currency}).`);

      return;
    }
    setLocal(null);
    const body = { amount_minor: form.values.direction === 'give' ? minor : -minor, note: form.values.note, idempotency_key: key };
    const saved = await form.submit(() => adminFetch<{ data: StoreCredit }>(`/customers/${customerId}/store-credit/adjust`, { method: 'POST', body }), 'Store credit changed.');
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open title="Change store credit" description={`Balance now: ${money(credit.balance_minor, credit.currency)}. This is recorded with your name and the reason. You will be asked for your password.`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <SelectField label="What to do" value={form.values.direction} onChange={(v) => form.set('direction', v)} options={[{ value: 'give', label: 'Give credit' }, { value: 'take', label: 'Take credit back' }]} />
        <TextField label={`Amount (${credit.currency})`} inputMode="decimal" value={form.values.amount} onChange={(v) => form.set('amount', v)} error={local ?? form.errors.amount_minor} required data-autofocus />
        <TextField label="Reason" value={form.values.note} onChange={(v) => form.set('note', v)} error={form.errors.note} maxLength={500} required hint="For example: goodwill for a late delivery." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={form.values.note.trim() === ''}>Save</Button>
        </div>
      </form>
    </Dialog>
  );
}

export default function StoreCreditCard({ customerId, version }: { customerId: string; version?: number }) {
  const state = useApi<{ data: StoreCredit }>(`/customers/${customerId}/store-credit`, { v: version || undefined });
  const [adjusting, setAdjusting] = useState(false);
  const credit = state.data?.data ?? null;

  return (
    <Card
      title="Store credit"
      description="Money the store owes this customer. They can use it at checkout when signed in."
      actions={credit?.can_adjust ? <Button size="sm" onClick={() => setAdjusting(true)}>Change</Button> : undefined}
    >
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : credit === null ? (
        <Skeleton lines={2} />
      ) : (
        <>
          <p className="text-2xl font-semibold text-slate-900">{money(credit.balance_minor, credit.currency)}</p>
          {credit.next_expiry && (
            <p className="text-sm text-amber-800">{money(credit.next_expiry.amount_minor, credit.currency)} expires on {formatDate(credit.next_expiry.expires_at)}.</p>
          )}
          {credit.other_balances.map((other) => (
            <p key={other.currency} className="text-sm text-slate-600">Also {money(other.balance_minor, other.currency)}, from when the store used {other.currency}.</p>
          ))}
          {credit.entries.length === 0 ? (
            <p className="mt-2 text-sm text-slate-600">No store credit yet.</p>
          ) : (
            <ol className="mt-3 space-y-2 text-sm">
              {credit.entries.map((entry) => (
                <li key={entry.id} className="flex items-start justify-between gap-3 border-l-2 border-slate-200 pl-3">
                  <span>
                    <span className="font-medium text-slate-900">{STORE_CREDIT_TYPES[entry.type] ?? entry.type}</span>
                    {entry.note && <span className="block text-slate-700">{entry.note}</span>}
                    <span className="block text-xs text-slate-500">{formatDateTime(entry.created_at)}{entry.by ? ` · by ${entry.by}` : ''}</span>
                  </span>
                  <span className={`whitespace-nowrap font-medium ${entry.amount_minor < 0 ? 'text-red-800' : 'text-green-800'}`}>
                    {entry.amount_minor < 0 ? '−' : '+'} {money(Math.abs(entry.amount_minor), entry.currency)}
                  </span>
                </li>
              ))}
            </ol>
          )}
        </>
      )}
      {adjusting && credit && (
        <AdjustDialog
          customerId={customerId}
          credit={credit}
          onClose={() => setAdjusting(false)}
          onDone={(next) => {
            state.setData({ data: { ...next, can_adjust: credit.can_adjust } });
            setAdjusting(false);
          }}
        />
      )}
    </Card>
  );
}
