import { useState, type ReactNode } from 'react';
import AdminPage from '@/Components/AdminPage';
import { Card, EmptyPanel, ErrorPanel, Skeleton, StatCard, Tabs } from '@/Components/ui/Page';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import Badge, { type Tone } from '@/Components/ui/Badge';
import Button from '@/Components/ui/Button';
import { CheckboxField, FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { adminFetch, fieldErrors, idempotencyKey } from '@/lib/adminApi';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { useAccess } from '@/lib/access';
import { formatDate } from '@/lib/datetime';
import { money, toMinor } from '@/lib/money';
import { toast } from '@/Components/ui/toast';

/**
 * Phase B58 (Module 28 §46 "Super Admin dashboard", §26, §68–72, §90) —
 * Super Admin → Resellers: the partners who sell Umar Techy. Tiers hold the
 * commercial terms (partner price and commissions); resellers are invited
 * or apply and are approved with a tier; each store they bring keeps the
 * terms of that day. Commissions come from paid Umar Techy invoices;
 * Umar Techy pays them outside the platform and records the reference.
 */
type Tier = {
  id: string; name: string; description: string | null; is_active: boolean; client_discount_percent: number; initial_commission_percent: number;
  recurring_commission_percent: number; recurring_months: number | null; hold_days: number; can_provision: boolean; max_stores: number | null;
  payout_threshold_minor: number; resellers: number;
};
type Balance = { pending_minor: number; payable_minor: number; in_payout_minor: number; paid_minor: number };
type Overview = { tiers: Tier[]; counts: Record<string, number>; stores: number; totals: Record<string, Balance>; currencies: string[] };
type Row = { id: string; business_name: string; contact_name: string; email: string; code: string; status: string; source: string; tier: string | null; stores: number; balances: Record<string, Balance>; created_at: string | null };
type Detail = {
  id: string; business_name: string; contact_name: string; email: string; phone: string | null; country: string | null; website: string | null; code: string;
  status: string; status_reason: string | null; source: string; application_note: string | null; tier: { id: string; name: string } | null;
  has_account: boolean; invitation_expires_at: string | null; joined_at: string | null; terminated_at: string | null;
  payout: { method: string | null; account_title: string | null; account: string | null; bank: string | null };
  balances: Record<string, Balance>;
  stores: { id: string; store: { id: number; name: string; status: string }; source: string; since: string; ended_at: string | null; ended_reason: string | null; terms: Record<string, number | string | null>; note: string | null }[];
  adjustments: { amount_minor: number; currency: string; reason: string; automatic: boolean; settled: boolean; created_at: string }[];
};
type Commission = {
  id: string; status: string; kind: string; reseller: { id: string; name: string }; store: string; invoice: string; base_minor: number; rate_percent: number;
  amount_minor: number; reversed_minor: number; net_minor: number; currency: string; hold_until: string | null; note: string | null; in_payout: boolean; created_at: string;
};
type PayoutRow = { id: string; reseller: { id: string; name: string }; status: 'pending' | 'paid' | 'cancelled'; amount_minor: number; currency: string; method: string | null; reference: string | null; created_at: string; paid_at: string | null };
type PayTo = { method: string | null; account_title: string | null; account_number: string | null; bank: string | null };

const STATUS: Record<string, [string, Tone]> = {
  pending: ['Applied', 'amber'], invited: ['Invited', 'blue'], active: ['Active', 'green'], paused: ['Paused', 'neutral'], terminated: ['Terminated', 'red'], rejected: ['Declined', 'red'],
};
const COMMISSION: Record<string, [string, Tone]> = {
  pending: ['On hold', 'blue'], approved: ['Approved', 'green'], paid: ['Paid', 'neutral'], rejected: ['Rejected', 'red'], reversed: ['Reversed', 'red'],
};
const SOURCE: Record<string, string> = { provisioned: 'Created by the reseller', link: 'Sign-up link', manual: 'Assigned by Umar Techy' };
const METHOD: Record<string, string> = { bank_transfer: 'Bank transfer', jazzcash: 'JazzCash', easypaisa: 'Easypaisa' };

export default function Resellers() {
  const overview = useApi<{ data: Overview }>('/super-admin/resellers/overview');
  const [tab, setTab] = useState('resellers');
  const o = overview.data?.data;
  const canManage = useAccess().platformCan('resellers');
  const totals = o ? Object.entries(o.totals) : [];

  return (
    <AdminPage title="Resellers" description="Partners who sell Umar Techy to their clients: their terms, the stores they bring, commissions and payouts.">
      {overview.error ? (
        <ErrorPanel message={overview.error} onRetry={overview.reload} />
      ) : o === undefined ? (
        <Skeleton lines={8} />
      ) : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Active resellers" value={(o.counts.active ?? 0).toLocaleString()} hint={`${o.counts.invited ?? 0} invited · ${o.counts.paused ?? 0} paused`} />
            <StatCard label="Applications" value={(o.counts.pending ?? 0).toLocaleString()} hint="Waiting for a decision" />
            <StatCard label="Stores brought" value={o.stores.toLocaleString()} />
            <StatCard label="Owed to resellers" value={totals.length ? totals.map(([c, b]) => money(b.payable_minor + b.in_payout_minor, c)).join(' · ') : '—'} hint={totals.length ? `On hold: ${totals.map(([c, b]) => money(b.pending_minor, c)).join(' · ')}` : 'Nothing yet'} />
          </div>
          <Tabs
            label="Reseller sections"
            active={tab}
            onChange={setTab}
            tabs={[
              { id: 'resellers', label: `Resellers${o.counts.pending ? ` (${o.counts.pending} new)` : ''}` },
              { id: 'tiers', label: `Tiers (${o.tiers.length})` },
              { id: 'commissions', label: 'Commissions' },
              { id: 'payouts', label: 'Payouts' },
            ]}
          />
          {tab === 'resellers' && <ResellersTab o={o} canManage={canManage} onChanged={overview.reload} goTiers={() => setTab('tiers')} />}
          {tab === 'tiers' && <TiersTab o={o} canManage={canManage} onChanged={overview.reload} />}
          {tab === 'commissions' && <CommissionsTab canManage={canManage} onChanged={overview.reload} />}
          {tab === 'payouts' && <PayoutsTab canManage={canManage} onChanged={overview.reload} />}
        </div>
      )}
    </AdminPage>
  );
}

// --- Resellers ---

function ResellersTab({ o, canManage, onChanged, goTiers }: { o: Overview; canManage: boolean; onChanged: () => void; goTiers: () => void }) {
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const list = useApi<{ data: Row[] }>('/super-admin/resellers', { status: status || undefined, q: q || undefined });
  const [inviting, setInviting] = useState(false);
  const [open, setOpen] = useState<string | null>(null);
  const [link, setLink] = useState<{ name: string; url: string } | null>(null);
  const rows = list.data?.data ?? [];
  const activeTiers = o.tiers.filter((t) => t.is_active);
  const changed = () => { list.reload(); onChanged(); };

  return (
    <div className="space-y-4">
      <FilterBar>
        <SearchField label="Search resellers" value={q} onChange={setQ} placeholder="Business, email or code" />
        <div className="w-48">
          <SelectField label="Status" value={status} onChange={setStatus} options={[{ value: '', label: 'All' }, ...Object.entries(STATUS).map(([value, [label]]) => ({ value, label }))]} />
        </div>
        {canManage && <Button variant="primary" onClick={() => (activeTiers.length ? setInviting(true) : goTiers())}>{activeTiers.length ? 'Invite reseller' : 'Create a tier first'}</Button>}
      </FilterBar>
      {list.error ? (
        <ErrorPanel message={list.error} onRetry={list.reload} />
      ) : list.data === null ? (
        <Skeleton lines={6} />
      ) : rows.length === 0 ? (
        <EmptyPanel title={status || q ? 'No resellers match' : 'No resellers yet'} description={status || q ? 'Try another search or status.' : 'Invite agencies you work with, or let them apply on the public partner page (/partners).'} />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <caption className="sr-only">Resellers</caption>
            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
              <tr>
                <th scope="col" className="px-4 py-3">Reseller</th>
                <th scope="col" className="px-4 py-3">Tier</th>
                <th scope="col" className="px-4 py-3">Status</th>
                <th scope="col" className="px-4 py-3 text-right">Stores</th>
                <th scope="col" className="px-4 py-3 text-right">To pay</th>
                <th scope="col" className="px-4 py-3"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((r) => (
                <tr key={r.id}>
                  <td className="px-4 py-3">
                    <span className="block font-medium text-slate-900">{r.business_name}</span>
                    <span className="block text-xs text-slate-500">{r.contact_name} · {r.email} · <span className="font-mono">{r.code}</span></span>
                  </td>
                  <td className="px-4 py-3">{r.tier ?? '—'}</td>
                  <td className="px-4 py-3"><Badge tone={STATUS[r.status]?.[1] ?? 'neutral'}>{STATUS[r.status]?.[0] ?? r.status}</Badge></td>
                  <td className="px-4 py-3 text-right tabular-nums">{r.stores}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{Object.entries(r.balances).map(([c, b]) => money(b.payable_minor, c)).join(' · ') || '—'}</td>
                  <td className="px-4 py-3 text-right"><Button size="sm" onClick={() => setOpen(r.id)} aria-label={`Open ${r.business_name}`}>{r.status === 'pending' ? 'Review' : 'Open'}</Button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {inviting && <InviteDialog tiers={activeTiers} onClose={() => setInviting(false)} onDone={(name, url) => { setInviting(false); changed(); setLink({ name, url }); }} />}
      {open && <ResellerDrawer id={open} tiers={activeTiers} canManage={canManage} currencies={o.currencies} onClose={() => setOpen(null)} onChanged={changed} onLink={(name, url) => setLink({ name, url })} />}
      {link && <InvitationLinkDialog name={link.name} url={link.url} onClose={() => setLink(null)} />}
    </div>
  );
}

function InvitationLinkDialog({ name, url, onClose }: { name: string; url: string; onClose: () => void }) {
  return (
    <Dialog open title={`Invitation for ${name}`} description="We emailed this link. You can also send it yourself now: it is shown only this once and works for 14 days." onClose={onClose} footer={<Button variant="primary" onClick={onClose}>Done</Button>}>
      <label className="block text-sm font-medium text-slate-700" htmlFor="invitation-link">Invitation link</label>
      <div className="mt-1 flex gap-2">
        <input id="invitation-link" readOnly value={url} onFocus={(e) => e.currentTarget.select()} className="min-w-0 flex-1 rounded-md border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs" />
        <Button onClick={() => void navigator.clipboard?.writeText(url).then(() => toast.success('Link copied.'))}>Copy</Button>
      </div>
      <p className="mt-2 text-xs text-slate-500">It can only be accepted with the account of the invited email address.</p>
    </Dialog>
  );
}

function InviteDialog({ tiers, onClose, onDone }: { tiers: Tier[]; onClose: () => void; onDone: (name: string, url: string) => void }) {
  const [form, setForm] = useState({ business_name: '', contact_name: '', email: '', phone: '', website: '', code: '', tier: tiers[0]?.id ?? '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [k]: value }));

  function save() {
    setBusy(true);
    setErrors({});
    setError(null);
    adminFetch<{ data: { invitation_url: string } }>('/super-admin/resellers', { method: 'POST', body: { ...form, phone: form.phone || null, website: form.website || null, code: form.code || null } })
      .then((r) => onDone(form.business_name, r.data.invitation_url))
      .catch((e) => { setErrors(fieldErrors(e)); setError(e instanceof Error ? e.message : 'Could not invite.'); })
      .finally(() => setBusy(false));
  }

  return (
    <Dialog open title="Invite a reseller" description="They get an email to join the partner portal with the account of this address." onClose={onClose} busy={busy}
      footer={<><Button onClick={onClose} disabled={busy}>Cancel</Button><Button variant="primary" busy={busy} onClick={save}>Send invitation</Button></>}>
      <div className="space-y-4">
        <FormError message={Object.keys(errors).length ? null : error} />
        <TextField label="Business name" value={form.business_name} onChange={set('business_name')} error={errors.business_name} maxLength={160} required />
        <TextField label="Contact person" value={form.contact_name} onChange={set('contact_name')} error={errors.contact_name} maxLength={120} required />
        <TextField label="Email" type="email" value={form.email} onChange={set('email')} error={errors.email} maxLength={190} required />
        <TextField label="Phone" optional value={form.phone} onChange={set('phone')} error={errors.phone} maxLength={30} />
        <TextField label="Website" optional value={form.website} onChange={set('website')} error={errors.website} maxLength={255} placeholder="https://" />
        <SelectField label="Tier" value={form.tier} onChange={set('tier')} error={errors.tier} options={tiers.map((t) => ({ value: t.id, label: `${t.name} — ${t.client_discount_percent}% off for clients, ${t.initial_commission_percent}% / ${t.recurring_commission_percent}%` }))} />
        <TextField label="Partner code" optional value={form.code} onChange={(v) => set('code')(v.toUpperCase())} error={errors.code} maxLength={20} hint="In their sign-up link. Left empty, one is made from the name." />
      </div>
    </Dialog>
  );
}

function ResellerDrawer({ id, tiers, canManage, currencies, onClose, onChanged, onLink }: {
  id: string; tiers: Tier[]; canManage: boolean; currencies: string[]; onClose: () => void; onChanged: () => void; onLink: (name: string, url: string) => void;
}) {
  const state = useApi<{ data: Detail }>(`/super-admin/resellers/${id}`);
  const d = state.data?.data;
  const { busy, run } = useAction();
  const [confirm, setConfirm] = useState<null | 'reject' | 'pause' | 'terminate'>(null);
  const [reason, setReason] = useState('');
  const [approveTier, setApproveTier] = useState(tiers[0]?.id ?? '');
  const [assigning, setAssigning] = useState(false);
  const [ending, setEnding] = useState<string | null>(null);
  const [details, setDetails] = useState(false);
  const [paying, setPaying] = useState<string | null>(null);
  const [adjusting, setAdjusting] = useState(false);
  const [editing, setEditing] = useState(false);
  const refresh = () => { state.reload(); onChanged(); };

  function act(step: string, body?: Record<string, unknown>) {
    return run(step, () => adminFetch<{ data?: { invitation_url?: string }; message: string }>(`/super-admin/resellers/${id}/${step}`, { method: 'POST', body })).then((r) => {
      setConfirm(null);
      setReason('');
      if (!r) return;
      toast.success(r.message);
      refresh();
      if (r.data?.invitation_url && d) onLink(d.business_name, r.data.invitation_url);
    });
  }

  return (
    <Dialog open side wide title={d?.business_name ?? 'Reseller'} description={d ? `${d.contact_name} · ${d.email}` : undefined} onClose={onClose}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : !d ? (
        <Skeleton lines={8} />
      ) : (
        <div className="space-y-6">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={STATUS[d.status]?.[1] ?? 'neutral'}>{STATUS[d.status]?.[0] ?? d.status}</Badge>
            <span className="text-sm text-slate-600">{d.tier ? `Tier ${d.tier.name}` : 'No tier yet'} · code <span className="font-mono">{d.code}</span></span>
            {d.joined_at && <span className="text-sm text-slate-600">· joined {formatDate(d.joined_at)}</span>}
          </div>
          {d.status_reason && <p className="text-sm text-slate-600">Reason: {d.status_reason}</p>}
          {d.application_note && <Card title="Their application"><p className="whitespace-pre-line text-sm text-slate-700">{d.application_note}</p></Card>}

          {canManage && (
            <div className="flex flex-wrap items-end gap-2">
              {d.status === 'pending' && (
                <>
                  <div className="w-64"><SelectField label="Tier" value={approveTier} onChange={setApproveTier} options={tiers.map((t) => ({ value: t.id, label: t.name }))} /></div>
                  <Button variant="primary" busy={busy === 'approve'} disabled={!approveTier} onClick={() => act('approve', { tier: approveTier })}>Approve</Button>
                  <Button onClick={() => setConfirm('reject')}>Decline</Button>
                </>
              )}
              {d.status === 'invited' && <Button busy={busy === 'invitation'} onClick={() => act('invitation')}>Send a new invitation</Button>}
              {d.status === 'active' && <Button onClick={() => setConfirm('pause')}>Pause</Button>}
              {d.status === 'paused' && <Button variant="primary" busy={busy === 'resume'} onClick={() => act('resume')}>Resume</Button>}
              {!['pending', 'rejected', 'terminated'].includes(d.status) && <Button onClick={() => setEditing(true)}>Edit</Button>}
              {['active', 'paused', 'invited'].includes(d.status) && <Button variant="danger" onClick={() => setConfirm('terminate')}>Terminate</Button>}
            </div>
          )}

          <Card title="Balance" actions={canManage && d.status !== 'pending' ? <Button size="sm" variant="ghost" onClick={() => setAdjusting(true)}>Add an adjustment</Button> : undefined}>
            {Object.keys(d.balances).length === 0 ? (
              <p className="text-sm text-slate-600">Nothing earned yet.</p>
            ) : (
              <ul className="space-y-3">
                {Object.entries(d.balances).map(([currency, b]) => (
                  <li key={currency} className="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span className="grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-4">
                      <Pair label="On hold" value={money(b.pending_minor, currency)} />
                      <Pair label="Payable" value={<strong>{money(b.payable_minor, currency)}</strong>} />
                      <Pair label="In a payout" value={money(b.in_payout_minor, currency)} />
                      <Pair label="Paid" value={money(b.paid_minor, currency)} />
                    </span>
                    {canManage && b.payable_minor > 0 && <Button size="sm" variant="primary" onClick={() => setPaying(currency)}>Prepare payout ({currency})</Button>}
                  </li>
                ))}
              </ul>
            )}
            {d.adjustments.length > 0 && (
              <ul className="mt-4 divide-y divide-slate-100 border-t border-slate-100 text-sm">
                {d.adjustments.map((a, i) => (
                  <li key={i} className="flex justify-between gap-3 py-2">
                    <span>{a.reason}<span className="block text-xs text-slate-500">{formatDate(a.created_at)} · {a.automatic ? 'Automatic' : 'By Umar Techy'} · {a.settled ? 'Settled' : 'With the next payout'}</span></span>
                    <span className={`tabular-nums ${a.amount_minor < 0 ? 'text-red-700' : ''}`}>{money(a.amount_minor, a.currency)}</span>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card title={`Stores (${d.stores.filter((s) => !s.ended_at).length})`} actions={canManage && d.status === 'active' ? <Button size="sm" onClick={() => setAssigning(true)}>Assign a store</Button> : undefined}>
            {d.stores.length === 0 ? (
              <p className="text-sm text-slate-600">No stores yet.</p>
            ) : (
              <ul className="divide-y divide-slate-100 text-sm">
                {d.stores.map((s) => (
                  <li key={s.id} className={`flex flex-wrap items-start justify-between gap-3 py-3 ${s.ended_at ? 'opacity-60' : ''}`}>
                    <span>
                      <a href={`/super-admin/stores/${s.store.id}`} className="font-medium text-indigo-700 hover:underline">{s.store.name}</a>
                      <span className="block text-xs text-slate-500">
                        {SOURCE[s.source] ?? s.source} · since {formatDate(s.since)} · terms {String(s.terms.tier)}: {Number(s.terms.client_discount_bp) / 100}% off, {Number(s.terms.initial_commission_bp) / 100}% / {Number(s.terms.recurring_commission_bp) / 100}%
                      </span>
                      {s.ended_at && <span className="block text-xs text-slate-500">Ended {formatDate(s.ended_at)}: {s.ended_reason}</span>}
                    </span>
                    {canManage && !s.ended_at && <Button size="sm" variant="ghost" onClick={() => setEnding(s.id)}>End</Button>}
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card title="Payout details" actions={canManage ? <Button size="sm" onClick={() => setDetails(true)}>{d.payout.method ? 'Change' : 'Add'}</Button> : undefined}>
            {d.payout.method ? (
              <dl className="grid gap-3 text-sm sm:grid-cols-2">
                <Pair label="Method" value={METHOD[d.payout.method] ?? d.payout.method} />
                <Pair label="Account title" value={d.payout.account_title ?? '—'} />
                <Pair label="Account" value={<span className="font-mono">{d.payout.account ?? '—'}</span>} />
                {d.payout.bank && <Pair label="Bank" value={d.payout.bank} />}
              </dl>
            ) : (
              <p className="text-sm text-slate-600">Not given yet. The reseller can add them in the partner portal.</p>
            )}
          </Card>

          <ConfirmDialog open={confirm === 'reject'} title={`Decline ${d.business_name}?`} confirmLabel="Decline" busy={busy === 'reject'} onClose={() => setConfirm(null)} onConfirm={() => act('reject', { reason: reason || null })}>
            <p>The application is closed. We do not email them about it.</p>
            <TextField label="Reason (for the team)" optional value={reason} onChange={setReason} maxLength={300} className="mt-3" />
          </ConfirmDialog>
          <ConfirmDialog open={confirm === 'pause'} title={`Pause ${d.business_name}?`} confirmLabel="Pause" variant="primary" busy={busy === 'pause'} onClose={() => setConfirm(null)} onConfirm={() => act('pause', { reason: reason || null })}>
            <p>They cannot create stores and new sign-ups through their link do not count. Commissions from their stores continue.</p>
            <TextField label="Reason (shown to them)" optional value={reason} onChange={setReason} maxLength={300} className="mt-3" />
          </ConfirmDialog>
          <ConfirmDialog open={confirm === 'terminate'} title={`Terminate ${d.business_name}?`} confirmLabel="Terminate" confirmText={d.code} busy={busy === 'terminate'} onClose={() => setConfirm(null)} onConfirm={() => act('terminate', { reason })}>
            <p>Their portal closes at once. Invoices from now on earn them nothing; commissions already earned can still be paid. Their clients keep their stores and the partner price. This cannot be undone.</p>
            <TextField label="Reason" value={reason} onChange={setReason} maxLength={300} className="mt-3" hint="At least 5 characters. Kept in the audit log." />
          </ConfirmDialog>
          {editing && <EditDialog d={d} tiers={tiers} onClose={() => setEditing(false)} onDone={() => { setEditing(false); refresh(); }} />}
          {assigning && <AssignDialog id={id} name={d.business_name} onClose={() => setAssigning(false)} onDone={() => { setAssigning(false); refresh(); }} />}
          {ending && <EndDialog attribution={ending} onClose={() => setEnding(null)} onDone={() => { setEnding(null); refresh(); }} />}
          {details && <PayoutDetailsDialog id={id} current={d.payout} onClose={() => setDetails(false)} onDone={() => { setDetails(false); refresh(); }} />}
          {paying && <PreparePayoutDialog id={id} currency={paying} amount={d.balances[paying]?.payable_minor ?? 0} onClose={() => setPaying(null)} onDone={() => { setPaying(null); refresh(); }} />}
          {adjusting && <AdjustmentDialog id={id} currencies={currencies} onClose={() => setAdjusting(false)} onDone={() => { setAdjusting(false); refresh(); }} />}
        </div>
      )}
    </Dialog>
  );
}

function Pair({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="text-slate-900">{value}</dd>
    </div>
  );
}

/** A form dialog: sends one request, shows field errors, closes on success. */
function FormDialog({ title, description, submitLabel, onClose, send, children }: {
  title: string; description?: string; submitLabel: string; onClose: () => void; send: () => Promise<{ message?: string }>; children: (errors: Record<string, string>) => ReactNode;
}) {
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function submit() {
    setBusy(true);
    setErrors({});
    setError(null);
    send()
      .then((r) => { if (r.message) toast.success(r.message); })
      .catch((e) => { setErrors(fieldErrors(e)); setError(e instanceof Error ? e.message : 'Could not save.'); })
      .finally(() => setBusy(false));
  }

  return (
    <Dialog open title={title} description={description} onClose={onClose} busy={busy}
      footer={<><Button onClick={onClose} disabled={busy}>Cancel</Button><Button variant="primary" busy={busy} onClick={submit}>{submitLabel}</Button></>}>
      <div className="space-y-4">
        <FormError message={Object.keys(errors).length ? null : error} />
        {children(errors)}
      </div>
    </Dialog>
  );
}

function EditDialog({ d, tiers, onClose, onDone }: { d: Detail; tiers: Tier[]; onClose: () => void; onDone: () => void }) {
  const [form, setForm] = useState({ business_name: d.business_name, contact_name: d.contact_name, phone: d.phone ?? '', website: d.website ?? '', tier: d.tier?.id ?? '' });
  const set = (k: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [k]: value }));
  const tierOptions = [...tiers.map((t) => ({ value: t.id, label: t.name })), ...(d.tier && !tiers.some((t) => t.id === d.tier?.id) ? [{ value: d.tier.id, label: `${d.tier.name} (inactive)` }] : [])];

  return (
    <FormDialog title={`Edit ${d.business_name}`} submitLabel="Save" onClose={onClose}
      send={() => adminFetch<{ message: string }>(`/super-admin/resellers/${d.id}`, { method: 'PUT', body: { ...form, phone: form.phone || null, website: form.website || null, tier: form.tier || null } }).then((r) => { onDone(); return r; })}>
      {(errors) => (
        <>
          <TextField label="Business name" value={form.business_name} onChange={set('business_name')} error={errors.business_name} maxLength={160} required />
          <TextField label="Contact person" value={form.contact_name} onChange={set('contact_name')} error={errors.contact_name} maxLength={120} required />
          <TextField label="Phone" optional value={form.phone} onChange={set('phone')} error={errors.phone} maxLength={30} />
          <TextField label="Website" optional value={form.website} onChange={set('website')} error={errors.website} maxLength={255} />
          <SelectField label="Tier" value={form.tier} onChange={set('tier')} error={errors.tier} options={tierOptions} hint="A new tier applies to stores they bring from now on. Their existing stores keep their terms." />
        </>
      )}
    </FormDialog>
  );
}

function AssignDialog({ id, name, onClose, onDone }: { id: string; name: string; onClose: () => void; onDone: () => void }) {
  const [store, setStore] = useState('');
  const [reason, setReason] = useState('');

  return (
    <FormDialog title={`Assign a store to ${name}`} description="The store counts for them from its next invoice, with their tier’s terms. If another reseller has it, theirs ends. Asks for your password again." submitLabel="Assign store" onClose={onClose}
      send={() => adminFetch<{ message: string }>(`/super-admin/resellers/${id}/stores`, { method: 'POST', body: { store, reason } }).then((r) => { onDone(); return r; })}>
      {(errors) => (
        <>
          <TextField label="Store number or address" value={store} onChange={setStore} error={errors.store} maxLength={120} hint="The number on the store’s Super Admin page, or its address (slug)." required />
          <TextAreaField label="Reason" value={reason} onChange={setReason} error={errors.reason} rows={2} maxLength={300} hint="At least 5 characters. Kept in the audit log." />
        </>
      )}
    </FormDialog>
  );
}

function EndDialog({ attribution, onClose, onDone }: { attribution: string; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('');

  return (
    <FormDialog title="End this store’s attribution?" description="Its invoices from now on earn the reseller nothing, and the store pays the public price from its next invoice. Commissions already earned stay." submitLabel="End attribution" onClose={onClose}
      send={() => adminFetch<{ message: string }>(`/super-admin/resellers/attributions/${attribution}/end`, { method: 'POST', body: { reason } }).then((r) => { onDone(); return r; })}>
      {(errors) => <TextAreaField label="Reason" value={reason} onChange={setReason} error={errors.reason} rows={2} maxLength={300} />}
    </FormDialog>
  );
}

function PayoutDetailsDialog({ id, current, onClose, onDone }: { id: string; current: Detail['payout']; onClose: () => void; onDone: () => void }) {
  const [form, setForm] = useState({ method: current.method ?? 'bank_transfer', account_title: current.account_title ?? '', account_number: '', bank: current.bank ?? '' });
  const set = (k: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [k]: value }));

  return (
    <FormDialog title="Payout details" description="Where Umar Techy pays this reseller. Asks for your password again." submitLabel="Save" onClose={onClose}
      send={() => adminFetch<{ message: string }>(`/super-admin/resellers/${id}/payout-details`, { method: 'PUT', body: { ...form, bank: form.method === 'bank_transfer' ? form.bank : null } }).then((r) => { onDone(); return r; })}>
      {(errors) => (
        <>
          <SelectField label="Method" value={form.method} onChange={set('method')} options={Object.entries(METHOD).map(([value, label]) => ({ value, label }))} />
          <TextField label="Account title" value={form.account_title} onChange={set('account_title')} error={errors.account_title} maxLength={120} required />
          <TextField label={form.method === 'bank_transfer' ? 'IBAN or account number' : 'Mobile account number'} value={form.account_number} onChange={set('account_number')} error={errors.account_number} maxLength={40} required autoComplete="off" />
          {form.method === 'bank_transfer' && <TextField label="Bank" value={form.bank} onChange={set('bank')} error={errors.bank} maxLength={80} required />}
        </>
      )}
    </FormDialog>
  );
}

function PayToDetails({ to }: { to: PayTo }) {
  if (!to.method) return <p className="mt-3 text-sm text-amber-800">No payout details on file.</p>;

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

function PreparePayoutDialog({ id, currency, amount, onClose, onDone }: { id: string; currency: string; amount: number; onClose: () => void; onDone: () => void }) {
  const [note, setNote] = useState('');
  const [key] = useState(() => idempotencyKey());
  const [paidKey] = useState(() => idempotencyKey());
  const [made, setMade] = useState<null | { id: string; amount_minor: number; currency: string; pay_to: PayTo }>(null);
  const [reference, setReference] = useState('');
  const { busy, run } = useAction();

  if (made) {
    return (
      <Dialog open title="Pay, then record it" description="Send the money from Umar Techy’s account, then enter the transaction reference. You can also do this later under Payouts." onClose={onDone}
        footer={<><Button onClick={onDone}>Later</Button><Button variant="primary" busy={busy === 'paid'} disabled={reference.trim().length < 3} onClick={() => run('paid', () => adminFetch(`/super-admin/resellers/payouts/${made.id}/paid`, { method: 'POST', body: { reference }, headers: { 'Idempotency-Key': paidKey } }), { success: 'Recorded as paid.' }).then((r) => r && onDone())}>Record as paid</Button></>}>
        <p className="text-sm text-slate-600">Amount</p>
        <p className="text-xl font-semibold">{money(made.amount_minor, made.currency)}</p>
        <PayToDetails to={made.pay_to} />
        <TextField label="Transaction reference" value={reference} onChange={setReference} maxLength={120} className="mt-4" hint="The reference the bank or wallet gave for this transfer." />
      </Dialog>
    );
  }

  return (
    <Dialog open title={`Prepare a payout in ${currency}`} description="Gathers every approved commission and adjustment in this currency not paid yet. Nothing is sent from here." onClose={onClose}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" busy={busy === 'make'} onClick={() => run('make', () => adminFetch<{ data: { id: string; amount_minor: number; currency: string; pay_to: PayTo } }>(`/super-admin/resellers/${id}/payouts`, { method: 'POST', body: { currency, note: note || null }, headers: { 'Idempotency-Key': key } })).then((r) => r && setMade(r.data))}>Prepare payout</Button></>}>
      <p className="text-sm text-slate-700">Payable now: <strong>{money(amount, currency)}</strong></p>
      <TextAreaField label="Note" optional value={note} onChange={setNote} rows={2} maxLength={500} className="mt-3" />
    </Dialog>
  );
}

function AdjustmentDialog({ id, currencies, onClose, onDone }: { id: string; currencies: string[]; onClose: () => void; onDone: () => void }) {
  const [direction, setDirection] = useState<'add' | 'deduct'>('add');
  const [currency, setCurrency] = useState(currencies[0] ?? 'PKR');
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [key] = useState(() => idempotencyKey());
  const minor = toMinor(amount, currency);

  return (
    <FormDialog title="Add an adjustment" description="A correction to what this reseller is owed, settled with the next payout in that currency. Recorded with your name; never edited." submitLabel="Record adjustment" onClose={onClose}
      send={() => adminFetch<{ message: string }>(`/super-admin/resellers/${id}/adjustments`, { method: 'POST', body: { amount_minor: direction === 'add' ? minor : -(minor ?? 0), currency, reason }, headers: { 'Idempotency-Key': key } }).then((r) => { onDone(); return r; })}>
      {(errors) => (
        <>
          <SelectField label="Type" value={direction} onChange={(v) => setDirection(v as 'add' | 'deduct')} options={[{ value: 'add', label: 'Add (a bonus or correction)' }, { value: 'deduct', label: 'Deduct' }]} />
          <SelectField label="Currency" value={currency} onChange={setCurrency} options={currencies.map((c) => ({ value: c, label: c }))} />
          <TextField label="Amount" inputMode="decimal" value={amount} onChange={setAmount} error={errors.amount_minor ?? (amount && !minor ? 'Enter an amount, for example 500 or 12.50' : null)} />
          <TextAreaField label="Reason" value={reason} onChange={setReason} error={errors.reason} rows={2} maxLength={300} hint="At least 5 characters. The reseller sees it." />
        </>
      )}
    </FormDialog>
  );
}

// --- Tiers ---

function TiersTab({ o, canManage, onChanged }: { o: Overview; canManage: boolean; onChanged: () => void }) {
  const [editing, setEditing] = useState<Tier | 'new' | null>(null);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-2xl text-sm text-slate-600">A tier is the deal a group of resellers gets. Each store keeps the terms of the day it was brought: changing a tier changes only stores brought afterwards.</p>
        {canManage && <Button variant="primary" onClick={() => setEditing('new')}>New tier</Button>}
      </div>
      {o.tiers.length === 0 ? (
        <EmptyPanel title="No tiers yet" description="Create a tier with the partner price and commissions you offer. Umar Techy sets these terms; nothing is filled in for you." />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {o.tiers.map((t) => (
            <Card key={t.id} title={<span className="flex items-center gap-2">{t.name}{!t.is_active && <Badge>Inactive</Badge>}</span>} description={t.description ?? undefined}
              actions={canManage ? <Button size="sm" onClick={() => setEditing(t)}>Edit</Button> : undefined}>
              <ul className="space-y-1 text-sm text-slate-700">
                <li>Clients pay <strong>{t.client_discount_percent}% less</strong></li>
                <li><strong>{t.initial_commission_percent}%</strong> of the first paid invoice</li>
                <li><strong>{t.recurring_commission_percent}%</strong> of renewals {t.recurring_months === null ? 'for as long as the client pays' : t.recurring_months === 0 ? '— none' : `for ${t.recurring_months} months`}</li>
                <li>Approved {t.hold_days} days after payment</li>
                <li>{t.can_provision ? `Creates stores${t.max_stores ? ` (up to ${t.max_stores})` : ''}` : 'Sign-up link only'}</li>
                <li className="text-slate-500">{t.resellers} reseller{t.resellers === 1 ? '' : 's'}</li>
              </ul>
            </Card>
          ))}
        </div>
      )}
      {editing && <TierDialog tier={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); onChanged(); }} />}
    </div>
  );
}

function TierDialog({ tier, onClose, onDone }: { tier: Tier | null; onClose: () => void; onDone: () => void }) {
  const [form, setForm] = useState({
    name: tier?.name ?? '', description: tier?.description ?? '', client_discount_percent: String(tier?.client_discount_percent ?? ''),
    initial_commission_percent: String(tier?.initial_commission_percent ?? ''), recurring_commission_percent: String(tier?.recurring_commission_percent ?? ''),
    recurring_months: tier?.recurring_months === null || tier === null ? '' : String(tier.recurring_months), hold_days: String(tier?.hold_days ?? '30'),
    max_stores: tier?.max_stores ? String(tier.max_stores) : '', payout_threshold: tier?.payout_threshold_minor ? String(tier.payout_threshold_minor / 100) : '',
  });
  const [canProvision, setCanProvision] = useState(tier?.can_provision ?? false);
  const [active, setActive] = useState(tier?.is_active ?? true);
  const set = (k: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [k]: value }));

  const body = {
    name: form.name, description: form.description || null, client_discount_percent: Number(form.client_discount_percent || 0),
    initial_commission_percent: Number(form.initial_commission_percent || 0), recurring_commission_percent: Number(form.recurring_commission_percent || 0),
    recurring_months: form.recurring_months === '' ? null : Number(form.recurring_months), hold_days: Number(form.hold_days || 0),
    can_provision: canProvision, max_stores: form.max_stores === '' ? null : Number(form.max_stores),
    payout_threshold_minor: Math.round(Number(form.payout_threshold || 0) * 100), is_active: active,
  };

  return (
    <FormDialog title={tier ? `Edit ${tier.name}` : 'New tier'} description="Changes apply to stores brought from now on. Asks for your password again." submitLabel={tier ? 'Save tier' : 'Create tier'} onClose={onClose}
      send={() => adminFetch<{ message: string }>(tier ? `/super-admin/resellers/tiers/${tier.id}` : '/super-admin/resellers/tiers', { method: tier ? 'PUT' : 'POST', body }).then((r) => { onDone(); return r; })}>
      {(errors) => (
        <>
          <TextField label="Name" value={form.name} onChange={set('name')} error={errors.name} maxLength={80} required />
          <TextField label="Description" optional value={form.description} onChange={set('description')} error={errors.description} maxLength={500} />
          <div className="grid gap-4 sm:grid-cols-3">
            <TextField label="Client discount (%)" inputMode="decimal" value={form.client_discount_percent} onChange={set('client_discount_percent')} error={errors.client_discount_percent} hint="Up to 50%." />
            <TextField label="First invoice (%)" inputMode="decimal" value={form.initial_commission_percent} onChange={set('initial_commission_percent')} error={errors.initial_commission_percent} />
            <TextField label="Renewals (%)" inputMode="decimal" value={form.recurring_commission_percent} onChange={set('recurring_commission_percent')} error={errors.recurring_commission_percent} />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Renewals for (months)" optional inputMode="numeric" value={form.recurring_months} onChange={set('recurring_months')} error={errors.recurring_months} hint="Empty: as long as the client pays. 0: first invoice only." />
            <TextField label="Hold (days)" inputMode="numeric" value={form.hold_days} onChange={set('hold_days')} error={errors.hold_days} hint="After the invoice is paid, before approval." />
            <TextField label="Least payout (in the payout currency)" optional inputMode="decimal" value={form.payout_threshold} onChange={set('payout_threshold')} error={errors.payout_threshold_minor} />
            <TextField label="Most stores they may create" optional inputMode="numeric" value={form.max_stores} onChange={set('max_stores')} error={errors.max_stores} hint="Empty: no limit." />
          </div>
          <CheckboxField label="May create stores for clients" checked={canProvision} onChange={setCanProvision} />
          {tier && <CheckboxField label="Active (offered to new resellers)" checked={active} onChange={setActive} />}
        </>
      )}
    </FormDialog>
  );
}

// --- Commissions ---

function CommissionsTab({ canManage, onChanged }: { canManage: boolean; onChanged: () => void }) {
  const [status, setStatus] = useState('');
  const list = useApi<{ data: Commission[] }>('/super-admin/resellers/commissions', { status: status || undefined });
  const [rejecting, setRejecting] = useState<Commission | null>(null);
  const rows = list.data?.data ?? [];

  return (
    <div className="space-y-4">
      <FilterBar>
        <div className="w-56">
          <SelectField label="Status" value={status} onChange={setStatus} options={[{ value: '', label: 'All' }, ...Object.entries(COMMISSION).map(([value, [label]]) => ({ value, label }))]} />
        </div>
      </FilterBar>
      {list.error ? (
        <ErrorPanel message={list.error} onRetry={list.reload} />
      ) : list.data === null ? (
        <Skeleton lines={6} />
      ) : rows.length === 0 ? (
        <EmptyPanel title="No commissions" description="They are made every hour from paid Umar Techy invoices of stores resellers brought." />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <caption className="sr-only">Reseller commissions</caption>
            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
              <tr>
                <th scope="col" className="px-4 py-3">Reseller / store</th>
                <th scope="col" className="px-4 py-3">Invoice</th>
                <th scope="col" className="px-4 py-3 text-right">Commission</th>
                <th scope="col" className="px-4 py-3">Status</th>
                <th scope="col" className="px-4 py-3"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((c) => (
                <tr key={c.id}>
                  <td className="px-4 py-3"><span className="block font-medium">{c.reseller.name}</span><span className="block text-xs text-slate-500">{c.store}</span></td>
                  <td className="px-4 py-3">
                    <span className="block font-mono text-xs">{c.invoice}</span>
                    <span className="block text-xs text-slate-500">{c.kind} · {c.rate_percent}% of {money(c.base_minor, c.currency)}</span>
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {money(c.net_minor, c.currency)}
                    {c.reversed_minor > 0 && <span className="block text-xs text-red-700">{money(c.reversed_minor, c.currency)} taken back</span>}
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={COMMISSION[c.status]?.[1] ?? 'neutral'}>{COMMISSION[c.status]?.[0] ?? c.status}</Badge>
                    {c.status === 'pending' && c.hold_until && <span className="block text-xs text-slate-500">until {formatDate(c.hold_until)}</span>}
                    {c.note && <span className="block text-xs text-slate-500">{c.note}</span>}
                  </td>
                  <td className="px-4 py-3 text-right">{canManage && ['pending', 'approved'].includes(c.status) && !c.in_payout && <Button size="sm" variant="ghost" onClick={() => setRejecting(c)}>Reject</Button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {rejecting && (
        <RejectDialog commission={rejecting} onClose={() => setRejecting(null)} onDone={() => { setRejecting(null); list.reload(); onChanged(); }} />
      )}
    </div>
  );
}

function RejectDialog({ commission, onClose, onDone }: { commission: Commission; onClose: () => void; onDone: () => void }) {
  const [note, setNote] = useState('');

  return (
    <FormDialog title="Reject this commission?" description={`${commission.reseller.name} will not be paid ${money(commission.net_minor, commission.currency)} for invoice ${commission.invoice}. They see it as not paid.`} submitLabel="Reject commission" onClose={onClose}
      send={() => adminFetch<{ message: string }>(`/super-admin/resellers/commissions/${commission.id}/reject`, { method: 'POST', body: { note } }).then((r) => { onDone(); return r; })}>
      {(errors) => <TextAreaField label="Why" value={note} onChange={setNote} error={errors.note} rows={2} maxLength={500} hint="At least 5 characters. Kept with the commission." />}
    </FormDialog>
  );
}

// --- Payouts ---

function PayoutsTab({ canManage, onChanged }: { canManage: boolean; onChanged: () => void }) {
  const list = useApi<{ data: PayoutRow[] }>('/super-admin/resellers/payouts');
  const [paying, setPaying] = useState<{ row: PayoutRow; to: PayTo } | null>(null);
  const [cancelling, setCancelling] = useState<PayoutRow | null>(null);
  const [reference, setReference] = useState('');
  const [key, setKey] = useState(() => idempotencyKey());
  const { busy, run } = useAction();
  const rows = list.data?.data ?? [];
  const done = () => { list.reload(); onChanged(); };

  function open(row: PayoutRow) {
    setReference('');
    setKey(idempotencyKey());
    void run('reveal', () => adminFetch<{ data: PayTo }>(`/super-admin/resellers/payouts/${row.id}/pay-to`, { method: 'POST' })).then((r) => r && setPaying({ row, to: r.data }));
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">Prepare a payout from a reseller’s page. Umar Techy sends the money and records the transfer’s reference here; nothing is paid automatically.</p>
      {list.error ? (
        <ErrorPanel message={list.error} onRetry={list.reload} />
      ) : list.data === null ? (
        <Skeleton lines={5} />
      ) : rows.length === 0 ? (
        <EmptyPanel title="No payouts yet" />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <caption className="sr-only">Reseller payouts</caption>
            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
              <tr>
                <th scope="col" className="px-4 py-3">Reseller</th>
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
                  <td className="px-4 py-3 font-medium">{p.reseller.name}<span className="block text-xs font-normal text-slate-500">{p.method ? METHOD[p.method] : ''}</span></td>
                  <td className="px-4 py-3 text-right tabular-nums">{money(p.amount_minor, p.currency)}</td>
                  <td className="px-4 py-3"><Badge tone={p.status === 'paid' ? 'green' : p.status === 'pending' ? 'amber' : 'neutral'}>{p.status === 'pending' ? 'To pay' : p.status === 'paid' ? 'Paid' : 'Cancelled'}</Badge></td>
                  <td className="px-4 py-3 font-mono text-xs">{p.reference ?? '—'}</td>
                  <td className="px-4 py-3 text-slate-600">{formatDate(p.paid_at ?? p.created_at)}</td>
                  <td className="px-4 py-3 text-right">
                    {canManage && p.status === 'pending' && (
                      <span className="inline-flex gap-2">
                        <Button size="sm" variant="primary" busy={busy === 'reveal'} onClick={() => open(p)}>Pay</Button>
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
      {paying && (
        <Dialog open title={`Pay ${paying.row.reseller.name}`} description={`${money(paying.row.amount_minor, paying.row.currency)} — record it only once the money has been sent.`} onClose={() => setPaying(null)} busy={busy === 'paid'}
          footer={<><Button onClick={() => setPaying(null)}>Cancel</Button><Button variant="primary" busy={busy === 'paid'} disabled={reference.trim().length < 3} onClick={() => run('paid', () => adminFetch(`/super-admin/resellers/payouts/${paying.row.id}/paid`, { method: 'POST', body: { reference }, headers: { 'Idempotency-Key': key } }), { success: 'Recorded as paid.' }).then((r) => { if (r) { setPaying(null); done(); } })}>Record as paid</Button></>}>
          <PayToDetails to={paying.to} />
          <TextField label="Transaction reference" value={reference} onChange={setReference} maxLength={120} className="mt-4" />
        </Dialog>
      )}
      <ConfirmDialog open={cancelling !== null} title="Cancel this payout?" confirmLabel="Cancel payout" busy={busy === 'cancel'} onClose={() => setCancelling(null)}
        onConfirm={() => cancelling && run('cancel', () => adminFetch(`/super-admin/resellers/payouts/${cancelling.id}/cancel`, { method: 'POST' }), { success: 'Cancelled.' }).then((r) => { setCancelling(null); if (r) done(); })}>
        Use this only if the money was not sent. Its commissions and adjustments become payable again.
      </ConfirmDialog>
    </div>
  );
}
