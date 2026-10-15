import { useCallback, useEffect, useState, type FormEvent } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import { errorMessage, storefrontFetch, validationErrors, StorefrontApiError } from '@/Storefront/api';
import type { StorefrontPageProps } from '@/Storefront/types';
import { useT, type Translate } from '@/Storefront/i18n';
import { formatDate } from '@/lib/datetime';
import { money } from '@/lib/money';

const input = 'w-full rounded-sf border border-sf-border bg-sf-bg px-3 py-2';

type Balance = { pending_minor: number; payable_minor: number; paid_minor: number; in_payout_minor: number };
type PayoutDetails = { method: string | null; account_title: string | null; account: string | null; bank: string | null };
export type Portal = {
  name: string;
  status: 'pending' | 'active' | 'paused' | 'rejected';
  code: string;
  link: string;
  coupon: string | null;
  program: { name: string; running: boolean; summary: string[]; terms: string | null };
  stats: { clicks_30d: number; orders: number };
  balance: Balance;
  currency: string | null;
  commissions: { date: string | null; sale_minor: number; commission_minor: number; status: string; source: 'link' | 'coupon' }[];
  payouts: { date: string | null; amount_minor: number; status: 'pending' | 'paid'; reference: string | null }[];
  adjustments: { date: string | null; amount_minor: number; reason: string }[];
  payout_details: PayoutDetails;
};

const STATUS: Record<string, string> = {
  review: 'Being checked',
  pending: 'Pending',
  approved: 'Approved',
  paid: 'Paid',
  rejected: 'Not paid',
  reversed: 'Refunded',
};
const METHOD: Record<string, string> = { bank_transfer: 'Bank transfer', jazzcash: 'JazzCash', easypaisa: 'Easypaisa' };
const NO_TOKEN = 'no-token';
const GONE = 'gone';

/**
 * Phase B57 (Module 28 §43 "affiliate dashboard", §76, §135) — the
 * affiliate's private page, opened from the link in their email. The
 * token is in the link's #fragment, which browsers never send: the page
 * reads it and sends it to the API in a header. It shows their link and
 * earnings, never who the customers were.
 */
export default function AffiliatePortal({ storefront, seo }: StorefrontPageProps) {
  const t = useT();
  const [token, setToken] = useState<string | null>(null);
  const [data, setData] = useState<Portal | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const read = () => setToken(new URLSearchParams(window.location.hash.slice(1)).get('token') ?? '');
    read();
    window.addEventListener('hashchange', read);

    return () => window.removeEventListener('hashchange', read);
  }, []);

  const load = useCallback(() => {
    if (token === null) return;
    if (token === '') {
      setError(NO_TOKEN);

      return;
    }
    setError(null);
    storefrontFetch<{ data: Portal }>(storefront, '/storefront/affiliate', { headers: { 'X-Affiliate-Token': token } })
      .then((res) => setData(res.data))
      .catch((e) => setError(e instanceof StorefrontApiError && e.status === 404 ? GONE : errorMessage(e)));
    // t() is a new function each render: messages are translated when shown, not here.
  }, [storefront, token]);

  useEffect(load, [load]);

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="mx-auto max-w-4xl">
        {error ? (
          <div className="rounded-sf border border-sf-border bg-sf-surface p-6" role="alert">
            <h1 className="text-xl font-semibold">{t('Your affiliate page')}</h1>
            <p className="mt-2 text-sf-muted">{error === NO_TOKEN ? t('Open this page from the link in your email.') : error === GONE ? t('This private link does not work any more. Ask the store for a new one.') : error}</p>
          </div>
        ) : data === null ? (
          <p role="status" className="text-sf-muted">{t('Loading…')}</p>
        ) : (
          <PortalView data={data} token={token ?? ''} t={t} storefront={storefront} onSaved={load} />
        )}
      </div>
    </StoreLayout>
  );
}

function PortalView({ data, token, t, storefront, onSaved }: { data: Portal; token: string; t: Translate; storefront: StorefrontPageProps['storefront']; onSaved: () => void }) {
  const [copied, setCopied] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const currency = data.currency ?? 'PKR';
  const copy = (text: string, what: string) => {
    void navigator.clipboard?.writeText(text).then(() => setCopied(what));
  };

  return (
    <div className="space-y-8">
      <header>
        <p className="text-sm font-medium uppercase tracking-wider text-sf-muted">{data.program.name}</p>
        <h1 className="mt-1 text-3xl font-bold">{t('Hello, {name}', { name: data.name })}</h1>
        {data.status === 'paused' && <p className="mt-2 rounded-sf bg-sf-surface p-3 text-sm">{t('Your account is paused: new visits and orders do not count for now. Contact the store if you have questions.')}</p>}
        {data.status === 'active' && !data.program.running && <p className="mt-2 rounded-sf bg-sf-surface p-3 text-sm">{t('The store has paused its program: new visits and orders do not count for now. What you earned is still paid.')}</p>}
      </header>

      {data.status === 'active' && (
        <section aria-labelledby="link" className="rounded-sf border border-sf-border p-5">
          <h2 id="link" className="text-lg font-semibold">{t('Your link')}</h2>
          <p className="mt-1 text-sm text-sf-muted">{t('Share it anywhere. Orders placed within the attribution window after a visit through it count for you.')}</p>
          <div className="mt-3 flex flex-col gap-2 sm:flex-row">
            <input readOnly value={data.link} aria-label={t('Your link')} onFocus={(e) => e.currentTarget.select()} className={`${input} font-mono text-sm`} />
            <button type="button" onClick={() => copy(data.link, 'link')} className="sf-btn rounded-sf bg-sf-primary px-4 py-2 font-medium text-white">
              {copied === 'link' ? t('Copied') : t('Copy link')}
            </button>
          </div>
          <p className="mt-3 text-sm">
            {t('Your code')}: <span className="font-mono font-semibold">{data.code}</span>
            {data.coupon && (
              <>
                {' · '}
                {t('Coupon code for your followers')}: <span className="font-mono font-semibold">{data.coupon}</span>{' '}
                <button type="button" onClick={() => copy(data.coupon ?? '', 'coupon')} className="text-sf-accent underline">
                  {copied === 'coupon' ? t('Copied') : t('Copy')}
                </button>
              </>
            )}
          </p>
        </section>
      )}

      <section aria-labelledby="numbers">
        <h2 id="numbers" className="sr-only">{t('Your results')}</h2>
        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
          <Stat label={t('Visits (30 days)')} value={data.stats.clicks_30d.toLocaleString()} />
          <Stat label={t('Orders')} value={data.stats.orders.toLocaleString()} />
          <Stat label={t('Pending')} value={money(data.balance.pending_minor, currency)} />
          <Stat label={t('Ready to be paid')} value={money(data.balance.payable_minor + data.balance.in_payout_minor, currency)} strong />
          <Stat label={t('Paid')} value={money(data.balance.paid_minor, currency)} />
        </dl>
        <p className="mt-2 text-xs text-sf-muted">{t('A commission is pending until the order is delivered and paid and the return period has passed. Refunds reduce it.')}</p>
      </section>

      <section aria-labelledby="earned">
        <h2 id="earned" className="text-lg font-semibold">{t('Your commissions')}</h2>
        {data.commissions.length === 0 ? (
          <p className="mt-2 text-sm text-sf-muted">{t('No orders yet. Share your link to get started.')}</p>
        ) : (
          <div className="mt-3 overflow-x-auto rounded-sf border border-sf-border">
            <table className="min-w-full text-sm">
              <thead className="bg-sf-surface text-start text-xs uppercase tracking-wider text-sf-muted">
                <tr>
                  <th scope="col" className="px-3 py-2 text-start">{t('Date')}</th>
                  <th scope="col" className="hidden px-3 py-2 text-start sm:table-cell">{t('Through')}</th>
                  <th scope="col" className="px-3 py-2 text-end">{t('Order value')}</th>
                  <th scope="col" className="px-3 py-2 text-end">{t('Your commission')}</th>
                  <th scope="col" className="px-3 py-2 text-start">{t('Status')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-sf-border">
                {data.commissions.map((c, i) => (
                  <tr key={i}>
                    <td className="px-3 py-2">{c.date ? formatDate(c.date) : '—'}</td>
                    <td className="hidden px-3 py-2 sm:table-cell">{c.source === 'coupon' ? t('Coupon') : t('Link')}</td>
                    <td className="px-3 py-2 text-end tabular-nums">{money(c.sale_minor, currency)}</td>
                    <td className="px-3 py-2 text-end font-medium tabular-nums">{money(c.commission_minor, currency)}</td>
                    <td className="px-3 py-2">{t(STATUS[c.status] ?? 'Pending')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {data.adjustments.length > 0 && (
        <section aria-labelledby="adjustments">
          <h2 id="adjustments" className="text-lg font-semibold">{t('Adjustments')}</h2>
          <ul className="mt-2 divide-y divide-sf-border text-sm">
            {data.adjustments.map((a, i) => (
              <li key={i} className="flex justify-between gap-3 py-2">
                <span>{a.reason}<span className="block text-xs text-sf-muted">{a.date ? formatDate(a.date) : ''}</span></span>
                <span className="tabular-nums">{money(a.amount_minor, currency)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section aria-labelledby="payouts" className="grid gap-6 md:grid-cols-2">
        <div>
          <h2 id="payouts" className="text-lg font-semibold">{t('Payouts')}</h2>
          {data.payouts.length === 0 ? (
            <p className="mt-2 text-sm text-sf-muted">{t('No payouts yet.')}</p>
          ) : (
            <ul className="mt-2 divide-y divide-sf-border text-sm">
              {data.payouts.map((p, i) => (
                <li key={i} className="flex justify-between gap-3 py-2">
                  <span>
                    {p.date ? formatDate(p.date) : '—'} · {p.status === 'paid' ? t('Paid') : t('Being paid')}
                    {p.reference && <span className="block font-mono text-xs text-sf-muted">{p.reference}</span>}
                  </span>
                  <span className="font-medium tabular-nums">{money(p.amount_minor, currency)}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="rounded-sf border border-sf-border p-4">
          <h2 className="text-lg font-semibold">{t('Where we pay you')}</h2>
          {editing ? (
            <PayoutForm storefront={storefront} token={token} current={data.payout_details} t={t} onDone={() => { setEditing(false); onSaved(); }} onCancel={() => setEditing(false)} />
          ) : (
            <>
              {data.payout_details.method ? (
                <p className="mt-2 text-sm">
                  {t(METHOD[data.payout_details.method] ?? data.payout_details.method)} · {data.payout_details.account_title}
                  <span className="block font-mono text-sf-muted">{data.payout_details.account}</span>
                  {data.payout_details.bank && <span className="block text-sf-muted">{data.payout_details.bank}</span>}
                </p>
              ) : (
                <p className="mt-2 text-sm text-sf-muted">{t('Add your account so the store can pay you.')}</p>
              )}
              <button type="button" onClick={() => setEditing(true)} className="mt-3 rounded-sf border border-sf-border px-3 py-1.5 text-sm">
                {data.payout_details.method ? t('Change') : t('Add account')}
              </button>
            </>
          )}
        </div>
      </section>

      {data.program.summary.length > 0 && (
        <section aria-labelledby="rules" className="rounded-sf bg-sf-surface p-5 text-sm">
          <h2 id="rules" className="font-semibold">{t('How you earn')}</h2>
          <ul className="mt-2 list-disc space-y-1 ps-5 text-sf-muted">
            {data.program.summary.map((line) => <li key={line}>{line}</li>)}
          </ul>
          {data.program.terms && (
            <details className="mt-3">
              <summary className="cursor-pointer font-medium">{t('Program terms')}</summary>
              <p className="mt-2 whitespace-pre-line text-sf-muted">{data.program.terms}</p>
            </details>
          )}
        </section>
      )}
      <p className="text-xs text-sf-muted">{t('This page is private: anyone with its link sees your earnings. Do not share it; ask the store for a new link if it was shared.')}</p>
    </div>
  );
}

function Stat({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="rounded-sf border border-sf-border p-3 sm:p-4">
      <dt className="text-xs text-sf-muted">{label}</dt>
      <dd className={`mt-1 text-lg tabular-nums ${strong ? 'font-bold' : 'font-semibold'}`}>{value}</dd>
    </div>
  );
}

function PayoutForm({ storefront, token, current, t, onDone, onCancel }: { storefront: StorefrontPageProps['storefront']; token: string; current: PayoutDetails; t: Translate; onDone: () => void; onCancel: () => void }) {
  const [form, setForm] = useState({ method: current.method ?? 'jazzcash', account_title: current.account_title ?? '', account_number: '', bank: current.bank ?? '' });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (field: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [field]: e.target.value });

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      await storefrontFetch(storefront, '/storefront/affiliate/payout-details', {
        method: 'PUT',
        body: { ...form, bank: form.method === 'bank_transfer' ? form.bank : null },
        headers: { 'X-Affiliate-Token': token },
      });
      onDone();
    } catch (e) {
      setErrors(validationErrors(e));
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }
  const fieldError = (field: string) => errors[field]?.[0] && <span className="mt-1 block text-sf-error">{errors[field][0]}</span>;

  return (
    <form onSubmit={submit} className="mt-3 space-y-3 text-sm">
      <label className="block">
        <span className="mb-1 block text-sf-muted">{t('How to pay you')}</span>
        <select value={form.method} onChange={set('method')} className={input}>
          {Object.entries(METHOD).map(([value, label]) => <option key={value} value={value}>{t(label)}</option>)}
        </select>
      </label>
      <label className="block">
        <span className="mb-1 block text-sf-muted">{t('Account title')}</span>
        <input required maxLength={120} value={form.account_title} onChange={set('account_title')} className={input} autoComplete="name" />
        {fieldError('account_title')}
      </label>
      <label className="block">
        <span className="mb-1 block text-sf-muted">{form.method === 'bank_transfer' ? t('IBAN or account number') : t('Mobile account number')}</span>
        <input required maxLength={40} value={form.account_number} onChange={set('account_number')} className={input} autoComplete="off" inputMode={form.method === 'bank_transfer' ? 'text' : 'tel'} />
        {fieldError('account_number')}
      </label>
      {form.method === 'bank_transfer' && (
        <label className="block">
          <span className="mb-1 block text-sf-muted">{t('Bank')}</span>
          <input required maxLength={80} value={form.bank} onChange={set('bank')} className={input} />
          {fieldError('bank')}
        </label>
      )}
      {error && Object.keys(errors).length === 0 && <p role="alert" className="text-sf-error">{error}</p>}
      <p className="text-xs text-sf-muted">{t('Kept encrypted. The store sees the full account only when it pays you.')}</p>
      <div className="flex gap-2">
        <button type="submit" disabled={busy} className="sf-btn rounded-sf bg-sf-primary px-4 py-2 font-medium text-white disabled:opacity-60">{busy ? t('Saving…') : t('Save')}</button>
        <button type="button" onClick={onCancel} className="rounded-sf border border-sf-border px-4 py-2">{t('Cancel')}</button>
      </div>
    </form>
  );
}
