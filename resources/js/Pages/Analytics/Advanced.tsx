import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import { SelectField, TextField } from '@/Components/ui/Form';
import { FilterBar } from '@/Components/ui/Filters';
import { Card, EmptyPanel, ErrorPanel, PackageNotice, Skeleton } from '@/Components/ui/Page';
import Icon, { type IconName } from '@/Components/ui/Icon';
import { FOCUS_RING } from '@/Components/ui/Button';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { money } from '@/lib/money';
import { displayTimezoneName, formatDate } from '@/lib/datetime';
import { DATE_FILTERS } from '@/lib/labels';

/**
 * Phase P3 — Reports → Analytics (Premium). How the store is doing against
 * an earlier period, where the money comes from and goes back, who buys and
 * comes back, how many carts become orders, and which products sit still or
 * come back. Every figure is the server's; each section says how it is
 * counted (Module 22 §22: no rate without its denominator).
 */
type Kpi = { current: number | null; previous: number | null; change_percent: number | null };
export type Overview = {
  period: { start: string; end: string };
  comparison: { kind: 'previous_period' | 'previous_year'; start: string; end: string };
  financial: boolean;
  currency: string;
  kpis: Record<string, Kpi>;
  sales: { gross_minor: number; discounts_minor: number; refunds_minor: number; net_minor: number; shipping_minor: number; tax_minor: number; total_minor: number } | null;
  customers: { guest_orders: number; registered_orders: number; repeat_rate_percent: number | null; orders_per_customer: number | null; average_lifetime_value_minor: number | null };
  cohorts: { month: string; customers: number; retention: (number | null)[] }[];
  carts: { created: number; converted: number; abandoned: number; conversion_percent: number | null; average_order_value_minor: number | null; online_payments: number; online_payment_failure_percent: number | null };
  products: { slow_movers: { id: string; name: string; available: number; last_sold_at: string | null }[]; returns: { name: string; sold: number; returned: number; return_percent: number | null }[] };
};

const n = (value: number | null | undefined) => (value === null || value === undefined ? '—' : value.toLocaleString());
const pct = (value: number | null) => (value === null ? '—' : `${value.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`);
const monthName = (ym: string) => new Date(Number(ym.slice(0, 4)), Number(ym.slice(5, 7)) - 1, 1).toLocaleString(undefined, { month: 'short', year: 'numeric' });

/** Up, down or level against the comparison — an arrow and words, never colour alone. */
export function Change({ value, better = 'up' }: { value: number | null; better?: 'up' | 'down' }) {
  if (value === null) return <span className="text-xs text-slate-500">No earlier figure to compare</span>;
  const good = value === 0 ? null : (value > 0) === (better === 'up');
  const tone = good === null ? 'bg-slate-100 text-slate-700' : good ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800';
  const arrow = value > 0 ? '▲' : value < 0 ? '▼' : '■';

  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums ${tone}`}>
      <span aria-hidden="true">{arrow}</span>
      {value > 0 ? '+' : ''}
      {value.toLocaleString(undefined, { maximumFractionDigits: 1 })}%
      <span className="sr-only">{value > 0 ? 'up' : value < 0 ? 'down' : 'no change'}</span>
    </span>
  );
}

function KpiCard({ label, icon, kpi, format, hint }: { label: string; icon: IconName; kpi: Kpi | undefined; format: (v: number | null) => string; hint: string }) {
  if (!kpi) return null;

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:shadow-md motion-reduce:transform-none">
      <span className="tone-chip absolute end-4 top-4 flex h-10 w-10 items-center justify-center rounded-xl transition-transform duration-300 group-hover:-rotate-6 group-hover:scale-110 motion-reduce:transform-none">
        <Icon name={icon} size={18} />
      </span>
      <p className="pe-12 text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-1.5 text-[1.65rem] font-bold leading-tight tracking-tight text-slate-900 tabular-nums">{format(kpi.current)}</p>
      <p className="mt-2 flex flex-wrap items-center gap-2">
        {kpi.change_percent === null ? (
          <span className="text-xs text-slate-500">{kpi.previous ? `was ${format(kpi.previous)}` : 'Nothing in the earlier period'}</span>
        ) : (
          <>
            <Change value={kpi.change_percent} />
            <span className="text-xs text-slate-500">was {format(kpi.previous)}</span>
          </>
        )}
      </p>
      <p className="mt-2 text-xs text-slate-500">{hint}</p>
    </div>
  );
}

/** "How is this counted?" — the definition next to the figure. */
function HowCounted({ children }: { children: ReactNode }) {
  return (
    <details className="mt-4 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">
      <summary className={`cursor-pointer select-none font-medium text-brand-700 ${FOCUS_RING}`}>How is this counted?</summary>
      <div className="mt-2 space-y-1">{children}</div>
    </details>
  );
}

/** One horizontal bar per row, one hue; the value is written beside it, the bar only shows proportion. */
function Bars({ rows, format, label }: { rows: { label: string; value: number; note?: string }[]; format: (v: number) => string; label: string }) {
  const highest = Math.max(1, ...rows.map((r) => Math.abs(r.value)));

  return (
    <ul className="space-y-3" aria-label={label}>
      {rows.map((row) => (
        <li key={row.label} className="group" title={`${row.label}: ${format(row.value)}${row.note ? ` — ${row.note}` : ''}`}>
          <div className="flex justify-between gap-3 text-sm">
            <span className="text-slate-700">{row.label}</span>
            <span className="font-semibold tabular-nums text-slate-900">{format(row.value)}</span>
          </div>
          <span className="mt-1 block h-2 rounded-full bg-slate-100" aria-hidden="true">
            <span className="block h-2 rounded-full bg-brand-600 transition-[width] duration-700 group-hover:bg-brand-700 motion-reduce:transition-none" style={{ width: `${Math.max(1, Math.round((Math.abs(row.value) / highest) * 100))}%` }} />
          </span>
        </li>
      ))}
    </ul>
  );
}

function SalesCard({ o }: { o: Overview }) {
  const s = o.sales;
  if (!s) return null;
  const c = o.currency;
  const line = (label: string, value: number, sign: '' | '−' | '+', strong = false) => (
    <div className={`flex justify-between gap-3 py-2 ${strong ? 'border-t border-slate-200 font-semibold text-slate-900' : 'text-slate-700'}`}>
      <dt>{label}</dt>
      <dd className="tabular-nums">{sign && value > 0 ? `${sign} ` : ''}{money(value, c)}</dd>
    </div>
  );

  return (
    <Card title="Where the money comes from" icon="cash" description="Sales in this period, after discounts and the money given back.">
      <dl className="text-sm">
        {line('Products sold (gross)', s.gross_minor, '')}
        {line('Discounts', s.discounts_minor, '−')}
        {line('Refunds', s.refunds_minor, '−')}
        {line('Net sales', s.net_minor, '', true)}
        {line('Delivery charged', s.shipping_minor, '+')}
        {line('Tax charged', s.tax_minor, '+')}
        {line('Orders total', s.total_minor, '', true)}
      </dl>
      <HowCounted>
        <p><strong>Net sales</strong> = products sold − discounts − refunds. Orders cancelled do not count.</p>
        <p><strong>Refunds</strong> are those made in the period: payment refunds and returns refunded as store credit, whenever the order was placed.</p>
        <p><strong>Orders total</strong> is what customers were asked to pay, with delivery and tax, before refunds.</p>
        <p>Only orders in {c}; orders from before a currency change are left out, never mixed.</p>
      </HowCounted>
    </Card>
  );
}

function CustomersCard({ o }: { o: Overview }) {
  const c = o.customers;
  const isNew = o.kpis.new_customers?.current ?? 0;
  const returning = o.kpis.returning_customers?.current ?? 0;

  return (
    <Card title="Who buys" icon="users" description="Customers who ordered in this period.">
      <Bars
        label="Customers by kind"
        format={(v) => v.toLocaleString()}
        rows={[
          { label: 'New customers', value: isNew, note: 'first order ever' },
          { label: 'Returning customers', value: returning, note: 'ordered before' },
          { label: 'Guests', value: Math.max(0, (o.kpis.ordering_customers?.current ?? 0) - isNew - returning), note: 'no account, counted by email' },
        ]}
      />
      <dl className="mt-5 grid grid-cols-2 gap-4 text-sm">
        <div><dt className="text-slate-500">Orders with an account</dt><dd className="text-lg font-semibold tabular-nums">{n(c.registered_orders)}</dd></div>
        <div><dt className="text-slate-500">Guest orders</dt><dd className="text-lg font-semibold tabular-nums">{n(c.guest_orders)}</dd></div>
        <div><dt className="text-slate-500">Repeat customers</dt><dd className="text-lg font-semibold tabular-nums">{pct(c.repeat_rate_percent)}</dd></div>
        <div><dt className="text-slate-500">Orders per customer</dt><dd className="text-lg font-semibold tabular-nums">{n(c.orders_per_customer)}</dd></div>
        {o.financial && (
          <div className="col-span-2"><dt className="text-slate-500">Average lifetime spend</dt><dd className="text-lg font-semibold tabular-nums">{c.average_lifetime_value_minor === null ? '—' : money(c.average_lifetime_value_minor, o.currency)}</dd></div>
        )}
      </dl>
      <HowCounted>
        <p><strong>New</strong>: the customer’s first order ever (not cancelled) is in this period. <strong>Returning</strong>: they had ordered before.</p>
        <p><strong>Repeat customers</strong> = customers with an account who ordered in this period and have two or more orders in all ÷ customers with an account who ordered in this period.</p>
        <p><strong>Average lifetime spend</strong> = everything those customers have ordered, ever ÷ their number. Guests are counted by email; they have no lifetime figures.</p>
      </HowCounted>
    </Card>
  );
}

/** Sequential, one hue: darker = more customers came back. The number is always written in the cell. */
function cellTone(value: number | null): string {
  if (value === null) return 'bg-white text-slate-300';
  if (value >= 40) return 'bg-brand-700 text-white';
  if (value >= 25) return 'bg-brand-500 text-white';
  if (value >= 10) return 'bg-brand-200 text-brand-900';
  if (value > 0) return 'bg-brand-50 text-brand-900';
  return 'bg-slate-50 text-slate-500';
}

function CohortCard({ o }: { o: Overview }) {
  const months = o.cohorts[0]?.retention.length ?? 0;

  return (
    <Card title="Do customers come back?" icon="refresh" description="Customers grouped by the month of their first order, and the share who ordered again in each month after.">
      {o.cohorts.every((row) => row.customers === 0) ? (
        <EmptyPanel icon="users" title="No first orders in the last six months" description="This fills in as customers with an account place their first orders." />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[34rem] border-separate border-spacing-[2px] text-center text-sm">
            <caption className="sr-only">Customers who came back, by month of first order</caption>
            <thead className="text-xs text-slate-500">
              <tr>
                <th scope="col" className="px-2 py-1 text-start font-medium">First order</th>
                <th scope="col" className="px-2 py-1 font-medium">Customers</th>
                {Array.from({ length: months }, (_, i) => <th key={i} scope="col" className="px-2 py-1 font-medium">Month {i + 1}</th>)}
              </tr>
            </thead>
            <tbody>
              {o.cohorts.map((row) => (
                <tr key={row.month}>
                  <th scope="row" className="px-2 py-1.5 text-start font-medium text-slate-700">{monthName(row.month)}</th>
                  <td className="px-2 py-1.5 tabular-nums text-slate-700">{row.customers}</td>
                  {row.retention.map((value, i) => (
                    <td key={i} className={`rounded-md px-2 py-1.5 tabular-nums transition hover:ring-2 hover:ring-brand-300 ${cellTone(row.customers === 0 ? null : value)}`} title={value === null ? 'Not yet' : `${monthName(row.month)}: ${value}% ordered again in month ${i + 1}`}>
                      {value === null || row.customers === 0 ? '' : `${value}%`}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <HowCounted>
        <p>Each row: customers with an account whose first order (not cancelled) was in that month, in your store’s time zone ({displayTimezoneName()}).</p>
        <p><strong>Month 1</strong> = the share of them who ordered again in the next calendar month, and so on. Empty cells are months that have not happened yet.</p>
      </HowCounted>
    </Card>
  );
}

function CartsCard({ o }: { o: Overview }) {
  const c = o.carts;

  return (
    <Card title="From cart to order" icon="cart" description="Carts started in this period and what became of them.">
      {c.created === 0 ? (
        <p className="text-sm text-slate-600">No carts were started in this period.</p>
      ) : (
        <>
          <p className="text-3xl font-bold tabular-nums text-slate-900">{pct(c.conversion_percent)}</p>
          <p className="text-sm text-slate-600">of carts became an order</p>
          <div className="mt-4">
            <Bars
              label="Carts"
              format={(v) => v.toLocaleString()}
              rows={[
                { label: 'Carts started', value: c.created },
                { label: 'Became an order', value: c.converted },
                { label: 'Left without ordering', value: c.abandoned, note: 'untouched for a day' },
              ]}
            />
          </div>
        </>
      )}
      <dl className="mt-5 grid grid-cols-2 gap-4 text-sm">
        {o.financial && <div><dt className="text-slate-500">Average cart that ordered</dt><dd className="text-lg font-semibold tabular-nums">{c.average_order_value_minor === null ? '—' : money(c.average_order_value_minor, o.currency)}</dd></div>}
        <div><dt className="text-slate-500">Online payments that failed</dt><dd className="text-lg font-semibold tabular-nums">{c.online_payments === 0 ? '—' : `${pct(c.online_payment_failure_percent)} of ${n(c.online_payments)}`}</dd></div>
      </dl>
      <HowCounted>
        <p><strong>Conversion</strong> = carts started in the period (with at least one product) that became an order ÷ all such carts. Carts merged into a customer’s cart at sign-in are counted once.</p>
        <p><strong>Left without ordering</strong>: not an order, and untouched for 24 hours.</p>
        <p>Product views and checkout visits are not recorded by the shop yet, so no view-to-order rate is shown.</p>
      </HowCounted>
    </Card>
  );
}

function ProductsCard({ o }: { o: Overview }) {
  const { slow_movers: slow, returns } = o.products;

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Card title="Not selling" icon="box" description="Products in stock that sold nothing in this period — the ten holding the most.">
        {slow.length === 0 ? (
          <p className="text-sm text-slate-600">Every product in stock sold at least once. Well done.</p>
        ) : (
          <ul className="divide-y divide-slate-100 text-sm">
            {slow.map((p) => (
              <li key={p.id} className="flex items-center justify-between gap-3 py-2 transition-colors hover:bg-slate-50">
                <span>
                  <Link href={`/products/${p.id}`} className={`rounded font-medium text-brand-700 hover:underline ${FOCUS_RING}`}>{p.name}</Link>
                  <span className="block text-xs text-slate-500">{p.last_sold_at ? `Last sold ${formatDate(p.last_sold_at)}` : 'Never sold'}</span>
                </span>
                <span className="whitespace-nowrap tabular-nums text-slate-700">{n(p.available)} in stock</span>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <Card title="Coming back" icon="undo" description="Products sold in this period and how many of those units were returned.">
        {returns.length === 0 ? (
          <p className="text-sm text-slate-600">Nothing sold in this period has come back.</p>
        ) : (
          <Bars label="Return rate by product" format={(v) => `${v.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`} rows={returns.map((r) => ({ label: `${r.name} (${r.returned} of ${r.sold})`, value: r.return_percent ?? 0 }))} />
        )}
        <HowCounted>
          <p><strong>Return rate</strong> = units accepted back in a return (resalable or damaged) ÷ units sold, for orders placed in this period. Returns rejected or cancelled do not count.</p>
        </HowCounted>
      </Card>
    </div>
  );
}

export default function Advanced() {
  const access = useAccess();
  const included = access.feature('advanced_analytics.enabled') === true;
  const [filters, setFilters] = useUrlState({ date_filter: 'last_30_days', start: '', end: '', compare_with: 'previous_period' });
  const ready = filters.date_filter !== 'custom' || (filters.start !== '' && filters.end !== '');
  const query = { date_filter: filters.date_filter, compare_with: filters.compare_with, ...(filters.date_filter === 'custom' ? { start: filters.start, end: filters.end } : {}) };
  const state = useApi<{ data: Overview }>(included && ready ? '/analytics/overview' : null, query);
  const o = state.data?.data;
  const c = o?.currency ?? access.currency;
  const moneyOf = (v: number | null) => (v === null ? '—' : money(v, c));
  const vs = filters.compare_with === 'previous_year' ? 'the same days last year' : 'the period before';

  return (
    <AdminPage title="Analytics" description="How your store is doing, who buys and comes back, and what needs your attention.">
      {!included ? (
        <PackageNotice title="Advanced analytics are part of the Premium package" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
          Compare periods, see new and returning customers, who comes back month after month, how many carts become orders, and which products sit still or come back.
        </PackageNotice>
      ) : (
        <>
          <FilterBar>
            <div className="w-48"><SelectField label="Period" value={filters.date_filter} onChange={(date_filter) => setFilters({ date_filter })} options={DATE_FILTERS} /></div>
            {filters.date_filter === 'custom' && (
              <>
                <div className="w-44"><TextField label="From" type="date" value={filters.start} onChange={(start) => setFilters({ start })} /></div>
                <div className="w-44"><TextField label="To" type="date" value={filters.end} onChange={(end) => setFilters({ end })} hint="At most 366 days." /></div>
              </>
            )}
            <div className="w-56">
              <SelectField label="Compare with" value={filters.compare_with} onChange={(compare_with) => setFilters({ compare_with })} options={[{ value: 'previous_period', label: 'The period before' }, { value: 'previous_year', label: 'Same days last year' }]} />
            </div>
          </FilterBar>
          {!ready ? (
            <p className="text-sm text-slate-600">Choose the first and the last day.</p>
          ) : state.error ? (
            <ErrorPanel message={state.error} onRetry={state.reload} />
          ) : !o ? (
            <Skeleton lines={10} />
          ) : (
            <div className="space-y-6">
              <p className="text-sm text-slate-600">
                {formatDate(o.period.start)} – {formatDate(o.period.end)}, compared with {formatDate(o.comparison.start)} – {formatDate(o.comparison.end)}.
              </p>
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {o.financial && <KpiCard label="Net sales" icon="cash" kpi={o.kpis.net_sales_minor} format={moneyOf} hint={`Against ${vs}`} />}
                <KpiCard label="Orders" icon="cart" kpi={o.kpis.orders} format={n} hint="Not cancelled" />
                {o.financial && <KpiCard label="Average order" icon="receipt" kpi={o.kpis.average_order_value_minor} format={moneyOf} hint="Orders total ÷ orders" />}
                <KpiCard label="Customers who ordered" icon="users" kpi={o.kpis.ordering_customers} format={n} hint="With an account, and guests by email" />
                <KpiCard label="New customers" icon="sparkles" kpi={o.kpis.new_customers} format={n} hint="Their first order ever" />
                <KpiCard label="Returning customers" icon="refresh" kpi={o.kpis.returning_customers} format={n} hint="Had ordered before" />
              </div>
              <div className="grid gap-6 lg:grid-cols-2 xl:grid-cols-3">
                {o.financial ? <SalesCard o={o} /> : null}
                <CustomersCard o={o} />
                <CartsCard o={o} />
                {!o.financial && (
                  <Card title="Money figures" icon="lock">
                    <p className="text-sm text-slate-600">Sales, average order and lifetime spend need the permission to see financial reports. Ask the store owner.</p>
                  </Card>
                )}
              </div>
              <CohortCard o={o} />
              <ProductsCard o={o} />
            </div>
          )}
        </>
      )}
    </AdminPage>
  );
}
