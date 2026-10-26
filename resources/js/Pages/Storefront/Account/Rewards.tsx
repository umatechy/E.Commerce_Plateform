import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import { formatDate } from '@/lib/datetime';
import { formatMoney } from '@/lib/money';
import { errorMessage, storefrontFetch, validationErrors } from '@/Storefront/api';
import { useCustomer } from '@/Storefront/account';
import { useT } from '@/Storefront/i18n';
import { howItWorks, LOYALTY_LABELS, usablePoints, valueOf, type CustomerLoyalty } from '@/Storefront/loyalty';
import type { StorefrontPageProps } from '@/Storefront/types';

function newKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/**
 * Phase P2: the customer's points — what they have and are worth, their
 * tier and the way to the next, turning points into store credit in one
 * step, how points are earned, and every change.
 */
export default function Rewards({ storefront, seo }: StorefrontPageProps) {
  const t = useT();
  const { customer } = useCustomer(storefront, { required: true });
  const [data, setData] = useState<CustomerLoyalty | null>(null);
  const [failed, setFailed] = useState<string | null>(null);

  useEffect(() => {
    if (!customer) return;
    storefrontFetch<{ data: CustomerLoyalty }>(storefront, '/customer/loyalty')
      .then((res) => setData(res.data))
      .catch((e) => setFailed(errorMessage(e)));
  }, [customer, storefront]);

  const program = data?.program ?? null;
  const currency = data?.currency ?? program?.currency ?? storefront.store.currency;
  const money = (minor: number) => formatMoney(minor, currency);

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account/rewards" title={t('Rewards')}>
      {failed && <p className="text-sf-error" role="alert">{failed}</p>}
      {!data && !failed && <p className="text-sm text-sf-muted">{t('Loading…')}</p>}
      {data && program === null && <p className="text-sm text-sf-muted">{t('This store has no rewards program at the moment.')}</p>}
      {data && program && (
        <div className="space-y-8">
          <section className="sf-rewards-hero relative overflow-hidden rounded-sf border border-sf-border bg-sf-surface p-6" aria-label={t('Your points')}>
            <div className="flex flex-wrap items-end justify-between gap-4">
              <div>
                <p className="text-sm text-sf-muted">{t('Your points')}</p>
                <p className="mt-1 text-4xl font-bold tabular-nums">{data.balance.toLocaleString()}</p>
                {data.value_minor > 0 && <p className="mt-1 text-sm">{t('Worth {amount} of store credit', { amount: money(data.value_minor) })}</p>}
              </div>
              {data.tier && (
                <p className="rounded-full bg-sf-primary px-3 py-1 text-sm font-semibold text-white">
                  {t('{tier} member', { tier: data.tier.name })}
                </p>
              )}
            </div>
            {data.next_tier && <TierProgress data={data} />}
            <div className="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-sf-muted">
              {data.pending_points > 0 && <span>{t('{points} more points on the way from orders not yet delivered.', { points: data.pending_points.toLocaleString() })}</span>}
              {data.expires_at && <span>{t('Your points expire on {date} unless you earn or use some before.', { date: formatDate(data.expires_at) })}</span>}
              {!program.earning && <span>{t('The store is not giving new points at the moment. You can still use yours.')}</span>}
            </div>
          </section>

          <Redeem storefront={storefront} data={data} money={money} onDone={setData} />

          <section>
            <h2 className="mb-3 text-xl font-semibold">{t('How it works')}</h2>
            <ul className="space-y-2 text-sm">
              {howItWorks(t, program, money).map((line) => (
                <li key={line} className="flex gap-2">
                  <span aria-hidden="true" className="text-sf-accent">★</span>
                  <span>{line}</span>
                </li>
              ))}
            </ul>
            {program.birthday_points > 0 && !customer?.birthday && (
              <p className="mt-3 text-sm">
                <Link href={`${storefront.base_path}/account/profile`} className="font-medium text-sf-accent underline">
                  {t('Add your birthday to get your birthday gift')}
                </Link>
              </p>
            )}
            {program.tiers.length > 0 && (
              <ol className="mt-4 grid gap-3 sm:grid-cols-3">
                {program.tiers.map((tier) => {
                  const current = data.tier?.name === tier.name;
                  return (
                    <li key={tier.name} className={`rounded-sf border p-4 text-sm ${current ? 'border-sf-primary ring-2 ring-sf-primary' : 'border-sf-border'}`} aria-current={current ? 'true' : undefined}>
                      <p className="font-semibold">{tier.name}</p>
                      <p className="text-sf-muted">{t('From {points} points earned', { points: tier.min_points.toLocaleString() })}</p>
                      {tier.bonus_percent > 0 && <p className="mt-1 font-medium">{t('{percent}% extra points on every order', { percent: tier.bonus_percent })}</p>}
                    </li>
                  );
                })}
              </ol>
            )}
          </section>

          <section>
            <h2 className="mb-3 text-xl font-semibold">{t('History')}</h2>
            {data.entries.length === 0 ? (
              <p className="text-sm text-sf-muted">{t('No points yet. Your next order earns some.')}</p>
            ) : (
              <ul className="divide-y divide-sf-border text-sm">
                {data.entries.map((entry) => (
                  <li key={entry.id} className="flex justify-between gap-3 py-2">
                    <span>
                      <span className="font-medium">{t(LOYALTY_LABELS[entry.type] ?? 'Changed')}</span>
                      <span className="block text-sf-muted">{formatDate(entry.created_at)}{entry.note && entry.type !== 'redeemed' ? ` · ${entry.note}` : ''}</span>
                    </span>
                    <span className={`whitespace-nowrap font-semibold tabular-nums ${entry.points < 0 ? 'text-sf-error' : 'text-sf-success'}`}>
                      {entry.points < 0 ? '−' : '+'}{Math.abs(entry.points).toLocaleString()}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      )}
    </AccountLayout>
  );
}

function TierProgress({ data }: { data: CustomerLoyalty }) {
  const t = useT();
  const next = data.next_tier!;
  const from = data.tier?.min_points ?? 0;
  const share = Math.min(100, Math.max(4, Math.round(((data.lifetime_points - from) / Math.max(1, next.min_points - from)) * 100)));

  return (
    <div className="mt-5">
      <div className="h-2 overflow-hidden rounded-full bg-sf-border" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={share} aria-label={t('Progress to {tier}', { tier: next.name })}>
        <div className="h-full rounded-full bg-sf-primary transition-[width] duration-700 motion-reduce:transition-none" style={{ width: `${share}%` }} />
      </div>
      <p className="mt-2 text-sm text-sf-muted">{t('{points} points to {tier}', { points: next.points_to_go.toLocaleString(), tier: next.name })}</p>
    </div>
  );
}

function Redeem({ storefront, data, money, onDone }: { storefront: StorefrontPageProps['storefront']; data: CustomerLoyalty; money: (minor: number) => string; onDone: (next: CustomerLoyalty) => void }) {
  const t = useT();
  const program = data.program!;
  const most = usablePoints(program, data.balance);
  const [points, setPoints] = useState(most);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null);
  const key = useMemo(newKey, [data.balance]); // a new key once the balance changed: a double click turns points once
  useEffect(() => setPoints(most), [most]);

  async function redeem() {
    setBusy(true);
    setNotice(null);
    try {
      const res = await storefrontFetch<{ data: CustomerLoyalty & { credited_minor: number } }>(storefront, '/customer/loyalty/redeem', { method: 'POST', body: { points, idempotency_key: key } });
      onDone(res.data);
      setNotice({ ok: true, text: t('{amount} was added to your store credit. It is used at checkout.', { amount: money(res.data.credited_minor) }) });
    } catch (e) {
      setNotice({ ok: false, text: Object.values(validationErrors(e))[0]?.[0] ?? errorMessage(e) });
    } finally {
      setBusy(false);
    }
  }

  const step = program.redeem_points;

  return (
    <section className="rounded-sf border border-sf-border p-5" aria-label={t('Use your points')}>
      <h2 className="text-xl font-semibold">{t('Use your points')}</h2>
      {most === 0 ? (
        <p className="mt-2 text-sm text-sf-muted">{t('You can use points from {min} points. You need {more} more.', { min: program.min_redeem_points.toLocaleString(), more: Math.max(0, program.min_redeem_points - data.balance).toLocaleString() })}</p>
      ) : (
        <div className="mt-3 flex flex-wrap items-center gap-3">
          <div className="flex items-center rounded-sf border border-sf-border">
            <button type="button" className="px-3 py-2 text-lg disabled:opacity-40" onClick={() => setPoints(Math.max(program.min_redeem_points, points - step))} disabled={points - step < program.min_redeem_points} aria-label={t('Fewer points')}>−</button>
            <span className="min-w-24 px-2 text-center font-semibold tabular-nums" aria-live="polite">{t('{points} points', { points: points.toLocaleString() })}</span>
            <button type="button" className="px-3 py-2 text-lg disabled:opacity-40" onClick={() => setPoints(Math.min(most, points + step))} disabled={points + step > most} aria-label={t('More points')}>+</button>
          </div>
          <button type="button" onClick={redeem} disabled={busy} className="sf-btn rounded-sf bg-sf-primary px-5 py-2.5 font-semibold text-white disabled:opacity-60">
            {busy ? t('Please wait…') : t('Get {amount} store credit', { amount: money(valueOf(program, points)) })}
          </button>
        </div>
      )}
      {notice && (
        <p className={`mt-3 text-sm ${notice.ok ? 'text-sf-success' : 'text-sf-error'}`} role={notice.ok ? 'status' : 'alert'}>
          {notice.text}
        </p>
      )}
    </section>
  );
}
