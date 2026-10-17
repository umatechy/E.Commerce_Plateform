import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import QuickStart from '@/Components/Dashboard/QuickStart';
import { PageHeader, Card, StatCard, QueryState, EmptyPanel, UsageMeter, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import Button, { ButtonLink, FOCUS_RING } from '@/Components/ui/Button';
import { SelectField } from '@/Components/ui/Form';
import { StatusBadge } from '@/Components/ui/Badge';
import HealthCheckList, { type HealthCheck, type HealthStatus } from '@/Components/HealthCheckList';
import { useAccess, useAuth } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { displayTimezoneName, formatDateTime } from '@/lib/datetime';
import { DATE_FILTERS, USAGE_LABELS, labelFor } from '@/lib/labels';
import Icon, { type IconName } from '@/Components/ui/Icon';
import BarChart from '@/Components/ui/BarChart';

/**
 * The Store Admin home (Module 22 §10 "Store Dashboard").
 *
 * Every figure comes from the API as the server computed it for the
 * store's own timezone: /dashboard (B12), /storefront/setup (B24),
 * /orders, /store/health (B21) and /subscription/usage (B2). Nothing is
 * calculated or invented here. A section the user's role does not
 * include is not requested and not shown; a figure the server did not
 * send is shown as "Not available".
 */
type Summary = {
  period: { start: string; end: string };
  revenue_minor?: number;
  net_sales_minor?: number;
  collected_amount_minor?: number;
  refunded_amount_minor?: number;
  average_order_value_minor?: number | null;
  order_count: number;
  pending_orders: number;
  completed_orders: number;
  new_customers: number;
  low_stock_products: number;
};

type Comparison = { current: Summary; previous: Summary; revenue_change_percent?: number | null; order_count_change_percent: number | null };

type SetupCheck = { key: string; group?: string; required: boolean; done: boolean; message: string };
// Phase B44 (Module 03 §24, §57): progress, what blocks the launch, whether payment comes first.
type Setup = { launched: boolean; availability: string; checks: SetupCheck[]; progress?: { done: number; total: number; percent: number }; blocking?: string[]; requires_payment?: boolean };

const SETUP_GROUPS: Record<string, string> = { essentials: 'Essentials', operations: 'Running your store', growth: 'Growing it' };

type OrderRow = { id: string; order_number: string; status: string; grand_total_minor: number; currency: string; created_at: string; guest_name?: string | null; customer?: { name: string } | null };
type Health = { status: HealthStatus; checked_at: string; checks: HealthCheck[] };
type Usage = Record<string, { limit: number | null; current: number; unlimited: boolean }>;

const SETUP_LINK: Record<string, string> = {
  business_info: '/settings',
  products: '/products',
  categories: '/categories',
  subscription: '/billing',
  payment: '/billing',
  payment_methods: '/payments',
  policies: '/content/pages',
  test_order: '/orders/new',
  seo: '/content/seo',
  warehouse: '/warehouses',
  shipping: '/shipping',
  branding: '/storefront/theme',
  domain: '/domains',
};

/** Phase B54 (Module 34 §13–14): the help article for each setup step. */
const SETUP_HELP: Record<string, string> = {
  business_info: 'store-settings',
  products: 'add-a-product',
  categories: 'organise-your-catalog',
  subscription: 'billing-invoices',
  payment: 'billing-invoices',
  payment_methods: 'payment-methods',
  policies: 'pages-and-seo',
  test_order: 'create-an-order',
  seo: 'pages-and-seo',
  warehouse: 'manage-stock',
  shipping: 'shipping-setup',
  branding: 'storefront-theme',
  domain: 'connect-a-domain',
};

function change(percent: number | null | undefined): string | undefined {
  if (percent === null || percent === undefined) return undefined;

  return `${percent > 0 ? '+' : ''}${percent}% against the period before`;
}

/** A small figure in the second row: what needs doing, at a glance. */
function MiniStat({ label, value, href, icon, tone = 'slate' }: { label: string; value: string | number; href?: string; icon: IconName; tone?: 'slate' | 'amber' | 'emerald' }) {
  const colour = { slate: 'bg-slate-100 text-slate-600', amber: 'bg-amber-50 text-amber-700', emerald: 'bg-emerald-50 text-emerald-700' }[tone];
  const body = (
    <>
      <span className={`flex h-10 w-10 flex-none items-center justify-center rounded-xl ${colour}`}><Icon name={icon} size={18} /></span>
      <span className="min-w-0">
        <span className="block truncate text-xs text-slate-500">{label}</span>
        <span className="block text-lg font-semibold text-slate-900">{value}</span>
      </span>
    </>
  );
  const box = 'flex items-center gap-3 rounded-2xl border border-slate-200/80 bg-white px-4 py-3.5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]';

  return href ? (
    <Link href={href} className={`${box} transition duration-200 hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-lg hover:shadow-slate-900/5 motion-reduce:transform-none motion-reduce:transition-none ${FOCUS_RING}`}>{body}</Link>
  ) : (
    <div className={box}>{body}</div>
  );
}

type SalesDay = { date: string; order_count: number; revenue_minor: number };

/** A moment as the store's calendar day (YYYY-MM-DD), in the store's timezone. */
function storeDay(iso: string): string | null {
  const moment = new Date(iso);
  if (Number.isNaN(moment.getTime())) return null;

  return new Intl.DateTimeFormat('en-CA', { timeZone: displayTimezoneName(), year: 'numeric', month: '2-digit', day: '2-digit' }).format(moment);
}

/**
 * Every day of the period up to today (or, without a period, from the first
 * to the last day with sales). A day without orders is a real zero.
 */
function fillDays(days: SalesDay[], period?: { start: string; end: string }): SalesDay[] {
  const today = storeDay(new Date().toISOString()) ?? '9999-12-31';
  const start = period ? storeDay(period.start) : null;
  const end = period ? storeDay(period.end) : null;
  // Without a usable period: from the first to the last day with sales.
  const from = start ?? days[0]?.date;
  const to = end ? [end, today].sort()[0] : days[days.length - 1]?.date;
  if (!from || !to) return [];
  const byDate = new Map(days.map((d) => [d.date, d]));
  const out: SalesDay[] = [];
  const cursor = new Date(`${from}T00:00:00Z`);
  const last = new Date(`${to}T00:00:00Z`);
  while (cursor <= last && out.length < 400) {
    const key = cursor.toISOString().slice(0, 10);
    out.push(byDate.get(key) ?? { date: key, order_count: 0, revenue_minor: 0 });
    cursor.setUTCDate(cursor.getUTCDate() + 1);
  }

  return out;
}

function SalesChart({ range, currency, period }: { range: string; currency: string; period?: { start: string; end: string } }) {
  const state = useApi<{ data: SalesDay[] }>('/reports/sales', { date_filter: range });
  // Revenue figures need the financial reports permission; without it the chart is left out.
  if (state.errorStatus === 403) return null;

  return (
    <Card title="Revenue by day" actions={<ButtonLink href="/reports" size="sm">Reports</ButtonLink>}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : state.data === null ? (
        <Skeleton lines={4} />
      ) : state.data.data.length === 0 ? (
        <p className="py-8 text-center text-sm text-slate-500">No sales in this period yet.</p>
      ) : (
        <BarChart
          title="Revenue by day"
          points={fillDays(state.data.data, period).map((d) => ({
            label: new Date(`${d.date}T00:00:00Z`).toLocaleDateString(undefined, { day: 'numeric', month: 'short', timeZone: 'UTC' }),
            value: d.revenue_minor,
            display: `${money(d.revenue_minor, currency)} · ${d.order_count} order${d.order_count === 1 ? '' : 's'}`,
          }))}
        />
      )}
    </Card>
  );
}

function SalesSection({ currency, financial }: { currency: string; financial: boolean }) {
  const [range, setRange] = useState('this_month');
  const state = useApi<{ data: Comparison }>('/dashboard', { date_filter: range, compare: 1 });

  return (
    <section aria-labelledby="sales-heading" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h2 id="sales-heading" className="text-base font-semibold text-slate-900">
          Sales
        </h2>
        <div className="w-44">
          <SelectField label="Period" value={range} onChange={setRange} options={DATE_FILTERS.filter((option) => option.value !== 'custom')} />
        </div>
      </div>
      <QueryState state={state} lines={4}>
        {({ data }) => {
          const now = data.current;

          return (
            <div className="space-y-3">
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {now.revenue_minor !== undefined && <StatCard icon="wallet" label="Revenue" value={money(now.revenue_minor, currency)} trend={data.revenue_change_percent} hint={change(data.revenue_change_percent)} href="/reports" />}
                <StatCard icon="cart" label="Orders" value={now.order_count} trend={data.order_count_change_percent} hint={change(data.order_count_change_percent)} href="/orders" />
                {now.average_order_value_minor !== undefined && (
                  <StatCard icon="chart" label="Average order" value={now.average_order_value_minor === null ? null : money(now.average_order_value_minor, currency)} />
                )}
                <StatCard icon="users" label="New customers" value={now.new_customers} href="/customers" />
              </div>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <MiniStat icon="refresh" tone={now.pending_orders > 0 ? 'amber' : 'slate'} label="Waiting for confirmation" value={now.pending_orders} href="/orders?status=pending_confirmation" />
                <MiniStat icon="checkCircle" tone="emerald" label="Completed orders" value={now.completed_orders} />
                {now.collected_amount_minor !== undefined && <MiniStat icon="cash" label="Payments collected" value={money(now.collected_amount_minor, currency)} href="/payments" />}
                <MiniStat icon="box" tone={now.low_stock_products > 0 ? 'amber' : 'slate'} label="Low on stock now" value={now.low_stock_products} href="/inventory?stock=low" />
              </div>
            </div>
          );
        }}
      </QueryState>
      {financial && <SalesChart range={range} currency={currency} period={state.data?.data.current.period} />}
      <p className="text-xs text-slate-500">Amounts are shown in your store currency ({currency}). Days follow your store's timezone.</p>
    </section>
  );
}

/** UI-2: progress as a ring (the same figure the server sent). */
function ProgressRing({ percent }: { percent: number }) {
  const r = 26;
  const c = 2 * Math.PI * r;

  return (
    <div className="relative h-16 w-16 flex-none" role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100} aria-label="Setup progress">
      <svg viewBox="0 0 64 64" className="h-16 w-16 -rotate-90" aria-hidden="true">
        <circle cx="32" cy="32" r={r} fill="none" stroke="rgb(var(--brand-100))" strokeWidth="7" />
        <circle cx="32" cy="32" r={r} fill="none" stroke="rgb(var(--brand-600))" strokeWidth="7" strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c * (1 - percent / 100)} />
      </svg>
      <span className="absolute inset-0 flex items-center justify-center text-sm font-bold text-slate-900">{percent}%</span>
    </div>
  );
}

/** UI-16: the quick start opens by itself once per store on this browser, while the store is new. */
function quickStartSeen(storeId: number | undefined): boolean {
  try {
    return window.localStorage.getItem(`ut.quickstart.${storeId}`) === '1';
  } catch {
    return true;
  }
}

function markQuickStartSeen(storeId: number | undefined): void {
  try {
    window.localStorage.setItem(`ut.quickstart.${storeId}`, '1');
  } catch {
    // Not remembered: it may open again next time.
  }
}

function SetupSection({ onChanged }: { onChanged?: () => void }) {
  const state = useApi<{ data: Setup }>('/storefront/setup');
  const { busy, run } = useAction();
  const [showDone, setShowDone] = useState(false);
  const auth = useAuth();
  const access = useAccess();
  const storeId = auth.activeStore?.id;
  const [quick, setQuick] = useState(false);
  const fresh = state.data !== null && !state.data.data.launched && state.data.data.checks.some((c) => !c.done && ['business_info', 'products'].includes(c.key));
  useEffect(() => {
    if (fresh && !quickStartSeen(storeId)) {
      markQuickStartSeen(storeId);
      setQuick(true);
    }
  }, [fresh, storeId]);
  if (state.error) return state.errorStatus === 403 ? null : <ErrorPanel message={state.error} onRetry={state.reload} />;
  if (state.data === null) return <Skeleton lines={3} />;

  const setup = state.data.data;
  const open = setup.checks.filter((check) => !check.done);
  const done = setup.checks.filter((check) => check.done);
  if (setup.launched && open.length === 0) return null;

  const blocked = setup.checks.some((check) => check.required && !check.done);
  const blocking = setup.blocking ?? [];

  const row = (check: SetupCheck) => (
    <li key={check.key} className="-mx-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-lg px-2 py-2.5 transition-colors hover:bg-slate-50 motion-reduce:transition-none">
      <span className="flex min-w-0 items-start gap-2.5">
        <span className={`mt-0.5 flex h-5 w-5 flex-none items-center justify-center rounded-full ${check.done ? 'bg-emerald-500 text-white' : 'border-2 border-slate-300'}`} aria-hidden="true">
          {check.done && <Icon name="check" size={12} strokeWidth={3} />}
        </span>
        <span className={check.done ? 'text-slate-500' : 'text-slate-800'}>
          <span className="sr-only">{check.done ? 'Done: ' : 'To do: '}</span>
          {check.message}{' '}
          {check.required ? <span className="ml-1 inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-200">Required</span> : <span className="ml-1 text-xs text-slate-400">Optional</span>}
        </span>
      </span>
      {!check.done && (
        <span className="ml-7 flex items-center gap-3">
          {SETUP_HELP[check.key] && (
            <Link href={`/help/articles/${SETUP_HELP[check.key]}`} className={`rounded text-sm text-slate-500 hover:text-slate-800 hover:underline ${FOCUS_RING}`}>
              How?<span className="sr-only"> Help with: {check.message}</span>
            </Link>
          )}
          {check.key === 'payment' ? (
            <Button
              size="sm"
              busy={busy === 'first-invoice'}
              onClick={() =>
                run('first-invoice', () => adminFetch('/billing/first-invoice', { method: 'POST' }), { success: 'Your first invoice is ready under Billing.' }).then(
                  (result) => result && router.visit('/billing'),
                )
              }
            >
              Get my first invoice
            </Button>
          ) : (
            SETUP_LINK[check.key] && (
              <Link href={SETUP_LINK[check.key]} className={`group/open inline-flex items-center gap-1 rounded-lg bg-brand-50 px-3 py-1.5 text-sm font-semibold text-brand-700 transition-colors hover:bg-brand-100 motion-reduce:transition-none ${FOCUS_RING}`}>
                Open<Icon name="arrowRight" size={14} className="transition-transform group-hover/open:translate-x-0.5 motion-reduce:transform-none" />
              </Link>
            )
          )}
        </span>
      )}
    </li>
  );

  return (
    <Card
      title={setup.launched ? 'Finish setting up your store' : 'Your store is not open to customers yet'}
      description={setup.launched ? 'These optional steps are still open.' : 'Complete the required steps, then launch.'}
      actions={
        !setup.launched && (
          <>
          {fresh && (
            <Button size="sm" variant="soft" onClick={() => setQuick(true)}>
              <Icon name="zap" size={15} />Quick start
            </Button>
          )}
          <Button
            variant="primary"
            size="sm"
            disabled={blocked}
            busy={busy === 'launch'}
            title={blocked ? 'Complete the required steps first' : undefined}
            onClick={() => run('launch', () => adminFetch('/storefront/launch', { method: 'POST' }), { success: 'Your store is live.' }).then((result) => result && state.reload())}
          >
            Launch store
          </Button>
          </>
        )
      }
    >
      <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
        {setup.progress && (
          <div className="flex items-center gap-4 rounded-xl bg-slate-50 p-4 sm:w-56 sm:flex-col sm:items-start">
            <ProgressRing percent={setup.progress.percent} />
            <div className="text-sm">
              <p className="font-semibold text-slate-900">{setup.progress.done} of {setup.progress.total} steps done</p>
              {!setup.launched && blocking.length > 0 ? (
                <p className="mt-1 text-amber-800">Still needed before you can launch: {blocking.length} required step{blocking.length === 1 ? '' : 's'}.</p>
              ) : (
                <p className="mt-1 text-slate-500">{open.length} step{open.length === 1 ? '' : 's'} left</p>
              )}
            </div>
          </div>
        )}
        <div className="min-w-0 flex-1">
          {Object.entries(SETUP_GROUPS).map(([group, title]) => {
            const checks = open.filter((check) => (check.group ?? 'essentials') === group);
            if (checks.length === 0) return null;

            return (
              <div key={group} className="mb-3 last:mb-0">
                <h3 className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{title}</h3>
                <ul className="text-sm">{checks.map(row)}</ul>
              </div>
            );
          })}
          {done.length > 0 && (
            <div className="mt-2 border-t border-slate-100 pt-2">
              <button type="button" onClick={() => setShowDone((s) => !s)} aria-expanded={showDone} className={`rounded text-sm font-medium text-slate-600 hover:text-slate-900 ${FOCUS_RING}`}>
                {showDone ? 'Hide' : 'Show'} the {done.length} completed step{done.length === 1 ? '' : 's'}
              </button>
              {showDone && <ul className="text-sm">{done.map(row)}</ul>}
            </div>
          )}
        </div>
      </div>
      {quick && <QuickStart checks={setup.checks} launched={setup.launched} currency={access.currency} onChanged={() => { state.reload(); onChanged?.(); }} onClose={() => setQuick(false)} />}
    </Card>
  );
}

function RecentOrders() {
  const state = usePagedApi<OrderRow>('/orders');

  return (
    <Card title="Recent orders" actions={<ButtonLink href="/orders" size="sm">All orders</ButtonLink>}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : state.rows === null ? (
        <Skeleton lines={4} />
      ) : state.rows.length === 0 ? (
        <p className="text-sm text-slate-600">No orders yet. They appear here as customers buy.</p>
      ) : (
        <ul className="-mx-5 -my-2 divide-y divide-slate-100 text-sm">
          {state.rows.slice(0, 6).map((order) => {
            const who = order.customer?.name ?? order.guest_name ?? null;

            return (
              <li key={order.id}>
                <Link href={`/orders/${order.id}`} className={`flex items-center gap-3 px-5 py-3 transition-colors hover:bg-brand-50/50 motion-reduce:transition-none ${FOCUS_RING}`}>
                  <span className="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700" aria-hidden="true">
                    {(who ?? '#').trim().slice(0, 1).toUpperCase()}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block font-mono text-brand-700">{order.order_number}</span>
                    <span className="block truncate text-xs text-slate-500">{who ? `${who} · ` : ''}{formatDateTime(order.created_at)}</span>
                  </span>
                  <StatusBadge status={order.status} />
                  <span className="w-24 text-right font-semibold tabular-nums">{money(order.grand_total_minor, order.currency)}</span>
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}

function HealthSection() {
  const state = useApi<{ data: Health }>('/store/health');

  return (
    <Card
      title="Store health"
      actions={<ButtonLink href="/store-health" size="sm">Details</ButtonLink>}
    >
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : state.data === null ? (
        <Skeleton lines={3} />
      ) : (
        (() => {
          const report = state.data.data;
          const attention = report.checks.filter((check) => check.status !== 'ok');

          return attention.length === 0 ? (
            <p className="text-sm text-slate-700">
              <StatusBadge status="ok" label="OK" /> All {report.checks.length} checks passed.
            </p>
          ) : (
            <HealthCheckList checks={attention} />
          );
        })()
      )}
    </Card>
  );
}

function UsageSection({ packageName }: { packageName: string | null }) {
  const state = useApi<{ data: Usage }>('/subscription/usage');
  const entries = state.data ? Object.entries(state.data.data) : [];

  return (
    <Card title="Package usage" description={packageName ? `${packageName} package` : undefined}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : state.data === null ? (
        <Skeleton lines={3} />
      ) : entries.length === 0 ? (
        <p className="text-sm text-slate-600">Your package sets no usage limits.</p>
      ) : (
        <div className="space-y-3">
          {entries.map(([key, entry]) => (
            <UsageMeter key={key} label={labelFor(USAGE_LABELS, key)} current={entry.current} limit={entry.unlimited ? null : entry.limit} />
          ))}
        </div>
      )}
    </Card>
  );
}

/** Good morning / afternoon / evening, by the time on this device. */
function greeting(): string {
  const hour = new Date().getHours();

  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}

export default function Dashboard() {
  const auth = useAuth();
  const access = useAccess();
  // UI-16: bumped when the quick start changes something, so the figures below load again.
  const [version, setVersion] = useState(0);
  const firstName = auth.user?.name.split(' ')[0] ?? '';

  if (!access.hasStore) {
    return (
      <AuthenticatedLayout>
        <PageHeader title="Dashboard" description={auth.user ? `Signed in as ${auth.user.name}.` : undefined} />
        {access.isPlatformStaff ? (
          <EmptyPanel
            title="Platform administration"
            description="Your account manages the platform, not a store. Use the platform section of the menu."
            action={<ButtonLink href="/super-admin" variant="primary">Open platform overview</ButtonLink>}
          />
        ) : access.isReseller ? (
          <EmptyPanel
            title="Your partner portal"
            description="Your account sells Umar Techy as a partner. Your clients’ stores, commissions and payouts are in the partner portal."
            action={<ButtonLink href="/partner" variant="primary">Open the partner portal</ButtonLink>}
          />
        ) : (auth.stores ?? []).length > 1 ? (
          <EmptyPanel title="Choose a store" description="You belong to more than one store. Pick the one to manage from the Store list at the top of the page." />
        ) : (
          <EmptyPanel title="No store yet" description="Your account is not part of a store. Ask a store owner to invite you." />
        )}
      </AuthenticatedLayout>
    );
  }

  return (
    <AuthenticatedLayout>
      {/* UI-8: a welcome banner in the theme colour, with the most used actions. */}
      <section className="relative mb-6 overflow-hidden rounded-3xl bg-gradient-to-br from-brand-600 via-brand-700 to-brand-900 p-6 text-white shadow-lg shadow-brand-900/20 sm:p-8">
        <div aria-hidden="true" className="pointer-events-none absolute -right-16 -top-24 h-72 w-72 rounded-full bg-white/10 blur-2xl" />
        <div aria-hidden="true" className="pointer-events-none absolute -bottom-24 right-40 h-56 w-56 rounded-full bg-white/5 blur-2xl" />
        <div className="relative flex flex-wrap items-end justify-between gap-5">
          <div className="min-w-0">
            <p className="text-sm font-medium text-white/70">{new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })}</p>
            <h1 className="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">{`${greeting()}${firstName ? `, ${firstName}` : ''}`}</h1>
            <p className="mt-1.5 text-sm text-white/80">Here is how <strong className="font-semibold text-white">{auth.activeStore?.name}</strong> is doing.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            {auth.activeStore && (
              <a href={`/shop/${auth.activeStore.slug}`} target="_blank" rel="noreferrer" className={`inline-flex min-h-10 items-center gap-2 rounded-lg bg-white/10 px-4 py-2 text-sm font-semibold text-white ring-1 ring-inset ring-white/20 backdrop-blur transition hover:-translate-y-px hover:bg-white/20 motion-reduce:transform-none motion-reduce:transition-none ${FOCUS_RING} focus-visible:ring-offset-brand-700`}>
                <Icon name="store" size={16} />View shop<span className="sr-only"> (opens in a new tab)</span>
              </a>
            )}
            {access.can('orders.create') && (
              <Link href="/orders/new" className={`inline-flex min-h-10 items-center gap-2 rounded-lg bg-white/10 px-4 py-2 text-sm font-semibold text-white ring-1 ring-inset ring-white/20 backdrop-blur transition hover:-translate-y-px hover:bg-white/20 motion-reduce:transform-none motion-reduce:transition-none ${FOCUS_RING} focus-visible:ring-offset-brand-700`}>
                <Icon name="cart" size={16} />New order
              </Link>
            )}
            {access.can('products.create') && (
              <Link href="/products/new" className={`inline-flex min-h-10 items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-brand-700 shadow-sm transition hover:-translate-y-px hover:bg-brand-50 motion-reduce:transform-none motion-reduce:transition-none ${FOCUS_RING} focus-visible:ring-offset-brand-700`}>
                <Icon name="box" size={16} />Add product
              </Link>
            )}
          </div>
        </div>
      </section>

      <div className="space-y-6">
        {access.can('storefront.manage') && <SetupSection onChanged={() => setVersion((v) => v + 1)} />}
        {access.can('analytics.view') && <SalesSection key={`sales-${version}`} currency={access.currency} financial={access.isOwner || access.can('analytics.financial')} />}

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
          {access.can('orders.view') && <RecentOrders key={`orders-${version}`} />}
          <div className="space-y-4">
            {access.can('store_health.view') && <HealthSection key={`health-${version}`} />}
            <UsageSection key={`usage-${version}`} packageName={access.packageName} />
          </div>
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
