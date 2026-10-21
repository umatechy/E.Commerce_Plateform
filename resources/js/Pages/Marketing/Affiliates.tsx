import { useState, type ReactNode } from 'react';
import AdminPage from '@/Components/AdminPage';
import { Card, EmptyPanel, ErrorPanel, Skeleton, StatCard, Tabs } from '@/Components/ui/Page';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import Badge, { type Tone } from '@/Components/ui/Badge';
import Button from '@/Components/ui/Button';
import { CheckboxField, FormError, SelectField, SwitchField, TextAreaField, TextField } from '@/Components/ui/Form';
import { adminFetch, fieldErrors, idempotencyKey } from '@/lib/adminApi';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { formatDate } from '@/lib/datetime';
import { fromMinor, money, toMinor } from '@/lib/money';
import { toast } from '@/Components/ui/toast';

/**
 * Phase B57 (gap G20; Module 28 §45 "store owner affiliate dashboard") —
 * Marketing → Affiliates. Set the program's rules (each change is a new
 * version; earned commissions keep theirs), add or approve affiliates and
 * hand them their private page, review commissions the system held back,
 * and pay what is due, recording the transfer's reference.
 */
type Rules = {
  commission_type: 'percentage' | 'fixed';
  rate_bp: number;
  rate_percent: number;
  fixed_minor: number | null;
  window_days: number;
  hold_days: number;
  require_delivery: boolean;
  require_payment: boolean;
  new_customers_only: boolean;
  min_order_minor: number;
  cap_per_order_minor: number | null;
  payout_threshold_minor: number;
  self_referral: 'review' | 'reject';
};
type Program = { id: string; name: string; status: 'draft' | 'active' | 'paused'; accepts_applications: boolean; version: number | null; currency: string | null; terms: string; rules: Rules; summary: string[] };
type Overview = {
  included: boolean;
  can_manage: boolean;
  can_pay: boolean;
  currency: string;
  program: Program | null;
  defaults: Omit<Rules, 'rate_percent'>;
  limits: { max_window_days: number; max_rate_percent: number };
  stats: Record<'clicks_30d' | 'orders_30d' | 'sales_30d_minor' | 'commission_30d_minor' | 'pending_minor' | 'payable_minor' | 'paid_minor' | 'in_review' | 'applications' | 'active_affiliates', number>;
  coupons: { id: number; code: string }[];
};
type Balance = { pending_minor: number; payable_minor: number; paid_minor: number; in_payout_minor: number };
type Payout = { method: string | null; account_title: string | null; account: string | null; bank: string | null };
/** The full account, given only to someone who pays, after confirming it is them (audited). */
type PayTo = { method: string | null; account_title: string | null; account_number: string | null; bank: string | null };
type Affiliate = {
  id: string; name: string; email: string; phone: string | null; status: 'pending' | 'active' | 'paused' | 'rejected'; status_reason: string | null;
  code: string; coupon: { id: number; code: string | null } | null; source: 'added' | 'applied'; application_note: string | null; is_customer: boolean;
  created_at: string | null; approved_at: string | null; payout: Payout; clicks_30d: number; orders: number; sales_minor: number; balance: Balance;
};
type Commission = {
  id: string; status: string; source: 'link' | 'coupon'; affiliate: { id: string; name: string; code: string }; order: { id: string; number: string; status: string };
  base_minor: number; amount_minor: number; reversed_minor: number; net_minor: number; currency: string; rate_percent: number | null; program_version: number | null;
  flags: { self_referral?: string[] } | null; note: string | null; hold_until: string | null; created_at: string | null; in_payout: boolean;
};
type PayoutRow = { id: string; affiliate: { id: string; name: string }; status: 'pending' | 'paid' | 'cancelled'; amount_minor: number; currency: string; method: string | null; reference: string | null; note: string | null; created_at: string | null; paid_at: string | null };

const AFFILIATE_STATUS: Record<Affiliate['status'], [string, Tone]> = {
  pending: ['Applied', 'amber'],
  active: ['Active', 'green'],
  paused: ['Paused', 'neutral'],
  rejected: ['Declined', 'red'],
};
export const COMMISSION_STATUS: Record<string, [string, Tone, string]> = {
  review: ['Needs review', 'amber', 'Held back: it may be the affiliate’s own order.'],
  pending: ['Pending', 'blue', 'Waiting for delivery, payment and the hold period.'],
  approved: ['Approved', 'green', 'Payable with the next payout.'],
  paid: ['Paid', 'neutral', 'Included in a paid payout.'],
  rejected: ['Rejected', 'red', 'Will not be paid.'],
  reversed: ['Reversed', 'red', 'Taken back: the order was refunded.'],
};
const METHOD: Record<string, string> = { bank_transfer: 'Bank transfer', jazzcash: 'JazzCash', easypaisa: 'Easypaisa' };
const SIGNALS: Record<string, string> = { same_customer_account: 'customer account', same_email: 'email address', same_phone: 'phone number' };

export default function Affiliates() {
  const overview = useApi<{ data: Overview }>('/affiliates/overview');
  const [tab, setTab] = useState('overview');
  const o = overview.data?.data;

  return (
    <AdminPage title="Affiliates" description="People who promote your store with their own link or coupon code, and earn a commission on the orders they bring.">
      {overview.error ? (
        <ErrorPanel message={overview.error} onRetry={overview.reload} />
      ) : o === undefined ? (
        <Skeleton lines={8} />
      ) : (
        <>
          <ProgramBar o={o} onChanged={overview.reload} onSettings={() => setTab('settings')} />
          <Tabs
            label="Affiliate sections"
            active={tab}
            onChange={setTab}
            tabs={[
              { id: 'overview', label: 'Overview' },
              { id: 'affiliates', label: `Affiliates${o.stats.applications ? ` (${o.stats.applications} new)` : ''}` },
              { id: 'commissions', label: `Commissions${o.stats.in_review ? ` (${o.stats.in_review} to review)` : ''}` },
              { id: 'payouts', label: 'Payouts' },
              { id: 'settings', label: 'Program settings' },
            ]}
          />
          {tab === 'overview' && <OverviewTab o={o} go={setTab} />}
          {tab === 'affiliates' && <AffiliatesTab o={o} onChanged={overview.reload} />}
          {tab === 'commissions' && <CommissionsTab o={o} onChanged={overview.reload} />}
          {tab === 'payouts' && <PayoutsTab o={o} onChanged={overview.reload} />}
          {tab === 'settings' && <SettingsTab o={o} onSaved={overview.reload} />}
        </>
      )}
    </AdminPage>
  );
}

function ProgramBar({ o, onChanged, onSettings }: { o: Overview; onChanged: () => void; onSettings: () => void }) {
  const { busy, run } = useAction();
  const [pausing, setPausing] = useState(false);
  const p = o.program;
  const state: [string, Tone] = p === null || p.version === null ? ['Not set up', 'neutral'] : p.status === 'active' ? ['Running', 'green'] : p.status === 'paused' ? ['Paused', 'amber'] : ['Draft', 'neutral'];

  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-2">
            <span className="font-semibold text-slate-900">{p?.name ?? 'Affiliate program'}</span>
            <Badge tone={state[1]}>{state[0]}</Badge>
            {p?.version != null && <span className="text-xs text-slate-500">Rules version {p.version}</span>}
          </p>
          {p?.version != null ? (
            <ul className="mt-2 list-disc space-y-0.5 pl-5 text-sm text-slate-600">
              {p.summary.map((line) => <li key={line}>{line}</li>)}
            </ul>
          ) : (
            <p className="mt-1 text-sm text-slate-600">Set the commission and the rules first. Nothing counts until you start the program.</p>
          )}
          {!o.included && <p className="mt-2 text-sm text-amber-800">Your package does not include an affiliate program. You can prepare it; starting it needs a package that includes it.</p>}
        </div>
        {o.can_manage && (
          <div className="flex flex-wrap gap-2">
            {p?.version == null ? (
              <Button variant="primary" onClick={onSettings}>Set up the program</Button>
            ) : p.status === 'active' ? (
              <Button onClick={() => setPausing(true)}>Pause program</Button>
            ) : (
              <Button variant="primary" busy={busy === 'start'} disabled={!o.included} onClick={() => run('start', () => adminFetch('/affiliates/program/activate', { method: 'POST' }), { success: 'The program is running. Referral links and codes now count.' }).then((r) => r && onChanged())}>
                {p.status === 'paused' ? 'Resume program' : 'Start program'}
              </Button>
            )}
          </div>
        )}
      </div>
      <ConfirmDialog
        open={pausing}
        title="Pause the affiliate program?"
        confirmLabel="Pause program"
        variant="primary"
        busy={busy === 'pause'}
        onClose={() => setPausing(false)}
        onConfirm={() => run('pause', () => adminFetch('/affiliates/program/pause', { method: 'POST' }), { success: 'Paused.' }).then((r) => { setPausing(false); if (r) onChanged(); })}
      >
        New clicks and orders stop counting. Commissions already earned continue as before and can still be paid. You can resume at any time.
      </ConfirmDialog>
    </Card>
  );
}

function OverviewTab({ o, go }: { o: Overview; go: (tab: string) => void }) {
  const c = o.currency;
  const s = o.stats;

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Visits from links (30 days)" value={s.clicks_30d.toLocaleString()} hint="Counted once per visitor a day" />
        <StatCard label="Orders brought (30 days)" value={s.orders_30d.toLocaleString()} hint={`${money(s.sales_30d_minor, c)} in sales`} />
        <StatCard label="Commission earned (30 days)" value={money(s.commission_30d_minor, c)} hint="After refunds" />
        <StatCard label="Active affiliates" value={s.active_affiliates.toLocaleString()} />
      </div>
      <Card title="What you owe" description="Approved commissions are payable. Pending ones wait for delivery, payment and the hold period, so returns are settled first.">
        <dl className="grid gap-4 sm:grid-cols-3">
          <Amount label="Pending" value={money(s.pending_minor, c)} />
          <Amount label="Payable now" value={money(s.payable_minor, c)} strong />
          <Amount label="Paid so far" value={money(s.paid_minor, c)} />
        </dl>
      </Card>
      {(s.applications > 0 || s.in_review > 0) && (
        <Card title="Needs your attention">
          <ul className="space-y-2 text-sm">
            {s.applications > 0 && (
              <li className="flex flex-wrap items-center justify-between gap-2">
                <span>{s.applications === 1 ? 'One person applied' : `${s.applications} people applied`} to become an affiliate.</span>
                <Button size="sm" onClick={() => go('affiliates')}>Review applications</Button>
              </li>
            )}
            {s.in_review > 0 && (
              <li className="flex flex-wrap items-center justify-between gap-2">
                <span>{s.in_review === 1 ? 'One commission was' : `${s.in_review} commissions were`} held back: the order may be the affiliate’s own.</span>
                <Button size="sm" onClick={() => go('commissions')}>Review commissions</Button>
              </li>
            )}
          </ul>
        </Card>
      )}
      <Card title="How it works">
        <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-600">
          <li>Each affiliate gets a link to your shop with their code, and you can give them a coupon code too. When both apply, the coupon wins.</li>
          <li>A visit through the link is remembered on that device for the attribution window. Only an order placed in it counts.</li>
          <li>The commission is worked out once, when the order is placed, with the rules of that moment. Shipping and tax never count.</li>
          <li>It is approved after delivery, payment and the hold period. Refunds take back their share, even after a payout.</li>
          <li>You pay affiliates yourself (bank, JazzCash or Easypaisa) and record the reference here. Affiliates see their earnings on their private page.</li>
        </ol>
      </Card>
    </div>
  );
}

function Amount({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <div>
      <dt className="text-sm text-slate-600">{label}</dt>
      <dd className={`mt-1 text-xl tabular-nums ${strong ? 'font-semibold text-slate-900' : 'text-slate-800'}`}>{value}</dd>
    </div>
  );
}

// --- Affiliates ---

function AffiliatesTab({ o, onChanged }: { o: Overview; onChanged: () => void }) {
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const list = useApi<{ data: Affiliate[] }>('/affiliates', { status: status || undefined, q: q || undefined });
  const [adding, setAdding] = useState(false);
  const [open, setOpen] = useState<string | null>(null);
  const [link, setLink] = useState<{ name: string; url: string } | null>(null);
  const rows = list.data?.data ?? [];
  const current = rows.find((a) => a.id === open) ?? null;
  const changed = () => { list.reload(); onChanged(); };

  return (
    <div className="space-y-4">
      <FilterBar>
        <SearchField label="Search affiliates" value={q} onChange={setQ} placeholder="Name, email or code" />
        <div className="w-48">
          <SelectField label="Status" value={status} onChange={setStatus} options={[{ value: '', label: 'All' }, { value: 'pending', label: 'Applied' }, { value: 'active', label: 'Active' }, { value: 'paused', label: 'Paused' }, { value: 'rejected', label: 'Declined' }]} />
        </div>
        {o.can_manage && <Button variant="primary" onClick={() => setAdding(true)}>Add affiliate</Button>}
      </FilterBar>
      {list.error ? (
        <ErrorPanel message={list.error} onRetry={list.reload} />
      ) : list.data === null ? (
        <Skeleton lines={6} />
      ) : rows.length === 0 ? (
        <EmptyPanel
          title={status || q ? 'No affiliates match' : 'No affiliates yet'}
          description={status || q ? 'Try another search or status.' : 'Add people you work with, or let them apply from your shop’s “Become an affiliate” page.'}
          action={o.can_manage && !status && !q ? <Button variant="primary" onClick={() => setAdding(true)}>Add affiliate</Button> : undefined}
        />
      ) : (
        <div className="relative overflow-x-auto rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <caption className="sr-only">Affiliates</caption>
            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
              <tr>
                <th scope="col" className="px-4 py-3">Affiliate</th>
                <th scope="col" className="px-4 py-3">Code</th>
                <th scope="col" className="px-4 py-3">Status</th>
                <th scope="col" className="px-4 py-3 text-right">Visits (30d)</th>
                <th scope="col" className="px-4 py-3 text-right">Orders</th>
                <th scope="col" className="px-4 py-3 text-right">Payable</th>
                <th scope="col" className="px-4 py-3"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((a) => (
                <tr key={a.id}>
                  <td className="px-4 py-3">
                    <span className="block font-medium text-slate-900">{a.name}</span>
                    <span className="block text-xs text-slate-500">{a.email}</span>
                  </td>
                  <td className="px-4 py-3 font-mono text-xs">
                    {a.code}
                    {a.coupon?.code && <span className="mt-0.5 block font-sans text-slate-500">Coupon {a.coupon.code}</span>}
                  </td>
                  <td className="px-4 py-3"><Badge tone={AFFILIATE_STATUS[a.status][1]}>{AFFILIATE_STATUS[a.status][0]}</Badge></td>
                  <td className="px-4 py-3 text-right tabular-nums">{a.clicks_30d.toLocaleString()}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{a.orders.toLocaleString()}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{money(a.balance.payable_minor, o.currency)}</td>
                  <td className="px-4 py-3 text-right">
                    <Button size="sm" onClick={() => setOpen(a.id)} aria-label={`Open ${a.name}`}>{a.status === 'pending' ? 'Review' : 'Open'}</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {adding && <AffiliateForm o={o} onClose={() => setAdding(false)} onDone={(name, url) => { setAdding(false); changed(); if (url) setLink({ name, url }); }} />}
      {current && <AffiliateDrawer o={o} a={current} onClose={() => setOpen(null)} onChanged={changed} onLink={(url) => setLink({ name: current.name, url })} />}
      {link && <PortalLinkDialog name={link.name} url={link.url} onClose={() => setLink(null)} />}
    </div>
  );
}

function AffiliateForm({ o, a, onClose, onDone }: { o: Overview; a?: Affiliate; onClose: () => void; onDone: (name: string, url: string | null) => void }) {
  const [name, setName] = useState(a?.name ?? '');
  const [email, setEmail] = useState(a?.email ?? '');
  const [phone, setPhone] = useState(a?.phone ?? '');
  const [code, setCode] = useState(a?.code ?? '');
  const [coupon, setCoupon] = useState(a?.coupon ? String(a.coupon.id) : '');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function save() {
    setBusy(true);
    setErrors({});
    setError(null);
    const body = { name, phone: phone || null, code: code || (a ? a.code : null), coupon_id: coupon ? Number(coupon) : null, ...(a ? {} : { email }) };
    adminFetch<{ data?: { portal_url: string }; message: string }>(a ? `/affiliates/${a.id}` : '/affiliates', { method: a ? 'PUT' : 'POST', body })
      .then((r) => { toast.success(r.message); onDone(name, r.data?.portal_url ?? null); })
      .catch((e) => { setErrors(fieldErrors(e)); setError(e instanceof Error ? e.message : 'Could not save.'); })
      .finally(() => setBusy(false));
  }

  return (
    <Dialog
      open
      title={a ? `Edit ${a.name}` : 'Add an affiliate'}
      description={a ? undefined : 'They get an email with their private page, where they find their link and earnings. Nothing else of your store is shared with them.'}
      onClose={onClose}
      busy={busy}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="primary" busy={busy} onClick={save}>{a ? 'Save' : 'Add affiliate'}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <FormError message={Object.keys(errors).length ? null : error} />
        <TextField label="Name" value={name} onChange={setName} error={errors.name} maxLength={120} required />
        {!a && <TextField label="Email" type="email" value={email} onChange={setEmail} error={errors.email} maxLength={190} required />}
        <TextField label="Phone" optional value={phone} onChange={setPhone} error={errors.phone} maxLength={30} />
        <TextField
          label="Referral code"
          optional={!a}
          hint={a ? 'Changing it stops links with the old code from counting.' : 'Letters and numbers, 3 to 20. Left empty, one is made from the name.'}
          value={code}
          onChange={(v) => setCode(v.toUpperCase())}
          error={errors.code}
          maxLength={20}
        />
        <SelectField
          label="Coupon code"
          optional
          hint="Orders using this coupon count for them, even without the link. Create coupons under Marketing → Promotions."
          value={coupon}
          onChange={setCoupon}
          error={errors.coupon_id}
          options={[{ value: '', label: 'No coupon' }, ...o.coupons.map((c) => ({ value: String(c.id), label: c.code }))]}
        />
      </div>
    </Dialog>
  );
}

function AffiliateDrawer({ o, a, onClose, onChanged, onLink }: { o: Overview; a: Affiliate; onClose: () => void; onChanged: () => void; onLink: (url: string) => void }) {
  const { busy, run } = useAction();
  const [editing, setEditing] = useState(false);
  const [confirm, setConfirm] = useState<null | 'reject' | 'pause' | 'link'>(null);
  const [reason, setReason] = useState('');
  const [details, setDetails] = useState(false);
  const [paying, setPaying] = useState(false);
  const [adjusting, setAdjusting] = useState(false);
  const c = o.currency;
  const [label, tone] = AFFILIATE_STATUS[a.status];

  function act(step: string, path: string, body?: Record<string, unknown>) {
    return run(step, () => adminFetch<{ data?: { portal_url: string }; message: string }>(`/affiliates/${a.id}/${path}`, { method: 'POST', body })).then((r) => {
      setConfirm(null);
      setReason('');
      if (!r) return;
      toast.success(r.message);
      onChanged();
      if (r.data?.portal_url) onLink(r.data.portal_url);
    });
  }

  return (
    <Dialog open side wide title={a.name} description={`${a.email}${a.phone ? ` · ${a.phone}` : ''}`} onClose={onClose}>
      <div className="space-y-6">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={tone}>{label}</Badge>
          <span className="text-sm text-slate-600">{a.source === 'applied' ? 'Applied' : 'Added'} {(a.created_at ? formatDate(a.created_at) : '')}</span>
          {a.is_customer && <Badge tone="blue">Also a customer</Badge>}
        </div>
        {a.status_reason && <p className="text-sm text-slate-600">Reason: {a.status_reason}</p>}
        {a.application_note && (
          <Card title="Their application">
            <p className="whitespace-pre-line text-sm text-slate-700">{a.application_note}</p>
          </Card>
        )}

        {o.can_manage && (
          <div className="flex flex-wrap gap-2">
            {a.status === 'pending' && (
              <>
                <Button variant="primary" busy={busy === 'approve'} onClick={() => act('approve', 'approve')}>Approve</Button>
                <Button onClick={() => setConfirm('reject')}>Decline</Button>
              </>
            )}
            {a.status === 'active' && <Button onClick={() => setConfirm('pause')}>Pause</Button>}
            {a.status === 'paused' && <Button variant="primary" busy={busy === 'resume'} onClick={() => act('resume', 'resume')}>Resume</Button>}
            {a.status !== 'pending' && a.status !== 'rejected' && <Button onClick={() => setConfirm('link')}>New private link</Button>}
            <Button onClick={() => setEditing(true)}>Edit</Button>
          </div>
        )}

        <Card title="Their link">
          <dl className="grid gap-3 text-sm sm:grid-cols-2">
            <Pair label="Referral code" value={<span className="font-mono">{a.code}</span>} />
            <Pair label="Coupon code" value={a.coupon?.code ?? 'None'} />
          </dl>
          <p className="mt-3 text-xs text-slate-500">Their link is your shop’s address followed by <span className="font-mono">?ref={a.code}</span>. It works on any page of your shop.</p>
        </Card>

        <Card title="Performance">
          <dl className="grid gap-3 text-sm sm:grid-cols-3">
            <Pair label="Visits (30 days)" value={a.clicks_30d.toLocaleString()} />
            <Pair label="Orders" value={a.orders.toLocaleString()} />
            <Pair label="Sales" value={money(a.sales_minor, c)} />
          </dl>
        </Card>

        <Card title="Balance" actions={o.can_pay && a.status === 'active' ? <Button size="sm" variant="primary" onClick={() => setPaying(true)} disabled={a.balance.payable_minor <= 0}>Prepare payout</Button> : undefined}>
          <dl className="grid gap-3 text-sm sm:grid-cols-4">
            <Pair label="Pending" value={money(a.balance.pending_minor, c)} />
            <Pair label="Payable" value={<strong>{money(a.balance.payable_minor, c)}</strong>} />
            <Pair label="In a payout" value={money(a.balance.in_payout_minor, c)} />
            <Pair label="Paid" value={money(a.balance.paid_minor, c)} />
          </dl>
          {o.can_pay && (
            <div className="mt-3">
              <Button size="sm" variant="ghost" onClick={() => setAdjusting(true)}>Add an adjustment</Button>
            </div>
          )}
          <Adjustments id={a.id} currency={c} />
        </Card>

        <Card title="Payout details" actions={o.can_pay ? <Button size="sm" onClick={() => setDetails(true)}>{a.payout.method ? 'Change' : 'Add'}</Button> : undefined}>
          {a.payout.method ? (
            <dl className="grid gap-3 text-sm sm:grid-cols-2">
              <Pair label="Method" value={METHOD[a.payout.method] ?? a.payout.method} />
              <Pair label="Account title" value={a.payout.account_title ?? '—'} />
              <Pair label="Account" value={<span className="font-mono">{a.payout.account ?? '—'}</span>} />
              {a.payout.bank && <Pair label="Bank" value={a.payout.bank} />}
            </dl>
          ) : (
            <p className="text-sm text-slate-600">Not given yet. The affiliate can add them on their private page.</p>
          )}
          <p className="mt-2 text-xs text-slate-500">Stored encrypted. Only the last four digits are shown here; the full account is shown when you prepare or pay a payout.</p>
        </Card>
      </div>

      {editing && <AffiliateForm o={o} a={a} onClose={() => setEditing(false)} onDone={() => { setEditing(false); onChanged(); }} />}
      {details && <PayoutDetailsDialog a={a} onClose={() => setDetails(false)} onDone={() => { setDetails(false); onChanged(); }} />}
      {paying && <PreparePayoutDialog a={a} currency={c} onClose={() => setPaying(false)} onDone={() => { setPaying(false); onChanged(); }} />}
      {adjusting && <AdjustmentDialog a={a} currency={c} onClose={() => setAdjusting(false)} onDone={() => { setAdjusting(false); onChanged(); }} />}
      <ConfirmDialog open={confirm === 'reject'} title={`Decline ${a.name}?`} confirmLabel="Decline" busy={busy === 'reject'} onClose={() => setConfirm(null)} onConfirm={() => act('reject', 'reject', { reason: reason || null })}>
        <p>Their application is closed. We do not email them about it.</p>
        <TextField label="Reason (for your team)" optional value={reason} onChange={setReason} maxLength={300} className="mt-3" />
      </ConfirmDialog>
      <ConfirmDialog open={confirm === 'pause'} title={`Pause ${a.name}?`} confirmLabel="Pause" variant="primary" busy={busy === 'pause'} onClose={() => setConfirm(null)} onConfirm={() => act('pause', 'pause', { reason: reason || null })}>
        <p>Their link and coupon stop counting for new orders and no payout can be made to them. Commissions already earned stay.</p>
        <TextField label="Reason (for your team)" optional value={reason} onChange={setReason} maxLength={300} className="mt-3" />
      </ConfirmDialog>
      <ConfirmDialog open={confirm === 'link'} title="Make a new private link?" confirmLabel="Make new link" variant="primary" busy={busy === 'link'} onClose={() => setConfirm(null)} onConfirm={() => act('link', 'portal-link')}>
        The link they have now stops working at once. Use this if they lost it or someone else may have it.
      </ConfirmDialog>
    </Dialog>
  );
}

function Pair({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <dt className="text-slate-500">{label}</dt>
      <dd className="mt-0.5 text-slate-900">{value}</dd>
    </div>
  );
}

function Adjustments({ id, currency }: { id: string; currency: string }) {
  const list = useApi<{ data: { amount_minor: number; reason: string; automatic: boolean; settled: boolean; created_at: string }[] }>(`/affiliates/${id}/adjustments`);
  const rows = list.data?.data ?? [];
  if (rows.length === 0) return null;

  return (
    <div className="mt-4">
      <h4 className="text-sm font-semibold text-slate-800">Adjustments</h4>
      <ul className="mt-2 divide-y divide-slate-100 text-sm">
        {rows.map((r, i) => (
          <li key={i} className="flex flex-wrap items-baseline justify-between gap-2 py-2">
            <span className="min-w-0">
              {r.reason}
              <span className="block text-xs text-slate-500">{formatDate(r.created_at)} · {r.automatic ? 'Automatic' : 'By your team'} · {r.settled ? 'Settled' : 'With the next payout'}</span>
            </span>
            <span className={`tabular-nums ${r.amount_minor < 0 ? 'text-red-700' : 'text-slate-900'}`}>{money(r.amount_minor, currency)}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** The private page's link, shown once: it is not kept anywhere it could be read again. */
export function PortalLinkDialog({ name, url, onClose }: { name: string; url: string; onClose: () => void }) {
  const [copied, setCopied] = useState(false);

  return (
    <Dialog open title={`${name}’s private page`} description="We emailed this link to them. You can also send it yourself now: it is shown only this once." onClose={onClose} footer={<Button variant="primary" onClick={onClose}>Done</Button>}>
      <div className="space-y-3">
        <label className="block text-sm font-semibold text-brand-700" htmlFor="portal-link">Private link</label>
        <div className="flex gap-2">
          <input id="portal-link" readOnly value={url} onFocus={(e) => e.currentTarget.select()} className="min-w-0 flex-1 rounded-md border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs" />
          <Button
            onClick={() => {
              void navigator.clipboard?.writeText(url).then(() => { setCopied(true); toast.success('Link copied.'); });
            }}
          >
            {copied ? 'Copied' : 'Copy'}
          </Button>
        </div>
        <p className="text-xs text-slate-500">Anyone with this link sees this affiliate’s earnings, so share it only with them. If it is lost or shared, make a new one.</p>
      </div>
    </Dialog>
  );
}

function PayoutDetailsDialog({ a, onClose, onDone }: { a: Affiliate; onClose: () => void; onDone: () => void }) {
  const [method, setMethod] = useState(a.payout.method ?? 'jazzcash');
  const [title, setTitle] = useState(a.payout.account_title ?? '');
  const [number, setNumber] = useState('');
  const [bank, setBank] = useState(a.payout.bank ?? '');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function save() {
    setBusy(true);
    setErrors({});
    setError(null);
    adminFetch(`/affiliates/${a.id}/payout-details`, { method: 'PUT', body: { method, account_title: title, account_number: number, bank: method === 'bank_transfer' ? bank : null } })
      .then(() => { toast.success('Payout details saved.'); onDone(); })
      .catch((e) => { setErrors(fieldErrors(e)); setError(e instanceof Error ? e.message : 'Could not save.'); })
      .finally(() => setBusy(false));
  }

  return (
    <Dialog open title="Payout details" description={`Where you send ${a.name}’s money. Changing them asks for your password again.`} onClose={onClose} busy={busy} footer={<><Button onClick={onClose} disabled={busy}>Cancel</Button><Button variant="primary" busy={busy} onClick={save}>Save</Button></>}>
      <div className="space-y-4">
        <FormError message={Object.keys(errors).length ? null : error} />
        <SelectField label="Method" value={method} onChange={setMethod} options={Object.entries(METHOD).map(([value, label]) => ({ value, label }))} />
        <TextField label="Account title" value={title} onChange={setTitle} error={errors.account_title} maxLength={120} required />
        <TextField label={method === 'bank_transfer' ? 'IBAN or account number' : 'Mobile account number'} value={number} onChange={setNumber} error={errors.account_number} maxLength={40} required autoComplete="off" hint={a.payout.account ? `Now: ${a.payout.account}. Enter the full number again to change it.` : undefined} />
        {method === 'bank_transfer' && <TextField label="Bank" value={bank} onChange={setBank} error={errors.bank} maxLength={80} required />}
      </div>
    </Dialog>
  );
}

function PreparePayoutDialog({ a, currency, onClose, onDone }: { a: Affiliate; currency: string; onClose: () => void; onDone: () => void }) {
  const [note, setNote] = useState('');
  const [key] = useState(() => idempotencyKey());
  const [made, setMade] = useState<null | { id: string; amount_minor: number; pay_to: PayTo }>(null);
  const [reference, setReference] = useState('');
  const [paidKey] = useState(() => idempotencyKey());
  const { busy, run } = useAction();

  if (made) {
    return (
      <Dialog
        open
        title="Pay, then record it"
        description="The payout is ready. Send the money from your bank or wallet, then enter the transaction reference. You can also do this later under Payouts."
        onClose={onDone}
        footer={
          <>
            <Button onClick={onDone}>Later</Button>
            <Button variant="primary" busy={busy === 'paid'} disabled={reference.trim().length < 3} onClick={() => run('paid', () => adminFetch(`/affiliates/payouts/${made.id}/paid`, { method: 'POST', body: { reference }, headers: { 'Idempotency-Key': paidKey } }), { success: 'Recorded as paid.' }).then((r) => r && onDone())}>
              Record as paid
            </Button>
          </>
        }
      >
        <dl className="grid gap-3 text-sm sm:grid-cols-2">
          <Pair label="Amount" value={<strong className="text-lg">{money(made.amount_minor, currency)}</strong>} />
        </dl>
        <PayToDetails to={made.pay_to} />
        <TextField label="Transaction reference" value={reference} onChange={setReference} maxLength={120} className="mt-4" hint="The reference your bank or wallet gave for this transfer." />
      </Dialog>
    );
  }

  return (
    <Dialog
      open
      title={`Prepare a payout to ${a.name}`}
      description="Gathers every approved commission and adjustment not yet paid. Nothing is sent from here: you pay them yourself, then record the reference."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" busy={busy === 'make'} onClick={() => run('make', () => adminFetch<{ data: { id: string; amount_minor: number; pay_to: PayTo } }>(`/affiliates/${a.id}/payouts`, { method: 'POST', body: { note: note || null }, headers: { 'Idempotency-Key': key } })).then((r) => r && setMade(r.data))}>
            Prepare payout
          </Button>
        </>
      }
    >
      <p className="text-sm text-slate-700">Payable now: <strong>{money(a.balance.payable_minor, currency)}</strong></p>
      {!a.payout.method && <p className="mt-2 text-sm text-amber-800">Add their payout details first: where to send the money.</p>}
      <TextAreaField label="Note" optional value={note} onChange={setNote} rows={2} maxLength={500} className="mt-3" />
    </Dialog>
  );
}

function AdjustmentDialog({ a, currency, onClose, onDone }: { a: Affiliate; currency: string; onClose: () => void; onDone: () => void }) {
  const [direction, setDirection] = useState<'add' | 'deduct'>('add');
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [key] = useState(() => idempotencyKey());
  const { busy, run } = useAction();
  const minor = toMinor(amount, currency);

  return (
    <Dialog
      open
      title="Add an adjustment"
      description="A correction to what you owe this affiliate, settled with the next payout. It is recorded with your name and cannot be edited."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button
            variant="primary"
            busy={busy === 'adjust'}
            disabled={!minor || minor <= 0 || reason.trim().length < 5}
            onClick={() => run('adjust', () => adminFetch(`/affiliates/${a.id}/adjustments`, { method: 'POST', body: { amount_minor: direction === 'add' ? minor : -(minor ?? 0), reason }, headers: { 'Idempotency-Key': key } }), { success: 'Adjustment recorded.' }).then((r) => r && onDone())}
          >
            Record adjustment
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <SelectField label="Type" value={direction} onChange={(v) => setDirection(v as 'add' | 'deduct')} options={[{ value: 'add', label: 'Add to what they are owed (a bonus or correction)' }, { value: 'deduct', label: 'Deduct (for example, money paid outside a payout)' }]} />
        <TextField label={`Amount (${currency})`} inputMode="decimal" value={amount} onChange={setAmount} error={amount && !minor ? 'Enter an amount, for example 500 or 12.50' : null} />
        <TextAreaField label="Reason" value={reason} onChange={setReason} rows={2} maxLength={300} hint="At least 5 characters. The affiliate sees adjustments on their page." />
      </div>
    </Dialog>
  );
}

function PayToDetails({ to }: { to: PayTo }) {
  if (!to.method) return <p className="mt-3 text-sm text-amber-800">No payout details on file. Add them on the affiliate’s page first.</p>;

  return (
    <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3">
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Send it to</p>
      <dl className="mt-2 grid gap-3 text-sm sm:grid-cols-2">
        <Pair label="Method" value={METHOD[to.method] ?? to.method} />
        <Pair label="Account title" value={to.account_title ?? '—'} />
        <Pair label={to.method === 'bank_transfer' ? 'IBAN or account number' : 'Mobile account'} value={<span className="select-all font-mono">{to.account_number ?? '—'}</span>} />
        {to.bank && <Pair label="Bank" value={to.bank} />}
      </dl>
      <p className="mt-2 text-xs text-slate-500">Shown because you are paying. Your look at it is recorded in the audit log.</p>
    </div>
  );
}

// --- Commissions ---

function CommissionsTab({ o, onChanged }: { o: Overview; onChanged: () => void }) {
  const [status, setStatus] = useState(o.stats.in_review > 0 ? 'review' : '');
  const list = useApi<{ data: Commission[]; meta: { total: number } }>('/affiliates/commissions', { status: status || undefined });
  const [deciding, setDeciding] = useState<{ c: Commission; decision: 'release' | 'reject' } | null>(null);
  const [note, setNote] = useState('');
  const { busy, run } = useAction();
  const rows = list.data?.data ?? [];

  return (
    <div className="space-y-4">
      <FilterBar>
        <div className="w-56">
          <SelectField label="Status" value={status} onChange={setStatus} options={[{ value: '', label: 'All' }, ...Object.entries(COMMISSION_STATUS).map(([value, [label]]) => ({ value, label }))]} />
        </div>
      </FilterBar>
      {list.error ? (
        <ErrorPanel message={list.error} onRetry={list.reload} />
      ) : list.data === null ? (
        <Skeleton lines={6} />
      ) : rows.length === 0 ? (
        <EmptyPanel title={status === 'review' ? 'Nothing to review' : 'No commissions yet'} description={status ? 'Nothing has this status.' : 'Orders placed through an affiliate’s link or coupon appear here.'} />
      ) : (
        <ul className="space-y-3">
          {rows.map((c) => {
            const [label, tone, help] = COMMISSION_STATUS[c.status] ?? [c.status, 'neutral', ''];
            return (
              <li key={c.id} className="rounded-lg border border-slate-200 bg-white p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2">
                      <a href={`/orders/${c.order.id}`} className="font-medium text-brand-700 underline-offset-2 hover:underline">Order {c.order.number}</a>
                      <Badge tone={tone}>{label}</Badge>
                      <span className="text-xs text-slate-500">via {c.source === 'coupon' ? 'coupon' : 'link'} · {c.affiliate.name} ({c.affiliate.code})</span>
                    </p>
                    <p className="mt-1 text-sm text-slate-600">
                      {money(c.amount_minor, c.currency)} on {money(c.base_minor, c.currency)}
                      {c.rate_percent !== null ? ` (${c.rate_percent}%)` : ' (fixed)'}
                      {c.reversed_minor > 0 && <span className="text-red-700"> · {money(c.reversed_minor, c.currency)} taken back</span>}
                      {c.program_version !== null && <span className="text-slate-500"> · rules v{c.program_version}</span>}
                    </p>
                    <p className="mt-1 text-xs text-slate-500">
                      {c.created_at ? formatDate(c.created_at) : ''} · {help}
                      {c.status === 'pending' && c.hold_until ? ` Approved from ${formatDate(c.hold_until)} if nothing changes.` : ''}
                    </p>
                    {c.flags?.self_referral && (
                      <p className="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        The order matches the affiliate’s {c.flags.self_referral.map((s) => SIGNALS[s] ?? s).join(' and ')}.
                      </p>
                    )}
                    {c.note && <p className="mt-1 text-xs text-slate-600">Note: {c.note}</p>}
                  </div>
                  {o.can_manage && c.status === 'review' && (
                    <div className="flex gap-2">
                      <Button size="sm" onClick={() => setDeciding({ c, decision: 'release' })}>Release</Button>
                      <Button size="sm" variant="danger" onClick={() => setDeciding({ c, decision: 'reject' })}>Reject</Button>
                    </div>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
      <ConfirmDialog
        open={deciding !== null}
        title={deciding?.decision === 'release' ? 'Release this commission?' : 'Reject this commission?'}
        confirmLabel={deciding?.decision === 'release' ? 'Release' : 'Reject'}
        variant={deciding?.decision === 'release' ? 'primary' : 'danger'}
        busy={busy === 'decide'}
        onClose={() => { setDeciding(null); setNote(''); }}
        onConfirm={() => deciding && run('decide', () => adminFetch<{ message: string }>(`/affiliates/commissions/${deciding.c.id}/decide`, { method: 'POST', body: { decision: deciding.decision, note: note || null } })).then((r) => {
          setDeciding(null);
          setNote('');
          if (r) { toast.success(r.message); list.reload(); onChanged(); }
        })}
      >
        <p>{deciding?.decision === 'release' ? 'It continues like any other commission: approved after delivery, payment and the hold period.' : 'It will not be paid. The affiliate sees it as rejected.'}</p>
        <TextField label="Note" optional value={note} onChange={setNote} maxLength={500} className="mt-3" />
      </ConfirmDialog>
    </div>
  );
}

// --- Payouts ---

function PayoutsTab({ o, onChanged }: { o: Overview; onChanged: () => void }) {
  const list = useApi<{ data: PayoutRow[] }>('/affiliates/payouts');
  const [paying, setPaying] = useState<PayoutRow | null>(null);
  const [cancelling, setCancelling] = useState<PayoutRow | null>(null);
  const [reference, setReference] = useState('');
  const [key, setKey] = useState(() => idempotencyKey());
  const [payTo, setPayTo] = useState<PayTo | null>(null);
  const { busy, run } = useAction();
  const rows = list.data?.data ?? [];
  const done = () => { list.reload(); onChanged(); };

  function openPaying(p: PayoutRow) {
    setReference('');
    setKey(idempotencyKey());
    setPayTo(null);
    // Where to send it: the full account, after confirming it is you.
    void run('reveal', () => adminFetch<{ data: PayTo }>(`/affiliates/payouts/${p.id}/pay-to`, { method: 'POST' })).then((r) => {
      if (!r) return;
      setPayTo(r.data);
      setPaying(p);
    });
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">Prepare a payout from an affiliate’s page. You send the money yourself and record the transfer’s reference here; nothing is paid automatically.</p>
      {list.error ? (
        <ErrorPanel message={list.error} onRetry={list.reload} />
      ) : list.data === null ? (
        <Skeleton lines={5} />
      ) : rows.length === 0 ? (
        <EmptyPanel title="No payouts yet" description="When affiliates have approved commissions, prepare a payout from the Affiliates tab." />
      ) : (
        <div className="relative overflow-x-auto rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <caption className="sr-only">Payouts</caption>
            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
              <tr>
                <th scope="col" className="px-4 py-3">Affiliate</th>
                <th scope="col" className="px-4 py-3 text-right">Amount</th>
                <th scope="col" className="px-4 py-3">Status</th>
                <th scope="col" className="px-4 py-3">Reference</th>
                <th scope="col" className="px-4 py-3">Date</th>
                <th scope="col" className="px-4 py-3"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((p) => (
                <tr key={p.id}>
                  <td className="px-4 py-3 font-medium text-slate-900">{p.affiliate.name}<span className="block text-xs font-normal text-slate-500">{p.method ? METHOD[p.method] : ''}</span></td>
                  <td className="px-4 py-3 text-right tabular-nums">{money(p.amount_minor, p.currency)}</td>
                  <td className="px-4 py-3"><Badge tone={p.status === 'paid' ? 'green' : p.status === 'pending' ? 'amber' : 'neutral'}>{p.status === 'pending' ? 'To pay' : p.status === 'paid' ? 'Paid' : 'Cancelled'}</Badge></td>
                  <td className="px-4 py-3 font-mono text-xs">{p.reference ?? '—'}</td>
                  <td className="px-4 py-3 text-slate-600">{p.paid_at ?? p.created_at ? formatDate((p.paid_at ?? p.created_at) as string) : '—'}</td>
                  <td className="px-4 py-3 text-right">
                    {o.can_pay && p.status === 'pending' && (
                      <span className="inline-flex gap-2">
                        <Button size="sm" variant="primary" busy={busy === 'reveal'} onClick={() => openPaying(p)}>Pay</Button>
                        <Button size="sm" variant="ghost" onClick={() => setCancelling(p)}>Cancel</Button>
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Dialog
        open={paying !== null}
        title={`Record the payout to ${paying?.affiliate.name ?? ''} as paid`}
        description={paying ? `${money(paying.amount_minor, paying.currency)} — only once the money has been sent.` : undefined}
        onClose={() => setPaying(null)}
        busy={busy === 'paid'}
        footer={
          <>
            <Button onClick={() => setPaying(null)}>Cancel</Button>
            <Button variant="primary" busy={busy === 'paid'} disabled={reference.trim().length < 3} onClick={() => paying && run('paid', () => adminFetch(`/affiliates/payouts/${paying.id}/paid`, { method: 'POST', body: { reference }, headers: { 'Idempotency-Key': key } }), { success: 'Recorded as paid.' }).then((r) => { if (r) { setPaying(null); done(); } })}>
              Record as paid
            </Button>
          </>
        }
      >
        {payTo && <PayToDetails to={payTo} />}
        <TextField label="Transaction reference" value={reference} onChange={setReference} maxLength={120} className="mt-4" hint="The reference your bank or wallet gave for this transfer." />
      </Dialog>
      <ConfirmDialog open={cancelling !== null} title="Cancel this payout?" confirmLabel="Cancel payout" busy={busy === 'cancel'} onClose={() => setCancelling(null)} onConfirm={() => cancelling && run('cancel', () => adminFetch(`/affiliates/payouts/${cancelling.id}/cancel`, { method: 'POST' }), { success: 'Cancelled. Its commissions are payable again.' }).then((r) => { setCancelling(null); if (r) done(); })}>
        Use this only if the money was not sent. Its commissions and adjustments become payable again.
      </ConfirmDialog>
    </div>
  );
}

// --- Settings ---

type Form = {
  name: string; accepts_applications: boolean; terms: string; commission_type: 'percentage' | 'fixed'; rate_percent: string; fixed: string; window_days: string; hold_days: string;
  require_delivery: boolean; require_payment: boolean; new_customers_only: boolean; min_order: string; cap: string; threshold: string; self_referral: 'review' | 'reject';
};

function formFrom(o: Overview): Form {
  const p = o.program;
  const r = p?.version != null ? p.rules : { ...o.defaults, rate_percent: o.defaults.rate_bp / 100 };
  const c = o.currency;

  return {
    name: p?.name && p.name !== 'Affiliate program' ? p.name : 'Affiliate program', accepts_applications: p?.accepts_applications ?? true, terms: p?.terms ?? '',
    commission_type: r.commission_type, rate_percent: String(r.rate_bp / 100), fixed: r.fixed_minor ? fromMinor(r.fixed_minor, c) : '',
    window_days: String(r.window_days), hold_days: String(r.hold_days), require_delivery: r.require_delivery, require_payment: r.require_payment, new_customers_only: r.new_customers_only,
    min_order: r.min_order_minor ? fromMinor(r.min_order_minor, c) : '', cap: r.cap_per_order_minor ? fromMinor(r.cap_per_order_minor, c) : '',
    threshold: r.payout_threshold_minor ? fromMinor(r.payout_threshold_minor, c) : '', self_referral: r.self_referral,
  };
}

function SettingsTab({ o, onSaved }: { o: Overview; onSaved: () => void }) {
  const [f, setF] = useState<Form>(() => formFrom(o));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const c = o.currency;
  const set = <K extends keyof Form>(key: K) => (value: Form[K]) => setF((prev) => ({ ...prev, [key]: value }));
  const amount = (text: string) => (text.trim() === '' ? 0 : toMinor(text, c));
  const readOnly = !o.can_manage;

  function save() {
    setBusy(true);
    setErrors({});
    setError(null);
    const body = {
      name: f.name, accepts_applications: f.accepts_applications, terms: f.terms, commission_type: f.commission_type,
      rate_percent: f.commission_type === 'percentage' ? Number(f.rate_percent) : null, fixed_minor: f.commission_type === 'fixed' ? amount(f.fixed) : null,
      window_days: Number(f.window_days), hold_days: Number(f.hold_days), require_delivery: f.require_delivery, require_payment: f.require_payment, new_customers_only: f.new_customers_only,
      min_order_minor: amount(f.min_order), cap_per_order_minor: f.cap.trim() === '' ? null : amount(f.cap), payout_threshold_minor: amount(f.threshold), self_referral: f.self_referral,
    };
    adminFetch<{ message: string }>('/affiliates/program', { method: 'PUT', body })
      .then((r) => { toast.success(r.message); onSaved(); })
      .catch((e) => { setErrors(fieldErrors(e)); setError(e instanceof Error ? e.message : 'Could not save.'); })
      .finally(() => setBusy(false));
  }

  return (
    <div className="space-y-6">
      <FormError message={Object.keys(errors).length ? 'Check the highlighted fields.' : error} />
      <Card title="Program" description="Shown to people on your shop’s “Become an affiliate” page and on each affiliate’s private page.">
        <div className="space-y-4">
          <TextField label="Program name" value={f.name} onChange={set('name')} error={errors.name} maxLength={120} disabled={readOnly} />
          <SwitchField label="Accept applications from the shop" hint="People can apply on your shop. You approve each one." checked={f.accepts_applications} onChange={set('accepts_applications')} disabled={readOnly} />
          <TextAreaField label="Terms for affiliates" optional value={f.terms} onChange={set('terms')} error={errors.terms} rows={6} maxLength={10000} disabled={readOnly} hint="For example: where they may promote, that they may not bid on your brand name, how and when you pay. Applicants accept them." />
        </div>
      </Card>
      <Card title="Commission" description="Worked out on the products’ price after discounts. Shipping and tax never count.">
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField label="Type" value={f.commission_type} onChange={(v) => set('commission_type')(v as Form['commission_type'])} options={[{ value: 'percentage', label: 'A percentage of the order' }, { value: 'fixed', label: 'A fixed amount per order' }]} disabled={readOnly} />
          {f.commission_type === 'percentage' ? (
            <TextField label="Rate (%)" inputMode="decimal" value={f.rate_percent} onChange={set('rate_percent')} error={errors.rate_percent} hint={`Up to ${o.limits.max_rate_percent}%.`} disabled={readOnly} />
          ) : (
            <TextField label={`Amount per order (${c})`} inputMode="decimal" value={f.fixed} onChange={set('fixed')} error={errors.fixed_minor} hint="Never more than the order’s products." disabled={readOnly} />
          )}
          <TextField label={`Minimum order (${c})`} optional inputMode="decimal" value={f.min_order} onChange={set('min_order')} error={errors.min_order_minor} hint="Smaller orders earn nothing." disabled={readOnly} />
          <TextField label={`Most per order (${c})`} optional inputMode="decimal" value={f.cap} onChange={set('cap')} error={errors.cap_per_order_minor} hint="Empty: no limit." disabled={readOnly} />
          <div className="sm:col-span-2">
            <CheckboxField label="Only first orders of new customers" hint="Orders from people who bought before earn nothing." checked={f.new_customers_only} onChange={set('new_customers_only')} disabled={readOnly} />
          </div>
        </div>
      </Card>
      <Card title="When it counts and when it is paid">
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Attribution window (days)" inputMode="numeric" value={f.window_days} onChange={set('window_days')} error={errors.window_days} hint={`How long after a visit an order still counts. 1 to ${o.limits.max_window_days}.`} disabled={readOnly} />
          <TextField label="Hold period (days)" inputMode="numeric" value={f.hold_days} onChange={set('hold_days')} error={errors.hold_days} hint="Wait after delivery before approving, so returns are settled first. 0 to 90." disabled={readOnly} />
          <CheckboxField label="Only after delivery" checked={f.require_delivery} onChange={set('require_delivery')} disabled={readOnly} />
          <CheckboxField label="Only after the order is paid" hint="For cash on delivery: once the cash is collected." checked={f.require_payment} onChange={set('require_payment')} disabled={readOnly} />
          <TextField label={`Least payout (${c})`} optional inputMode="decimal" value={f.threshold} onChange={set('threshold')} error={errors.payout_threshold_minor} hint="Smaller balances wait for the next payout." disabled={readOnly} />
          <SelectField
            label="An affiliate’s own orders"
            value={f.self_referral}
            onChange={(v) => set('self_referral')(v as Form['self_referral'])}
            options={[{ value: 'review', label: 'Hold for my review' }, { value: 'reject', label: 'Never pay' }]}
            hint="Matched by customer account, email or phone."
            disabled={readOnly}
          />
        </div>
      </Card>
      {!readOnly && (
        <div className="flex flex-wrap items-center justify-end gap-3">
          <p className="text-sm text-slate-600">Saving new rules makes a new version. Commissions already earned keep the rules they were earned under.</p>
          <Button variant="primary" busy={busy} onClick={save}>Save program</Button>
        </div>
      )}
    </div>
  );
}
