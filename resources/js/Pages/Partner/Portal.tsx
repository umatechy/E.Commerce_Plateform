import { useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import { Card, EmptyPanel, ErrorPanel, Skeleton, StatCard, Tabs } from '@/Components/ui/Page';
import Dialog from '@/Components/ui/Dialog';
import Badge, { type Tone } from '@/Components/ui/Badge';
import Button, { ButtonLink } from '@/Components/ui/Button';
import { CheckboxField, FormError, SelectField, TextField } from '@/Components/ui/Form';
import { AdminApiError, adminFetch, fieldErrors, idempotencyKey } from '@/lib/adminApi';
import { useApi } from '@/lib/useApi';
import { formatDate } from '@/lib/datetime';
import { money } from '@/lib/money';
import { toast } from '@/Components/ui/toast';

/**
 * Phase B58 (Module 28 §44 "reseller dashboard", §30, §96) — the partner
 * portal of an Umar Techy reseller: its terms and sign-up link, the stores
 * it brought (their state, never their business data), commissions,
 * payouts and where it is paid, and creating a store for a client when the
 * tier allows. Every call is checked again on the server.
 */
type Balance = { pending_minor: number; payable_minor: number; in_payout_minor: number; paid_minor: number };
type Payout = { method: string | null; account_title: string | null; account: string | null; bank: string | null };
type Overview = {
  business_name: string;
  contact_name: string;
  status: 'active' | 'paused';
  code: string;
  signup_link: string;
  tier: null | {
    name: string; client_discount_percent: number; initial_commission_percent: number; recurring_commission_percent: number;
    recurring_months: number | null; hold_days: number; can_provision: boolean; stores_left: number | null; payout_threshold_minor: number;
  };
  stats: { stores: number; paying: number; trialing: number };
  balances: Record<string, Balance>;
  payout_details: Payout;
  business_categories: { value: string; label: string }[];
};
type StoreRow = {
  id: string; store: string; store_status: string; package: string | null; subscription: string | null; trial_ends_at: string | null;
  owner_joined: boolean; source: 'provisioned' | 'link' | 'manual'; since: string; ended_at: string | null; client_discount_percent: number; earned_minor: number; currency: string | null;
};
type Commission = { id: string; date: string; store: string; kind: 'initial' | 'renewal' | 'upgrade'; base_minor: number; rate_percent: number; amount_minor: number; currency: string; status: string; hold_until: string | null };
type PayoutRow = { date: string; amount_minor: number; currency: string; status: 'pending' | 'paid'; reference: string | null };
type PackageOption = { code: string; name: string; prices: { interval: string; currency: string; public_minor: number; partner_minor: number; first_public_minor?: number | null; first_partner_minor?: number | null }[] };

const SUBSCRIPTION: Record<string, [string, Tone]> = {
  trialing: ['Trial', 'blue'], active: ['Paying', 'green'], past_due: ['Payment due', 'amber'], grace_period: ['Payment due', 'amber'],
  suspended: ['Suspended', 'red'], cancelled: ['Cancelled', 'neutral'], expired: ['Ended', 'neutral'], pending: ['Starting', 'neutral'],
};
const SOURCE: Record<StoreRow['source'], string> = { provisioned: 'Created by you', link: 'Your sign-up link', manual: 'Assigned by Umar Techy' };
const COMMISSION: Record<string, [string, Tone]> = {
  pending: ['On hold', 'blue'], approved: ['Approved', 'green'], paid: ['Paid', 'neutral'], rejected: ['Not paid', 'red'], reversed: ['Refunded', 'red'],
};
const KIND: Record<Commission['kind'], string> = { initial: 'First invoice', renewal: 'Renewal', upgrade: 'Upgrade' };
const METHOD: Record<string, string> = { bank_transfer: 'Bank transfer', jazzcash: 'JazzCash', easypaisa: 'Easypaisa' };

export default function Portal() {
  const overview = useApi<{ data: Overview }>('/partner/overview');
  const [tab, setTab] = useState('stores');
  const [creating, setCreating] = useState(false);
  // A new store: the list is loaded again (a fresh StoresTab).
  const [storesVersion, setStoresVersion] = useState(0);
  const o = overview.data?.data;

  return (
    <AdminPage title="Partner portal" description="Your clients’ stores, your commissions and payouts as an Umar Techy partner.">
      {overview.error ? (
        <PortalRefusal message={overview.error} code={overview.errorCode} onRetry={overview.reload} />
      ) : o === undefined ? (
        <Skeleton lines={8} />
      ) : (
        <div className="space-y-6">
          <Summary o={o} onCreate={() => setCreating(true)} />
          <Tabs
            label="Partner sections"
            active={tab}
            onChange={setTab}
            tabs={[
              { id: 'stores', label: `Stores (${o.stats.stores})` },
              { id: 'commissions', label: 'Commissions' },
              { id: 'payouts', label: 'Payouts' },
              { id: 'terms', label: 'Terms & payment' },
            ]}
          />
          {tab === 'stores' && <StoresTab key={storesVersion} o={o} onCreate={() => setCreating(true)} />}
          {tab === 'commissions' && <CommissionsTab />}
          {tab === 'payouts' && <PayoutsTab />}
          {tab === 'terms' && <TermsTab o={o} onSaved={overview.reload} />}
          {creating && <CreateStoreDialog o={o} onClose={() => setCreating(false)} onDone={() => { setCreating(false); overview.reload(); setStoresVersion((v) => v + 1); setTab('stores'); }} />}
        </div>
      )}
    </AdminPage>
  );
}

/** The API refuses with a reason: two-step sign-in missing, or no open partner account. */
function PortalRefusal({ message, code, onRetry }: { message: string; code: string | null; onRetry: () => void }) {
  return code === 'mfa_enrollment_required' ? (
    <Card title="Turn on two-step sign-in">
      <p className="text-sm text-slate-700">{message} Partners create stores and are paid, so the portal asks for a code from your phone at every sign-in.</p>
      <div className="mt-4"><ButtonLink href="/security" variant="primary">Go to Security</ButtonLink></div>
    </Card>
  ) : (
    <ErrorPanel message={message} onRetry={onRetry} />
  );
}

function Summary({ o, onCreate }: { o: Overview; onCreate: () => void }) {
  const [copied, setCopied] = useState(false);
  const currencies = Object.keys(o.balances);
  const main = currencies[0];
  const b = main ? o.balances[main] : null;

  return (
    <>
      {o.status === 'paused' && (
        <p className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
          Your partner account is paused: you cannot create stores and new sign-ups through your link do not count for now. Your commissions continue. Contact Umar Techy for details.
        </p>
      )}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Stores" value={o.stats.stores.toLocaleString()} hint={`${o.stats.paying} paying · ${o.stats.trialing} in trial`} />
        <StatCard label="On hold" value={b ? money(b.pending_minor, main) : '—'} hint="Until the hold period ends" />
        <StatCard label="To be paid" value={b ? money(b.payable_minor + b.in_payout_minor, main) : '—'} hint={currencies.length > 1 ? `Also in ${currencies.slice(1).join(', ')}` : 'Approved commissions'} />
        <StatCard label="Paid to you" value={b ? money(b.paid_minor, main) : '—'} />
      </div>
      <Card
        title="Your sign-up link"
        description="Clients who create their store through this link count for you and get your partner price."
        actions={o.tier?.can_provision && o.status === 'active' ? <Button variant="primary" onClick={onCreate} disabled={o.tier.stores_left === 0}>Create a store for a client</Button> : undefined}
      >
        <div className="flex flex-col gap-2 sm:flex-row">
          <input readOnly value={o.signup_link} aria-label="Your sign-up link" onFocus={(e) => e.currentTarget.select()} className="min-w-0 flex-1 rounded-md border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-sm" />
          <Button onClick={() => void navigator.clipboard?.writeText(o.signup_link).then(() => { setCopied(true); toast.success('Link copied.'); })}>{copied ? 'Copied' : 'Copy link'}</Button>
        </div>
        <p className="mt-2 text-xs text-slate-500">Your partner code: <span className="font-mono font-semibold">{o.code}</span>{o.tier?.stores_left != null ? ` · ${o.tier.stores_left} more stores you can create` : ''}</p>
      </Card>
    </>
  );
}

function StoresTab({ o, onCreate }: { o: Overview; onCreate: () => void }) {
  const list = useApi<{ data: StoreRow[] }>('/partner/stores');
  const rows = list.data?.data ?? [];

  if (list.error) return <ErrorPanel message={list.error} onRetry={list.reload} />;
  if (list.data === null) return <Skeleton lines={5} />;
  if (rows.length === 0) {
    return (
      <EmptyPanel
        title="No stores yet"
        description="Share your sign-up link with clients, or create a store for a client. Stores appear here as soon as they are created."
        action={o.tier?.can_provision && o.status === 'active' ? <Button variant="primary" onClick={onCreate}>Create a store for a client</Button> : undefined}
      />
    );
  }

  return (
    <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <caption className="sr-only">Your clients’ stores</caption>
        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
          <tr>
            <th scope="col" className="px-4 py-3">Store</th>
            <th scope="col" className="px-4 py-3">Package</th>
            <th scope="col" className="px-4 py-3">Owner</th>
            <th scope="col" className="px-4 py-3">How</th>
            <th scope="col" className="px-4 py-3 text-right">You earned</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((s) => {
            const [label, tone] = SUBSCRIPTION[s.subscription ?? ''] ?? ['—', 'neutral'];
            return (
              <tr key={s.id} className={s.ended_at ? 'opacity-60' : undefined}>
                <td className="px-4 py-3">
                  <span className="block font-medium text-slate-900">{s.store}</span>
                  <span className="block text-xs text-slate-500">Since {formatDate(s.since)}{s.ended_at ? ` · no longer yours since ${formatDate(s.ended_at)}` : ''}</span>
                </td>
                <td className="px-4 py-3">
                  <span className="block">{s.package ?? '—'}</span>
                  <span className="mt-0.5 inline-flex flex-wrap gap-1">
                    <Badge tone={tone}>{label}</Badge>
                    {s.subscription === 'trialing' && s.trial_ends_at && <span className="text-xs text-slate-500">until {formatDate(s.trial_ends_at)}</span>}
                  </span>
                </td>
                <td className="px-4 py-3">{s.owner_joined ? <Badge tone="green">Joined</Badge> : <Badge tone="amber">Invited</Badge>}</td>
                <td className="px-4 py-3 text-slate-600">
                  {SOURCE[s.source]}
                  {s.client_discount_percent > 0 && <span className="block text-xs text-slate-500">Partner price: {s.client_discount_percent}% off</span>}
                </td>
                <td className="px-4 py-3 text-right tabular-nums">{s.currency ? money(s.earned_minor, s.currency) : '—'}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function CommissionsTab() {
  const list = useApi<{ data: Commission[] }>('/partner/commissions');
  const rows = list.data?.data ?? [];

  if (list.error) return <ErrorPanel message={list.error} onRetry={list.reload} />;
  if (list.data === null) return <Skeleton lines={5} />;
  if (rows.length === 0) return <EmptyPanel title="No commissions yet" description="When a client pays an Umar Techy invoice, your commission appears here." />;

  return (
    <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <caption className="sr-only">Your commissions</caption>
        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
          <tr>
            <th scope="col" className="px-4 py-3">Date</th>
            <th scope="col" className="px-4 py-3">Store</th>
            <th scope="col" className="px-4 py-3">For</th>
            <th scope="col" className="px-4 py-3 text-right">Invoice</th>
            <th scope="col" className="px-4 py-3 text-right">Commission</th>
            <th scope="col" className="px-4 py-3">Status</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((c) => {
            const [label, tone] = COMMISSION[c.status] ?? [c.status, 'neutral'];
            return (
              <tr key={c.id}>
                <td className="px-4 py-3 text-slate-600">{formatDate(c.date)}</td>
                <td className="px-4 py-3 font-medium text-slate-900">{c.store}</td>
                <td className="px-4 py-3 text-slate-600">{KIND[c.kind]} · {c.rate_percent}%</td>
                <td className="px-4 py-3 text-right tabular-nums">{money(c.base_minor, c.currency)}</td>
                <td className="px-4 py-3 text-right font-medium tabular-nums">{money(c.amount_minor, c.currency)}</td>
                <td className="px-4 py-3">
                  <Badge tone={tone}>{label}</Badge>
                  {c.status === 'pending' && c.hold_until && <span className="block text-xs text-slate-500">until {formatDate(c.hold_until)}</span>}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function PayoutsTab() {
  const list = useApi<{ data: PayoutRow[] }>('/partner/payouts');
  const rows = list.data?.data ?? [];

  if (list.error) return <ErrorPanel message={list.error} onRetry={list.reload} />;
  if (list.data === null) return <Skeleton lines={4} />;
  if (rows.length === 0) return <EmptyPanel title="No payouts yet" description="Umar Techy pays approved commissions to the account under Terms & payment." />;

  return (
    <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
      {rows.map((p, i) => (
        <li key={i} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
          <span>
            {formatDate(p.date)} · <Badge tone={p.status === 'paid' ? 'green' : 'amber'}>{p.status === 'paid' ? 'Paid' : 'Being paid'}</Badge>
            {p.reference && <span className="mt-0.5 block font-mono text-xs text-slate-500">Reference {p.reference}</span>}
          </span>
          <span className="font-semibold tabular-nums">{money(p.amount_minor, p.currency)}</span>
        </li>
      ))}
    </ul>
  );
}

function TermsTab({ o, onSaved }: { o: Overview; onSaved: () => void }) {
  const t = o.tier;
  const [editing, setEditing] = useState(false);

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Card title={t ? `Your terms: ${t.name}` : 'Your terms'} description="Each store keeps the terms of the day it became yours.">
        {t ? (
          <ul className="space-y-2 text-sm text-slate-700">
            <li>Your clients pay <strong>{t.client_discount_percent}% less</strong> than the public price.</li>
            <li>You earn <strong>{t.initial_commission_percent}%</strong> of a client’s first paid invoice.</li>
            <li>
              Then <strong>{t.recurring_commission_percent}%</strong> of renewals and upgrades
              {t.recurring_months === null ? ' for as long as the client pays' : t.recurring_months === 0 ? ' — none: first invoice only' : ` for ${t.recurring_months} months`}.
            </li>
            <li>A commission is approved {t.hold_days} days after the invoice is paid. Refunds take back their share.</li>
            <li>{t.can_provision ? `You can create stores for clients${t.stores_left != null ? ` (${t.stores_left} left)` : ''}.` : 'Clients create their stores through your link.'}</li>
            {t.payout_threshold_minor > 0 && <li>Payouts start from {money(t.payout_threshold_minor, Object.keys(o.balances)[0] ?? 'PKR')}.</li>}
          </ul>
        ) : (
          <p className="text-sm text-slate-600">No terms yet.</p>
        )}
      </Card>
      <Card title="Where we pay you" actions={!editing ? <Button size="sm" onClick={() => setEditing(true)}>{o.payout_details.method ? 'Change' : 'Add'}</Button> : undefined}>
        {editing ? (
          <PayoutForm current={o.payout_details} onDone={() => { setEditing(false); onSaved(); }} onCancel={() => setEditing(false)} />
        ) : o.payout_details.method ? (
          <dl className="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt className="text-slate-500">Method</dt><dd>{METHOD[o.payout_details.method] ?? o.payout_details.method}</dd></div>
            <div><dt className="text-slate-500">Account title</dt><dd>{o.payout_details.account_title}</dd></div>
            <div><dt className="text-slate-500">Account</dt><dd className="font-mono">{o.payout_details.account}</dd></div>
            {o.payout_details.bank && <div><dt className="text-slate-500">Bank</dt><dd>{o.payout_details.bank}</dd></div>}
          </dl>
        ) : (
          <p className="text-sm text-slate-600">Add your account so Umar Techy can pay you.</p>
        )}
        <p className="mt-3 text-xs text-slate-500">Kept encrypted. Changing it asks for your password again.</p>
      </Card>
    </div>
  );
}

function PayoutForm({ current, onDone, onCancel }: { current: Payout; onDone: () => void; onCancel: () => void }) {
  const [form, setForm] = useState({ method: current.method ?? 'bank_transfer', account_title: current.account_title ?? '', account_number: '', bank: current.bank ?? '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }));

  function save() {
    setBusy(true);
    setErrors({});
    setError(null);
    adminFetch('/partner/payout-details', { method: 'PUT', body: { ...form, bank: form.method === 'bank_transfer' ? form.bank : null } })
      .then(() => { toast.success('Payout details saved.'); onDone(); })
      .catch((e) => { setErrors(fieldErrors(e)); setError(e instanceof Error ? e.message : 'Could not save.'); })
      .finally(() => setBusy(false));
  }

  return (
    <div className="space-y-4">
      <FormError message={Object.keys(errors).length ? null : error} />
      <SelectField label="Method" value={form.method} onChange={set('method')} options={Object.entries(METHOD).map(([value, label]) => ({ value, label }))} />
      <TextField label="Account title" value={form.account_title} onChange={set('account_title')} error={errors.account_title} maxLength={120} required />
      <TextField label={form.method === 'bank_transfer' ? 'IBAN or account number' : 'Mobile account number'} value={form.account_number} onChange={set('account_number')} error={errors.account_number} maxLength={40} required autoComplete="off" hint={current.account ? `Now: ${current.account}. Enter the full number to change it.` : undefined} />
      {form.method === 'bank_transfer' && <TextField label="Bank" value={form.bank} onChange={set('bank')} error={errors.bank} maxLength={80} required />}
      <div className="flex gap-2">
        <Button variant="primary" busy={busy} onClick={save}>Save</Button>
        <Button onClick={onCancel} disabled={busy}>Cancel</Button>
      </div>
    </div>
  );
}

function CreateStoreDialog({ o, onClose, onDone }: { o: Overview; onClose: () => void; onDone: () => void }) {
  const packages = useApi<{ data: PackageOption[] }>('/partner/packages');
  const [form, setForm] = useState({ store_name: '', business_category: '', package_code: '', owner_email: '' });
  const [template, setTemplate] = useState(true);
  const [key] = useState(() => idempotencyKey());
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [k]: value }));
  const options = packages.data?.data ?? [];
  const chosen = options.find((p) => p.code === form.package_code);

  function create() {
    setBusy(true);
    setErrors({});
    setError(null);
    adminFetch<{ message: string }>('/partner/stores', { method: 'POST', body: { ...form, starter_template: template }, headers: { 'Idempotency-Key': key } })
      .then((r) => { toast.success(r.message); onDone(); })
      .catch((e) => { setErrors(fieldErrors(e)); setError(e instanceof AdminApiError || e instanceof Error ? e.message : 'Could not create the store.'); })
      .finally(() => setBusy(false));
  }

  return (
    <Dialog
      open
      title="Create a store for a client"
      description="The client becomes the owner: we email them an invitation to take the store over. You follow it from this portal; you never get access to its customers or orders."
      onClose={onClose}
      busy={busy}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="primary" busy={busy} onClick={create}>Create store</Button>
        </>
      }
    >
      <div className="space-y-4">
        <FormError message={Object.keys(errors).length ? null : error} />
        <TextField label="Store name" value={form.store_name} onChange={set('store_name')} error={errors.store_name} maxLength={255} required />
        <SelectField label="What the client sells" value={form.business_category} onChange={set('business_category')} error={errors.business_category} placeholder="Choose…" options={o.business_categories} />
        <SelectField
          label="Package"
          value={form.package_code}
          onChange={set('package_code')}
          error={errors.package_code}
          placeholder={packages.data === null ? 'Loading…' : 'Choose…'}
          options={options.map((p) => ({ value: p.code, label: p.name }))}
        />
        {chosen && (
          <ul className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">
            {chosen.prices.map((p) => (
              <li key={`${p.interval}-${p.currency}`}>
                {p.first_partner_minor != null && p.first_public_minor != null && (
                  <span className="block">
                    One-time: <strong>{money(p.first_partner_minor, p.currency)}</strong>
                    {p.first_partner_minor < p.first_public_minor && <span className="text-slate-500"> (public price {money(p.first_public_minor, p.currency)})</span>}
                    , then{' '}
                  </span>
                )}
                {p.interval}: <strong>{money(p.partner_minor, p.currency)}</strong>
                {p.partner_minor < p.public_minor && <span className="text-slate-500"> (public price {money(p.public_minor, p.currency)})</span>}
              </li>
            ))}
            <li className="text-xs text-slate-500">The store starts with the platform’s free trial; the client pays the partner price after it.</li>
          </ul>
        )}
        <TextField label="Client’s email (the owner)" type="email" value={form.owner_email} onChange={set('owner_email')} error={errors.owner_email} maxLength={190} required />
        <CheckboxField label="Start from the ready-made categories for this kind of business" checked={template} onChange={setTemplate} />
      </div>
    </Dialog>
  );
}
