import { FormEvent, useState } from 'react';
import Badge from '@/Components/ui/Badge';
import Button from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import { Card, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch, idempotencyKey } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { formatDate, formatDateTime } from '@/lib/datetime';

/**
 * Phase P2: a customer's loyalty points — balance, tier and ledger, as the
 * server holds them. Giving or taking points by hand needs its own
 * permission, a reason and the password again; the server decides all three
 * and never lets the balance go below zero.
 */
type Tier = { name: string; min_points: number; bonus_percent: number };
export type LoyaltyEntry = { id: string; type: string; points: number; balance_after: number; note: string | null; created_at: string; by?: string | null };
export type CustomerLoyalty = {
  balance: number;
  value_minor: number;
  currency: string | null;
  lifetime_points: number;
  tier: Tier | null;
  next_tier: (Tier & { points_to_go: number }) | null;
  pending_points: number;
  expires_at: string | null;
  entries: LoyaltyEntry[];
  can_adjust?: boolean;
};

export const LOYALTY_TYPES: Record<string, string> = {
  earned: 'Earned on an order',
  repeat_bonus: 'Repeat-purchase bonus',
  birthday: 'Birthday reward',
  reversed: 'Taken back after a refund',
  redeemed: 'Turned into store credit',
  adjustment: 'Changed by the store',
  expired: 'Expired',
  erased: 'Ended with the erasure of personal data',
};

function AdjustDialog({ customerId, loyalty, onClose, onDone }: { customerId: string; loyalty: CustomerLoyalty; onClose: () => void; onDone: (next: CustomerLoyalty) => void }) {
  const form = useForm({ direction: 'give', points: '', note: '' });
  const [local, setLocal] = useState<string | null>(null);
  // One key per opened dialog: pressing Save twice changes the balance once.
  const [key] = useState(idempotencyKey);

  async function save(event: FormEvent) {
    event.preventDefault();
    const points = Number(form.values.points);
    if (!Number.isInteger(points) || points <= 0) {
      setLocal('Enter a whole number of points above 0.');
      return;
    }
    setLocal(null);
    const body = { points: form.values.direction === 'give' ? points : -points, note: form.values.note, idempotency_key: key };
    const saved = await form.submit(() => adminFetch<{ data: CustomerLoyalty }>(`/customers/${customerId}/loyalty/adjust`, { method: 'POST', body }), 'Points changed.');
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open title="Change loyalty points" description={`Balance now: ${loyalty.balance.toLocaleString()} points. This is recorded with your name and the reason. You will be asked for your password.`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <SelectField label="What to do" value={form.values.direction} onChange={(v) => form.set('direction', v)} options={[{ value: 'give', label: 'Give points' }, { value: 'take', label: 'Take points back' }]} />
        <TextField label="Points" inputMode="numeric" value={form.values.points} onChange={(v) => form.set('points', v)} error={local ?? form.errors.points} required data-autofocus />
        <TextField label="Reason" value={form.values.note} onChange={(v) => form.set('note', v)} error={form.errors.note} maxLength={500} required hint="For example: points for an order placed by phone." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={form.values.note.trim() === ''}>Save</Button>
        </div>
      </form>
    </Dialog>
  );
}

export default function LoyaltyCard({ customerId, version }: { customerId: string; version?: number }) {
  const state = useApi<{ data: CustomerLoyalty }>(`/customers/${customerId}/loyalty`, { v: version || undefined });
  const [adjusting, setAdjusting] = useState(false);
  const loyalty = state.data?.data ?? null;
  // A store without points and a customer without any: nothing to show.
  if (loyalty !== null && !loyalty.can_adjust && loyalty.balance === 0 && loyalty.entries.length === 0 && loyalty.pending_points === 0) return null;

  return (
    <Card
      title="Loyalty points"
      icon="star"
      description="Points this customer earned on orders. They turn them into store credit."
      actions={loyalty?.can_adjust ? <Button size="sm" onClick={() => setAdjusting(true)}>Change</Button> : undefined}
    >
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : loyalty === null ? (
        <Skeleton lines={2} />
      ) : (
        <>
          <p className="flex flex-wrap items-baseline gap-2">
            <span className="text-2xl font-semibold tabular-nums text-slate-900">{loyalty.balance.toLocaleString()}</span>
            <span className="text-sm text-slate-600">points{loyalty.currency && loyalty.value_minor > 0 ? ` · worth ${money(loyalty.value_minor, loyalty.currency)}` : ''}</span>
            {loyalty.tier && <Badge tone="blue">{loyalty.tier.name}</Badge>}
          </p>
          {loyalty.pending_points > 0 && <p className="text-sm text-slate-600">{loyalty.pending_points.toLocaleString()} more on the way from orders not yet delivered.</p>}
          {loyalty.next_tier && <p className="text-sm text-slate-600">{loyalty.next_tier.points_to_go.toLocaleString()} points to {loyalty.next_tier.name}.</p>}
          {loyalty.expires_at && <p className="text-sm text-amber-800">Expires on {formatDate(loyalty.expires_at)} without new activity.</p>}
          {loyalty.entries.length === 0 ? (
            <p className="mt-2 text-sm text-slate-600">No points yet.</p>
          ) : (
            <ol className="mt-3 space-y-2 text-sm">
              {loyalty.entries.slice(0, 10).map((entry) => (
                <li key={entry.id} className="flex items-start justify-between gap-3 border-l-2 border-slate-200 pl-3">
                  <span>
                    <span className="font-medium text-slate-900">{LOYALTY_TYPES[entry.type] ?? entry.type}</span>
                    {entry.note && <span className="block text-slate-700">{entry.note}</span>}
                    <span className="block text-xs text-slate-500">{formatDateTime(entry.created_at)}{entry.by ? ` · by ${entry.by}` : ''}</span>
                  </span>
                  <span className={`whitespace-nowrap font-medium tabular-nums ${entry.points < 0 ? 'text-red-800' : 'text-green-800'}`}>
                    {entry.points < 0 ? '−' : '+'} {Math.abs(entry.points).toLocaleString()}
                  </span>
                </li>
              ))}
            </ol>
          )}
        </>
      )}
      {adjusting && loyalty && (
        <AdjustDialog
          customerId={customerId}
          loyalty={loyalty}
          onClose={() => setAdjusting(false)}
          onDone={(next) => {
            state.setData({ data: { ...next, can_adjust: loyalty.can_adjust } });
            setAdjusting(false);
          }}
        />
      )}
    </Card>
  );
}
