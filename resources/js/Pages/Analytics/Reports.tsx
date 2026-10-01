import { useEffect, useRef, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import { SelectField, TextField } from '@/Components/ui/Form';
import { FilterBar } from '@/Components/ui/Filters';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { QueryState, StatCard, Tabs } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { readPage, useApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { adminErrorMessage, adminFetch, idempotencyKey } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { displayTimezoneName, formatDateTime } from '@/lib/datetime';
import { DATE_FILTERS } from '@/lib/labels';
import { CARRIER_LABELS, PAYMENT_METHOD_LABELS } from '@/lib/orders';

/**
 * Module 22 "Reports & Analytics" (/api/v1/reports/*, /exports).
 *
 * Every number is calculated by the server for the chosen period, with
 * days cut in the store's timezone (Phase B28). This page only shows the
 * answers: it adds nothing up and filters nothing itself. The financial
 * reports (sales, payments, promotions) need the financial permission;
 * without it their tabs are not offered and the API refuses them.
 */
type ReportId = 'sales' | 'products' | 'customers' | 'payments' | 'shipping' | 'promotions' | 'marketing' | 'notifications' | 'inventory';

const REPORTS: { id: ReportId; label: string; financial?: boolean }[] = [
  { id: 'sales', label: 'Sales', financial: true },
  { id: 'products', label: 'Products' },
  { id: 'customers', label: 'Customers' },
  { id: 'payments', label: 'Payments', financial: true },
  { id: 'shipping', label: 'Shipping' },
  { id: 'promotions', label: 'Promotions', financial: true },
  { id: 'marketing', label: 'Campaigns' },
  { id: 'notifications', label: 'Messages' },
  { id: 'inventory', label: 'Inventory' },
];

type Range = { date_filter: string; start: string; end: string };
type Row = Record<string, string | number | null>;

function rangeQuery(range: Range): Record<string, string | undefined> {
  return range.date_filter === 'custom' ? { date_filter: 'custom', start: range.start || undefined, end: range.end || undefined } : { date_filter: range.date_filter };
}

/** A custom range needs both days before it is asked for. */
function rangeReady(range: Range): boolean {
  return range.date_filter !== 'custom' || (range.start !== '' && range.end !== '');
}

function SalesReport({ range, currency }: { range: Range; currency: string }) {
  const state = useApi<{ data: { date: string; order_count: number; revenue_minor: number; discounts_minor: number }[] }>(rangeReady(range) ? '/reports/sales' : null, rangeQuery(range));

  return (
    <QueryState state={state}>
      {({ data }) => {
        const highest = Math.max(1, ...data.map((day) => day.revenue_minor));
        const columns: Column<(typeof data)[number]>[] = [
          { key: 'date', header: 'Day', render: (day) => day.date },
          { key: 'orders', header: 'Orders', align: 'right', priority: true, render: (day) => day.order_count },
          { key: 'discounts', header: 'Discounts', align: 'right', render: (day) => money(day.discounts_minor, currency) },
          { key: 'revenue', header: 'Revenue', align: 'right', priority: true, render: (day) => <span className="font-medium">{money(day.revenue_minor, currency)}</span> },
          {
            key: 'bar',
            header: 'Share of the best day',
            render: (day) => (
              <span className="block h-2 w-40 rounded bg-slate-100" aria-hidden="true">
                <span className="block h-2 rounded bg-indigo-600" style={{ width: `${Math.round((day.revenue_minor / highest) * 100)}%` }} />
              </span>
            ),
          },
        ];

        return <DataTable caption="Sales per day" columns={columns} rows={data} rowKey={(day) => day.date} empty={<p className="text-sm text-slate-600">No orders in this period.</p>} />;
      }}
    </QueryState>
  );
}

function ProductsReport({ range, currency }: { range: Range; currency: string }) {
  const [sort, setSort] = useState('revenue');
  const [page, setPage] = useState(1);
  const state = useApi<unknown>(rangeReady(range) ? '/reports/products' : null, { ...rangeQuery(range), sort_by: sort, page });
  const paged = state.data === null ? null : readPage<{ product_id: number | null; product_name_snapshot: string; quantity_sold: number | string; revenue_minor: number | string }>(state.data);
  const columns: Column<NonNullable<typeof paged>['rows'][number]>[] = [
    { key: 'name', header: 'Product', render: (row) => <span className="font-medium">{row.product_name_snapshot}</span> },
    { key: 'quantity', header: 'Sold', align: 'right', priority: true, render: (row) => Number(row.quantity_sold) },
    { key: 'revenue', header: 'Revenue', align: 'right', priority: true, render: (row) => money(Number(row.revenue_minor), currency) },
  ];

  return (
    <>
      <div className="mb-3 w-48">
        <SelectField label="Order by" value={sort} onChange={(value) => { setSort(value); setPage(1); }} options={[{ value: 'revenue', label: 'Revenue' }, { value: 'quantity', label: 'Quantity sold' }]} />
      </div>
      <DataTable caption="Products sold" columns={columns} rows={paged?.rows ?? null} rowKey={(row) => `${row.product_id}-${row.product_name_snapshot}`} loading={state.loading} error={state.error} onRetry={state.reload} empty={<p className="text-sm text-slate-600">Nothing was sold in this period.</p>} />
      <Pagination meta={paged?.meta ?? null} disabled={state.loading} onPage={setPage} />
    </>
  );
}

function CustomersReport({ range }: { range: Range }) {
  const state = useApi<{ data: { new_customers: number; customers_with_orders: number; repeat_customers: number; repeat_customer_rate_percent: number | null } }>(rangeReady(range) ? '/reports/customers' : null, rangeQuery(range));

  return (
    <QueryState state={state}>
      {({ data }) => (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard label="New customers" value={data.new_customers} />
          <StatCard label="Customers who ordered" value={data.customers_with_orders} />
          <StatCard label="Ordered more than once" value={data.repeat_customers} />
          <StatCard label="Repeat rate" value={data.repeat_customer_rate_percent === null ? null : `${data.repeat_customer_rate_percent}%`} />
        </div>
      )}
    </QueryState>
  );
}

function InventoryReport() {
  const state = useApi<{ data: { total_on_hand: number; total_reserved: number; low_stock_count: number; out_of_stock_count: number } }>('/reports/inventory');

  return (
    <QueryState state={state}>
      {({ data }) => (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Units on hand" value={data.total_on_hand} />
            <StatCard label="Units reserved" value={data.total_reserved} />
            <StatCard label="Low on stock" value={data.low_stock_count} href="/inventory?stock=low" />
            <StatCard label="Out of stock" value={data.out_of_stock_count} href="/inventory?stock=out" />
          </div>
          <p className="mt-2 text-xs text-slate-600">The stock as it is now. This report has no period and no stock value: the platform keeps no inventory costing.</p>
        </>
      )}
    </QueryState>
  );
}

/** The reports that are a plain list of grouped counts. */
function GroupedReport({ id, range, currency }: { id: 'payments' | 'shipping' | 'promotions' | 'marketing' | 'notifications'; range: Range; currency: string }) {
  const state = useApi<{ data: Row[] }>(rangeReady(range) ? `/reports/${id}` : null, rangeQuery(range));
  const text = (value: string | number | null, labels?: Record<string, string>) => (value === null ? '—' : (labels?.[String(value)] ?? humanize(String(value))));

  const columns: Record<typeof id, Column<Row>[]> = {
    payments: [
      { key: 'method', header: 'Method', render: (row) => text(row.method, PAYMENT_METHOD_LABELS) },
      { key: 'status', header: 'Status', priority: true, render: (row) => <StatusBadge status={String(row.status)} /> },
      { key: 'count', header: 'Payments', align: 'right', render: (row) => row.count },
      { key: 'amount', header: 'Amount', align: 'right', priority: true, render: (row) => money(Number(row.amount_minor), currency) },
    ],
    shipping: [
      { key: 'carrier', header: 'Carrier', render: (row) => text(row.carrier, CARRIER_LABELS) },
      { key: 'status', header: 'Status', priority: true, render: (row) => <StatusBadge status={String(row.status)} /> },
      { key: 'count', header: 'Shipments', align: 'right', priority: true, render: (row) => row.count },
    ],
    promotions: [
      { key: 'name', header: 'Promotion', render: (row) => <span className="font-medium">{row.name}</span> },
      { key: 'count', header: 'Times used', align: 'right', priority: true, render: (row) => row.usage_count },
      { key: 'discount', header: 'Discount given', align: 'right', priority: true, render: (row) => money(Number(row.total_discount_minor), currency) },
    ],
    marketing: [
      { key: 'name', header: 'Campaign', render: (row) => <span className="font-medium">{row.name}</span> },
      { key: 'status', header: 'Recipient status', priority: true, render: (row) => <StatusBadge status={String(row.status)} /> },
      { key: 'count', header: 'Recipients', align: 'right', priority: true, render: (row) => row.count },
    ],
    notifications: [
      { key: 'channel', header: 'Channel', render: (row) => text(row.channel) },
      { key: 'status', header: 'Status', priority: true, render: (row) => <StatusBadge status={String(row.status)} /> },
      { key: 'count', header: 'Messages', align: 'right', priority: true, render: (row) => row.count },
    ],
  };

  return (
    <DataTable
      caption={`${humanize(id)} report`}
      columns={columns[id]}
      rows={state.data?.data ?? null}
      rowKey={(row) => Object.values(row).join('|')}
      loading={state.loading}
      error={state.error}
      onRetry={state.reload}
      empty={<p className="text-sm text-slate-600">Nothing to report for this period.</p>}
    />
  );
}

type Export = { id: string; status: string; row_count: number | null; failure_reason: string | null; expires_at: string | null; download_url: string | null };

/** Asks the server to prepare a CSV, then looks a few times until it is ready. */
function ExportButton({ report, range }: { report: ReportId; range: Range }) {
  const [state, setState] = useState<{ phase: 'idle' | 'working' | 'slow' | 'error'; message?: string; file?: Export }>({ phase: 'idle' });
  const timer = useRef<number | null>(null);

  useEffect(() => () => { if (timer.current !== null) window.clearTimeout(timer.current); }, []);
  // A different report or period: the prepared file no longer matches.
  useEffect(() => setState({ phase: 'idle' }), [report, range.date_filter, range.start, range.end]);

  function settle(file: Export, attempt: number) {
    if (file.status === 'completed' && file.download_url) return setState({ phase: 'idle', file });
    if (file.status === 'failed' || file.status === 'expired') return setState({ phase: 'error', message: 'The export could not be prepared. Try again.' });
    if (attempt >= 10) return setState({ phase: 'slow', file });
    timer.current = window.setTimeout(() => {
      adminFetch<{ data: Export }>(`/exports/${file.id}`)
        .then((body) => settle(body.data, attempt + 1))
        .catch((e) => setState({ phase: 'error', message: adminErrorMessage(e) }));
    }, 2000);
  }

  function start() {
    setState({ phase: 'working' });
    adminFetch<{ data: Export }>('/exports', { method: 'POST', body: { report_type: report, ...rangeQuery(range), idempotency_key: idempotencyKey() } })
      .then((body) => settle(body.data, 0))
      .catch((e) => setState({ phase: 'error', message: adminErrorMessage(e) }));
  }

  return (
    <div className="flex flex-wrap items-center gap-3 text-sm" aria-live="polite">
      <Button onClick={start} busy={state.phase === 'working'} busyLabel="Preparing…" disabled={!rangeReady(range)}>Export as CSV</Button>
      {state.phase === 'slow' && state.file && (
        <>
          <span className="text-slate-700">Still being prepared.</span>
          <Button size="sm" onClick={() => { setState({ phase: 'working' }); settle(state.file as Export, 0); }}>Check again</Button>
        </>
      )}
      {state.phase === 'error' && <span role="alert" className="text-red-700">{state.message}</span>}
      {state.phase === 'idle' && state.file?.download_url && (
        <a href={state.file.download_url} className="font-medium text-indigo-700 underline">
          Download ({state.file.row_count ?? 0} rows{state.file.expires_at ? `, link valid until ${formatDateTime(state.file.expires_at)}` : ''})
        </a>
      )}
    </div>
  );
}

export default function Reports() {
  const access = useAccess();
  const available = REPORTS.filter((report) => !report.financial || access.can('analytics.financial'));
  const [filters, setFilters] = useUrlState({ report: (available[0]?.id ?? 'products') as string, date_filter: 'this_month', start: '', end: '' });
  const report = (available.find((item) => item.id === filters.report)?.id ?? available[0]?.id ?? 'products') as ReportId;
  const range: Range = { date_filter: filters.date_filter, start: filters.start, end: filters.end };

  return (
    <AdminPage title="Reports" description={`Figures for your store. Days follow your store's timezone (${displayTimezoneName()}).`}>
      <Tabs label="Reports" tabs={available} active={report} onChange={(id) => setFilters({ report: id })} />

      {report !== 'inventory' && (
        <FilterBar>
          <div className="w-48">
            <SelectField label="Period" value={filters.date_filter} onChange={(date_filter) => setFilters({ date_filter })} options={DATE_FILTERS} />
          </div>
          {filters.date_filter === 'custom' && (
            <>
              <div className="w-44"><TextField label="From" type="date" value={filters.start} onChange={(start) => setFilters({ start })} /></div>
              <div className="w-44"><TextField label="To" type="date" value={filters.end} onChange={(end) => setFilters({ end })} hint="At most 366 days." /></div>
            </>
          )}
          {access.can('analytics.export') && <ExportButton report={report} range={range} />}
        </FilterBar>
      )}

      <div role="tabpanel">
        {!rangeReady(range) && report !== 'inventory' ? (
          <p className="text-sm text-slate-600">Choose the first and the last day.</p>
        ) : report === 'sales' ? (
          <SalesReport range={range} currency={access.currency} />
        ) : report === 'products' ? (
          <ProductsReport range={range} currency={access.currency} />
        ) : report === 'customers' ? (
          <CustomersReport range={range} />
        ) : report === 'inventory' ? (
          <InventoryReport />
        ) : (
          <GroupedReport id={report} range={range} currency={access.currency} />
        )}
      </div>
      {report !== 'inventory' && report !== 'customers' && <p className="mt-3 text-xs text-slate-600">Amounts are shown in your store currency ({access.currency}).</p>}
    </AdminPage>
  );
}
