import { useState } from 'react';
import Button from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField } from '@/Components/ui/Form';
import { Skeleton } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { formatUtcDate } from '@/lib/datetime';
import { FEATURE_LABELS, USAGE_LABELS, labelFor } from '@/lib/labels';

/**
 * Phase B47 (Module 29 §47–49, Module 04 §22): change the store's package
 * with a preview first — when it happens, what it costs now (an upgrade's
 * prorated invoice), what the store loses, and which limits it would be
 * over. Downgrades wait for the end of what is paid for; nothing is deleted.
 */
export type PlanOption = { code: string; name: string; price_minor: number | null; currency: string | null; current: boolean; scheduled: boolean };
export type PlanPreview = {
  direction: 'upgrade' | 'downgrade' | 'lateral' | 'same' | null;
  timing: 'now' | 'period_end' | null;
  effective_at?: string | null;
  blocked: string | null;
  reason?: string;
  currency: string | null;
  from: { name: string; price_minor: number | null };
  to: { name: string; price_minor: number | null };
  interval: string;
  features_lost: string[];
  features_gained: string[];
  limits_exceeded: Record<string, { limit: number; current: number }>;
  proration: { charge_minor: number; credit_minor: number; net_minor: number; tax_minor: number; total_minor: number; until: string } | null;
};

const BLOCKED: Record<string, string> = {
  same_package: 'This is already your package.',
  no_price: 'This package has no price for your billing period yet.',
  pay_open_invoice: 'Pay your open invoice first, then change the plan.',
};

export default function PlanChangeDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const plans = useApi<{ data: PlanOption[] }>('/billing/plans');
  const [code, setCode] = useState('');
  const preview = useApi<{ data: PlanPreview }>(code === '' ? null : '/billing/plan-change/preview', code === '' ? undefined : { package: code });
  const [error, setError] = useState<string | null>(null);
  const { busy, run } = useAction();
  const p = preview.data?.data;
  const currency = p?.currency ?? 'PKR';

  async function confirm() {
    setError(null);
    const done = await run('change', () => adminFetch('/billing/plan-change', { method: 'POST', body: { package: code } }), {
      success: p?.timing === 'now' ? 'Your plan has changed.' : 'Your plan will change at the end of the period.',
      onError: setError,
    });
    if (done !== undefined) onDone();
  }

  return (
    <Dialog open wide title="Change your plan" onClose={onClose} busy={busy === 'change'}>
      {!plans.data ? (
        <Skeleton lines={3} />
      ) : (
        <div className="space-y-4">
          <FormError message={error} />
          <SelectField
            label="New plan"
            value={code}
            onChange={setCode}
            placeholder="Choose a plan"
            options={plans.data.data.filter((o) => !o.current).map((o) => ({ value: o.code, label: `${o.name}${o.price_minor !== null && o.currency ? ` — ${money(o.price_minor, o.currency)}` : ''}${o.scheduled ? ' (scheduled)' : ''}` }))}
          />
          {code !== '' && (!p || preview.loading ? <Skeleton lines={4} /> : (
            <div className="space-y-3 text-sm">
              {p.blocked ? (
                <p role="alert" className="rounded-md bg-amber-50 p-3 text-amber-900">{BLOCKED[p.blocked] ?? 'This change is not possible now.'}</p>
              ) : (
                <p className="rounded-md bg-slate-50 p-3">
                  {p.timing === 'now'
                    ? p.reason === 'trial' ? `You move to ${p.to.name} now. Your trial continues; nothing is charged now.` : `You move to ${p.to.name} now.`
                    : `You keep ${p.from.name} until ${formatUtcDate(p.effective_at ?? null)} (UTC), the end of what you have paid for; then ${p.to.name} starts.`}
                </p>
              )}
              {p.proration && !p.blocked && (
                <dl className="max-w-sm space-y-1">
                  <div className="flex justify-between"><dt className="text-slate-600">{p.to.name} until {formatUtcDate(p.proration.until)}</dt><dd>{money(p.proration.charge_minor, currency)}</dd></div>
                  <div className="flex justify-between"><dt className="text-slate-600">Unused {p.from.name}</dt><dd>− {money(p.proration.credit_minor, currency)}</dd></div>
                  {p.proration.tax_minor > 0 && <div className="flex justify-between"><dt className="text-slate-600">Tax</dt><dd>{money(p.proration.tax_minor, currency)}</dd></div>}
                  <div className="flex justify-between border-t border-slate-200 pt-1 font-semibold"><dt>To pay now</dt><dd>{money(p.proration.total_minor, currency)}</dd></div>
                </dl>
              )}
              {p.features_lost.length > 0 && (
                <div>
                  <p className="font-medium text-slate-900">You lose</p>
                  <ul className="list-disc ps-5 text-slate-700">{p.features_lost.map((k) => <li key={k}>{labelFor(FEATURE_LABELS, k)}</li>)}</ul>
                </div>
              )}
              {p.features_gained.length > 0 && (
                <div>
                  <p className="font-medium text-slate-900">You get</p>
                  <ul className="list-disc ps-5 text-slate-700">{p.features_gained.map((k) => <li key={k}>{labelFor(FEATURE_LABELS, k)}</li>)}</ul>
                </div>
              )}
              {Object.keys(p.limits_exceeded).length > 0 && (
                <div role="note" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-900">
                  <p className="font-medium">You are above these limits of {p.to.name}:</p>
                  <ul className="list-disc ps-5">{Object.entries(p.limits_exceeded).map(([k, v]) => <li key={k}>{labelFor(USAGE_LABELS, k)}: {v.current} of {v.limit}</li>)}</ul>
                  <p className="mt-1">Nothing is deleted; you cannot add more until you are under the limit.</p>
                </div>
              )}
            </div>
          ))}
          <div className="flex justify-end gap-2">
            <Button onClick={onClose} disabled={busy === 'change'}>Cancel</Button>
            <Button variant="primary" disabled={!p || p.blocked !== null || preview.loading} busy={busy === 'change'} busyLabel="Changing…" onClick={confirm}>
              {p?.proration && p.proration.total_minor > 0 ? `Change and pay ${money(p.proration.total_minor, currency)}` : 'Change plan'}
            </Button>
          </div>
        </div>
      )}
    </Dialog>
  );
}
