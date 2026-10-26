import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import { Card, EmptyPanel, ErrorPanel, Skeleton, StatCard, Tabs } from '@/Components/ui/Page';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import Badge from '@/Components/ui/Badge';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import { FormError, SwitchField, TextField } from '@/Components/ui/Form';
import { adminFetch, fieldErrors } from '@/lib/adminApi';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { formatDate } from '@/lib/datetime';
import { fromMinor, money, toMinor } from '@/lib/money';
import { toast } from '@/Components/ui/toast';


/**
 * Phase P2 — Marketing → Loyalty points (Premium). The store sets its own
 * rates; customers earn points on delivered, paid orders, climb tiers, get
 * birthday and repeat-purchase rewards, and turn points into store credit.
 * Everything here is worked out by the server; the page only explains it.
 */
export type Tier = { name: string; min_points: number; bonus_percent: number };
type Program = {
  is_enabled: boolean;
  currency: string;
  earn_points: number;
  earn_spend_minor: number;
  redeem_points: number;
  redeem_value_minor: number;
  min_redeem_points: number;
  hold_days: number;
  expiry_days: number | null;
  birthday_points: number;
  repeat_every: number | null;
  repeat_points: number;
  tiers: Tier[];
  started_at: string | null;
  return_rate: number;
};
type Overview = {
  included: boolean;
  running: boolean;
  can_manage: boolean;
  can_adjust: boolean;
  currency: string;
  max_tiers: number;
  program: Program | null;
  stats: Record<'members' | 'outstanding_points' | 'outstanding_value_minor' | 'pending_points' | 'earned_30d' | 'redeemed_30d' | 'redeemed_value_30d_minor', number>;
};
type Member = { customer: { id: string | null; name: string | null; email: string | null }; balance: number; lifetime_points: number; tier: string | null; last_activity_at: string | null };

const n = (value: number) => value.toLocaleString();
const percent = (rate: number) => `${(rate * 100).toLocaleString(undefined, { maximumFractionDigits: 2 })}%`;

/** One plain sentence per rule — the same words the shop uses. */
export function programSummary(p: Pick<Program, 'currency' | 'earn_points' | 'earn_spend_minor' | 'redeem_points' | 'redeem_value_minor' | 'min_redeem_points' | 'hold_days' | 'expiry_days' | 'birthday_points' | 'repeat_every' | 'repeat_points'>): string[] {
  const c = p.currency;
  return [
    `${n(p.earn_points)} ${p.earn_points === 1 ? 'point' : 'points'} for every ${money(p.earn_spend_minor, c)} spent on products.`,
    `${n(p.redeem_points)} points = ${money(p.redeem_value_minor, c)} of store credit, from ${n(p.min_redeem_points)} points.`,
    p.hold_days > 0 ? `Points can be used ${p.hold_days} ${p.hold_days === 1 ? 'day' : 'days'} after delivery.` : 'Points can be used as soon as the order is delivered and paid.',
    ...(p.repeat_every && p.repeat_points ? [`Every ${ordinal(p.repeat_every)} order brings ${n(p.repeat_points)} bonus points.`] : []),
    ...(p.birthday_points ? [`${n(p.birthday_points)} points on the customer’s birthday.`] : []),
    p.expiry_days ? `Points expire after ${n(p.expiry_days)} days without earning or using any.` : 'Points do not expire.',
  ];
}

function ordinal(value: number): string {
  const suffix = value % 100 >= 11 && value % 100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' } as Record<number, string>)[value % 10] ?? 'th';
  return `${value}${suffix}`;
}

export default function Loyalty() {
  const overview = useApi<{ data: Overview }>('/loyalty/overview');
  const [tab, setTab] = useState('overview');
  const o = overview.data?.data;

  return (
    <AdminPage title="Loyalty points" description="Reward customers who come back: points on every order, tiers, birthday and repeat-purchase bonuses — paid out as store credit.">
      {overview.error ? (
        <ErrorPanel message={overview.error} onRetry={overview.reload} />
      ) : o === undefined ? (
        <Skeleton lines={8} />
      ) : (
        <>
          <ProgramBar o={o} onChanged={overview.reload} onSettings={() => setTab('settings')} />
          <Tabs
            label="Loyalty sections"
            active={tab}
            onChange={setTab}
            tabs={[
              { id: 'overview', label: 'Overview' },
              { id: 'members', label: 'Members' },
              { id: 'settings', label: 'Program settings' },
            ]}
          />
          {tab === 'overview' && <OverviewTab o={o} />}
          {tab === 'members' && <MembersTab o={o} />}
          {tab === 'settings' && <SettingsTab o={o} onSaved={overview.reload} />}
        </>
      )}
    </AdminPage>
  );
}

function ProgramBar({ o, onChanged, onSettings }: { o: Overview; onChanged: () => void; onSettings: () => void }) {
  const { busy, run } = useAction();
  const [stopping, setStopping] = useState(false);
  const p = o.program;
  const state = p === null ? (['Not set up', 'neutral'] as const) : o.running ? (['Running', 'green'] as const) : p.is_enabled ? (['Not in your package', 'amber'] as const) : (['Off', 'neutral'] as const);

  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-2">
            <span className="font-semibold text-slate-900">Loyalty program</span>
            <Badge tone={state[1]}>{state[0]}</Badge>
            {p && <span className="text-xs text-slate-500">About {percent(p.return_rate)} of spending comes back</span>}
          </p>
          {p ? (
            <ul className="mt-2 list-disc space-y-0.5 pl-5 text-sm text-slate-600">
              {programSummary(p).map((line) => <li key={line}>{line}</li>)}
            </ul>
          ) : (
            <p className="mt-1 text-sm text-slate-600">Choose how many points customers earn and what they are worth. Nothing is given until you turn the program on.</p>
          )}
          {!o.included && <p className="mt-2 text-sm text-amber-800">Loyalty points are part of the Premium package. You can prepare the program now; turning it on needs a package that includes it.</p>}
        </div>
        {o.can_manage && (
          <div className="flex flex-wrap gap-2">
            {p === null ? (
              <Button variant="primary" onClick={onSettings}>Set up the program</Button>
            ) : p.is_enabled ? (
              <Button onClick={() => setStopping(true)}>Turn off</Button>
            ) : (
              <Button variant="primary" busy={busy === 'on'} disabled={!o.included} onClick={() => run('on', () => adminFetch('/loyalty/program/enable', { method: 'POST' }), { success: 'Loyalty points are on.' }).then((r) => r && onChanged())}>
                Turn on
              </Button>
            )}
          </div>
        )}
      </div>
      <ConfirmDialog
        open={stopping}
        title="Turn off loyalty points?"
        confirmLabel="Turn off"
        variant="primary"
        busy={busy === 'off'}
        onClose={() => setStopping(false)}
        onConfirm={() => run('off', () => adminFetch('/loyalty/program/disable', { method: 'POST' }), { success: 'Turned off.' }).then((r) => { setStopping(false); if (r) onChanged(); })}
      >
        New orders stop earning points. Customers keep the points they have and can still turn them into store credit. Orders already placed still earn theirs.
      </ConfirmDialog>
    </Card>
  );
}

function OverviewTab({ o }: { o: Overview }) {
  const c = o.currency;
  const s = o.stats;

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard icon="users" label="Members" value={n(s.members)} hint="Customers who have earned points" />
        <StatCard icon="star" label="Points held" value={n(s.outstanding_points)} hint={`Worth ${money(s.outstanding_value_minor, c)} if all were used`} />
        <StatCard icon="clock" label="Points on the way" value={n(s.pending_points)} hint="Orders not yet delivered and paid" />
        <StatCard icon="wallet" label="Used (30 days)" value={n(s.redeemed_30d)} hint={`${money(s.redeemed_value_30d_minor, c)} of store credit · ${n(s.earned_30d)} earned`} />
      </div>
      <Card title="How it works" icon="award">
        <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-600">
          <li>Customers with an account earn points on what they pay for products — after discounts, without delivery, tax added on top, or what they paid with store credit.</li>
          <li>The points show as “on the way” at once, and can be used when the order is delivered and paid and your hold period is over.</li>
          <li>A refund takes back the same share of the points. A cancelled order earns nothing.</li>
          <li>Customers turn points into store credit in their account, in steps you choose, and use it at checkout. Points are never a discount code.</li>
          <li>Tiers give regular customers extra points on every order; birthdays and every few orders bring a bonus.</li>
        </ol>
      </Card>
    </div>
  );
}

function MembersTab({ o }: { o: Overview }) {
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [query, setQuery] = useState('');
  useEffect(() => {
    const timer = window.setTimeout(() => { setQuery(q.trim()); setPage(1); }, 300);
    return () => window.clearTimeout(timer);
  }, [q]);
  const members = useApi<{ data: Member[]; meta: { current_page: number; last_page: number; total: number } }>('/loyalty/members', { q: query || undefined, page: page > 1 ? String(page) : undefined });
  const rows = members.data?.data;
  const meta = members.data?.meta;
  const c = o.currency;

  return (
    <div className="space-y-4">
      <FilterBar>
        <SearchField label="Search members" value={q} onChange={setQ} placeholder="Name or email" />
      </FilterBar>
      {members.error ? (
        <ErrorPanel message={members.error} onRetry={members.reload} />
      ) : rows === undefined ? (
        <Skeleton lines={6} />
      ) : rows.length === 0 ? (
        <EmptyPanel icon="star" title={query ? 'No member matches' : 'No members yet'} description={query ? 'Try another name or email.' : 'Customers appear here once they earn their first points.'} />
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="py-2 pe-4 font-medium">Customer</th>
                  <th className="py-2 pe-4 font-medium">Tier</th>
                  <th className="py-2 pe-4 text-end font-medium">Points</th>
                  <th className="py-2 pe-4 text-end font-medium">Worth</th>
                  <th className="py-2 pe-4 text-end font-medium">Earned in all</th>
                  <th className="py-2 font-medium">Last active</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((m) => (
                  <tr key={m.customer.id ?? m.customer.name} className="transition-colors hover:bg-slate-50">
                    <td className="py-2.5 pe-4">
                      {m.customer.id ? (
                        <Link href={`/customers/${m.customer.id}`} className={`rounded font-medium text-brand-700 hover:underline ${FOCUS_RING}`}>{m.customer.name}</Link>
                      ) : (
                        <span className="font-medium">{m.customer.name}</span>
                      )}
                      {m.customer.email && <span className="block text-xs text-slate-500">{m.customer.email}</span>}
                    </td>
                    <td className="py-2.5 pe-4">{m.tier ? <Badge tone="blue">{m.tier}</Badge> : <span className="text-slate-400">—</span>}</td>
                    <td className="py-2.5 pe-4 text-end font-semibold tabular-nums text-slate-900">{n(m.balance)}</td>
                    <td className="py-2.5 pe-4 text-end tabular-nums">{o.program ? money(Math.floor(m.balance / o.program.redeem_points) * o.program.redeem_value_minor, c) : '—'}</td>
                    <td className="py-2.5 pe-4 text-end tabular-nums text-slate-600">{n(m.lifetime_points)}</td>
                    <td className="py-2.5 text-slate-600">{m.last_activity_at ? formatDate(m.last_activity_at) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && meta.last_page > 1 && (
            <div className="mt-4 flex items-center justify-between gap-3 text-sm text-slate-600">
              <span>{n(meta.total)} members · page {meta.current_page} of {meta.last_page}</span>
              <span className="flex gap-2">
                <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
                <Button size="sm" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>Next</Button>
              </span>
            </div>
          )}
        </Card>
      )}
    </div>
  );
}

// --- Settings ---

type TierRow = { name: string; min_points: string; bonus_percent: string };
type Form = {
  earn_points: string; earn_spend: string; redeem_points: string; redeem_value: string; min_redeem_points: string; hold_days: string;
  expires: boolean; expiry_days: string; birthday_points: string; repeat: boolean; repeat_every: string; repeat_points: string; tiers: TierRow[];
};

function formFrom(o: Overview): Form {
  const p = o.program;
  const c = o.currency;
  if (p === null) {
    // Nothing is proposed: the store chooses its own rates (the placeholders show the format only).
    return { earn_points: '', earn_spend: '', redeem_points: '', redeem_value: '', min_redeem_points: '', hold_days: '0', expires: false, expiry_days: '', birthday_points: '0', repeat: false, repeat_every: '', repeat_points: '', tiers: [] };
  }

  return {
    earn_points: String(p.earn_points), earn_spend: fromMinor(p.earn_spend_minor, c), redeem_points: String(p.redeem_points), redeem_value: fromMinor(p.redeem_value_minor, c),
    min_redeem_points: String(p.min_redeem_points), hold_days: String(p.hold_days), expires: p.expiry_days !== null, expiry_days: p.expiry_days ? String(p.expiry_days) : '',
    birthday_points: String(p.birthday_points), repeat: p.repeat_every !== null && p.repeat_points > 0, repeat_every: p.repeat_every ? String(p.repeat_every) : '', repeat_points: p.repeat_points ? String(p.repeat_points) : '',
    tiers: p.tiers.map((t) => ({ name: t.name, min_points: String(t.min_points), bonus_percent: String(t.bonus_percent) })),
  };
}

const int = (text: string) => (text.trim() === '' ? NaN : Number(text));

function SettingsTab({ o, onSaved }: { o: Overview; onSaved: () => void }) {
  const [f, setF] = useState<Form>(() => formFrom(o));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const c = o.currency;
  const readOnly = !o.can_manage;
  const set = <K extends keyof Form>(key: K) => (value: Form[K]) => setF((prev) => ({ ...prev, [key]: value }));
  const setTier = (index: number, key: keyof TierRow, value: string) => setF((prev) => ({ ...prev, tiers: prev.tiers.map((t, i) => (i === index ? { ...t, [key]: value } : t)) }));

  // The live example: what an order of this size earns and is worth, as the server would count it.
  const rates = { earn_points: int(f.earn_points), earn_spend_minor: toMinor(f.earn_spend, c) ?? NaN, redeem_points: int(f.redeem_points), redeem_value_minor: toMinor(f.redeem_value, c) ?? NaN };
  const complete = Object.values(rates).every((v) => Number.isFinite(v) && v > 0);
  const exampleMinor = complete ? rates.earn_spend_minor * 50 : 0;
  const examplePoints = complete ? Math.floor(exampleMinor / rates.earn_spend_minor) * rates.earn_points : 0;
  // What the points are worth, also when they are not a whole step yet (used together with later points).
  const exampleValue = complete ? Math.floor((examplePoints * rates.redeem_value_minor) / rates.redeem_points) : 0;
  const rate = complete ? (rates.earn_points / rates.earn_spend_minor) * (rates.redeem_value_minor / rates.redeem_points) : 0;

  function save() {
    setBusy(true);
    setErrors({});
    setError(null);
    const body = {
      earn_points: int(f.earn_points), earn_spend_minor: toMinor(f.earn_spend, c), redeem_points: int(f.redeem_points), redeem_value_minor: toMinor(f.redeem_value, c),
      min_redeem_points: int(f.min_redeem_points), hold_days: int(f.hold_days || '0'), expiry_days: f.expires ? int(f.expiry_days) : null,
      birthday_points: int(f.birthday_points || '0'), repeat_every: f.repeat ? int(f.repeat_every) : null, repeat_points: f.repeat ? int(f.repeat_points) : 0,
      tiers: f.tiers.map((t) => ({ name: t.name, min_points: int(t.min_points), bonus_percent: int(t.bonus_percent || '0') })),
    };
    adminFetch<{ message: string }>('/loyalty/program', { method: 'PUT', body })
      .then((r) => { toast.success(r.message); onSaved(); })
      .catch((e) => { setErrors(fieldErrors(e)); setError(e instanceof Error ? e.message : 'Could not save.'); })
      .finally(() => setBusy(false));
  }

  return (
    <div className="space-y-6">
      <FormError message={Object.keys(errors).length ? 'Check the highlighted fields.' : error} />
      <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div className="space-y-6">
          <Card title="Earning points" icon="star" description="Points are counted on what the customer pays for products, after discounts. Delivery and tax added on top never count.">
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField label="Points given" inputMode="numeric" value={f.earn_points} onChange={set('earn_points')} error={errors.earn_points} placeholder="e.g. 1" disabled={readOnly} />
              <TextField label={`For every (${c})`} inputMode="decimal" value={f.earn_spend} onChange={set('earn_spend')} error={errors.earn_spend_minor} placeholder="e.g. 100" disabled={readOnly} />
              <TextField label="Hold after delivery (days)" inputMode="numeric" value={f.hold_days} onChange={set('hold_days')} error={errors.hold_days} hint="Points wait this long so returns are settled first. 0 to 365." disabled={readOnly} />
            </div>
          </Card>
          <Card title="Using points" icon="wallet" description="Customers turn points into store credit in their account and use it at checkout.">
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField label="Points" inputMode="numeric" value={f.redeem_points} onChange={set('redeem_points')} error={errors.redeem_points} placeholder="e.g. 100" hint="Used in steps of this many." disabled={readOnly} />
              <TextField label={`Are worth (${c})`} inputMode="decimal" value={f.redeem_value} onChange={set('redeem_value')} error={errors.redeem_value_minor} placeholder="e.g. 50" disabled={readOnly} />
              <TextField label="Least points to use" inputMode="numeric" value={f.min_redeem_points} onChange={set('min_redeem_points')} error={errors.min_redeem_points} hint="At least one step." disabled={readOnly} />
              <div className="space-y-3">
                <SwitchField label="Points expire" hint="After a time without earning or using any." checked={f.expires} onChange={set('expires')} disabled={readOnly} />
                {f.expires && <TextField label="Expire after (days)" inputMode="numeric" value={f.expiry_days} onChange={set('expiry_days')} error={errors.expiry_days} hint="30 to 3650." disabled={readOnly} />}
              </div>
            </div>
          </Card>
          <Card title="Bonus rewards" icon="sparkles">
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField label="Birthday points" inputMode="numeric" value={f.birthday_points} onChange={set('birthday_points')} error={errors.birthday_points} hint="Once a year, to customers who have shopped and gave their birthday. 0: none." disabled={readOnly} />
              <div className="space-y-3">
                <SwitchField label="Repeat-purchase bonus" hint="Bonus points on every few orders." checked={f.repeat} onChange={set('repeat')} disabled={readOnly} />
                {f.repeat && (
                  <div className="grid grid-cols-2 gap-3">
                    <TextField label="Every … orders" inputMode="numeric" value={f.repeat_every} onChange={set('repeat_every')} error={errors.repeat_every} placeholder="e.g. 5" disabled={readOnly} />
                    <TextField label="Bonus points" inputMode="numeric" value={f.repeat_points} onChange={set('repeat_points')} error={errors.repeat_points} disabled={readOnly} />
                  </div>
                )}
              </div>
            </div>
          </Card>
          <Card
            title="Tiers"
            icon="award"
            description="Customers move up as they earn points; each tier adds a bonus to the points of every order. Points given by hand do not count."
            actions={!readOnly && f.tiers.length < o.max_tiers ? <Button size="sm" onClick={() => set('tiers')([...f.tiers, { name: '', min_points: '', bonus_percent: '' }])}>Add tier</Button> : undefined}
          >
            {f.tiers.length === 0 ? (
              <p className="text-sm text-slate-600">No tiers: every customer earns at the same rate.</p>
            ) : (
              <ul className="space-y-3">
                {f.tiers.map((t, i) => (
                  <li key={i} className="grid items-end gap-3 rounded-xl border border-slate-200 p-3 transition hover:border-brand-200 sm:grid-cols-[1fr_1fr_1fr_auto]">
                    <TextField label="Name" value={t.name} onChange={(v) => setTier(i, 'name', v)} error={errors[`tiers.${i}.name`]} maxLength={40} placeholder="e.g. Gold" disabled={readOnly} />
                    <TextField label="From points earned" inputMode="numeric" value={t.min_points} onChange={(v) => setTier(i, 'min_points', v)} error={errors[`tiers.${i}.min_points`]} disabled={readOnly} />
                    <TextField label="Extra points (%)" inputMode="numeric" value={t.bonus_percent} onChange={(v) => setTier(i, 'bonus_percent', v)} error={errors[`tiers.${i}.bonus_percent`]} placeholder="0 to 200" disabled={readOnly} />
                    {!readOnly && <Button size="sm" variant="ghost" onClick={() => set('tiers')(f.tiers.filter((_, j) => j !== i))}>Remove</Button>}
                  </li>
                ))}
              </ul>
            )}
            {errors.tiers && <p className="mt-2 text-sm text-red-700">{errors.tiers}</p>}
          </Card>
        </div>

        <aside className="space-y-4 lg:sticky lg:top-4 lg:self-start">
          <Card title="Example" icon="receipt" description="What one order earns with these settings.">
            {complete ? (
              <dl className="space-y-3 text-sm">
                <div className="flex justify-between gap-3"><dt className="text-slate-600">An order of</dt><dd className="font-medium tabular-nums">{money(exampleMinor, c)}</dd></div>
                <div className="flex justify-between gap-3"><dt className="text-slate-600">Earns</dt><dd className="font-semibold tabular-nums text-slate-900">{n(examplePoints)} points</dd></div>
                <div className="flex justify-between gap-3"><dt className="text-slate-600">Worth about</dt><dd className="font-semibold tabular-nums text-emerald-700">{money(exampleValue, c)}</dd></div>
                <div className={`rounded-xl px-3 py-2 ${rate > 1 ? 'bg-red-50 text-red-800' : rate > 0.1 ? 'bg-amber-50 text-amber-900' : 'bg-emerald-50 text-emerald-900'}`}>
                  About <strong>{percent(rate)}</strong> of spending comes back as store credit{rate > 1 ? ' — more than customers spend, which cannot be saved.' : rate > 0.1 ? ' — generous; check it is what you mean.' : '.'}
                </div>
              </dl>
            ) : (
              <p className="text-sm text-slate-600">Fill in earning and using points to see an example.</p>
            )}
          </Card>
          {!readOnly && (
            <div className="space-y-2">
              <Button variant="primary" className="w-full" busy={busy} onClick={save}>Save program</Button>
              <p className="text-xs text-slate-500">New rates apply to orders from now on. Orders already placed keep the points they were counted with.</p>
            </div>
          )}
        </aside>
      </div>
    </div>
  );
}
