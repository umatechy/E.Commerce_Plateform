import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
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
import { formatDateTime } from '@/lib/datetime';
import { DATE_FILTERS, USAGE_LABELS, labelFor } from '@/lib/labels';
import { navigationFor } from '@/lib/adminNav';

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

type SetupCheck = { key: string; required: boolean; done: boolean; message: string };
type Setup = { launched: boolean; availability: string; checks: SetupCheck[] };

type OrderRow = { id: string; order_number: string; status: string; grand_total_minor: number; currency: string; created_at: string };
type Health = { status: HealthStatus; checked_at: string; checks: HealthCheck[] };
type Usage = Record<string, { limit: number | null; current: number; unlimited: boolean }>;

const SETUP_LINK: Record<string, string> = {
  products: '/products',
  subscription: '/billing',
  warehouse: '/warehouses',
  shipping: '/shipping',
  branding: '/storefront/theme',
  domain: '/domains',
};

function change(percent: number | null | undefined): string | undefined {
  if (percent === null || percent === undefined) return undefined;

  return `${percent > 0 ? '+' : ''}${percent}% against the period before`;
}

function SalesSection({ currency }: { currency: string }) {
  const [range, setRange] = useState('this_month');
  const state = useApi<{ data: Comparison }>('/dashboard', { date_filter: range, compare: 1 });

  return (
    <section aria-labelledby="sales-heading">
      <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
        <h2 id="sales-heading" className="text-sm font-semibold text-slate-900">
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
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {now.revenue_minor !== undefined && <StatCard label="Revenue" value={money(now.revenue_minor, currency)} hint={change(data.revenue_change_percent)} href="/reports" />}
              <StatCard label="Orders" value={now.order_count} hint={change(data.order_count_change_percent)} href="/orders" />
              {now.average_order_value_minor !== undefined && (
                <StatCard label="Average order" value={now.average_order_value_minor === null ? null : money(now.average_order_value_minor, currency)} />
              )}
              <StatCard label="New customers" value={now.new_customers} href="/customers" />
              <StatCard label="Waiting for confirmation" value={now.pending_orders} href="/orders?status=pending_confirmation" />
              <StatCard label="Completed orders" value={now.completed_orders} />
              {now.collected_amount_minor !== undefined && <StatCard label="Payments collected" value={money(now.collected_amount_minor, currency)} href="/payments" />}
              <StatCard label="Low on stock" value={now.low_stock_products} hint="Stock records at or under their reorder point, now." href="/inventory?stock=low" />
            </div>
          );
        }}
      </QueryState>
      <p className="mt-2 text-xs text-slate-500">Amounts are shown in your store currency ({currency}). Days follow your store's timezone.</p>
    </section>
  );
}

function SetupSection() {
  const state = useApi<{ data: Setup }>('/storefront/setup');
  const { busy, run } = useAction();

  if (state.error) return state.errorStatus === 403 ? null : <ErrorPanel message={state.error} onRetry={state.reload} />;
  if (state.data === null) return <Skeleton lines={3} />;

  const setup = state.data.data;
  const open = setup.checks.filter((check) => !check.done);
  if (setup.launched && open.length === 0) return null;

  const blocked = setup.checks.some((check) => check.required && !check.done);

  return (
    <Card
      title={setup.launched ? 'Finish setting up your store' : 'Your store is not open to customers yet'}
      description={setup.launched ? 'These optional steps are still open.' : 'Complete the required steps, then launch.'}
      actions={
        !setup.launched && (
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
        )
      }
    >
      <ul className="space-y-2 text-sm">
        {setup.checks.map((check) => (
          <li key={check.key} className="flex flex-wrap items-center justify-between gap-2">
            <span>
              <span className={check.done ? 'text-green-700' : 'text-slate-500'} aria-hidden="true">
                {check.done ? '✓' : '○'}
              </span>{' '}
              <span className="sr-only">{check.done ? 'Done: ' : 'To do: '}</span>
              {check.message} {check.required ? <span className="text-xs text-slate-500">(required)</span> : <span className="text-xs text-slate-500">(optional)</span>}
            </span>
            {!check.done && SETUP_LINK[check.key] && (
              <Link href={SETUP_LINK[check.key]} className={`rounded text-indigo-700 hover:underline ${FOCUS_RING}`}>
                Open
              </Link>
            )}
          </li>
        ))}
      </ul>
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
        <ul className="divide-y divide-slate-100 text-sm">
          {state.rows.slice(0, 6).map((order) => (
            <li key={order.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <Link href={`/orders/${order.id}`} className={`rounded font-mono text-indigo-700 hover:underline ${FOCUS_RING}`}>
                {order.order_number}
              </Link>
              <span className="text-xs text-slate-500">{formatDateTime(order.created_at)}</span>
              <StatusBadge status={order.status} />
              <span className="font-medium">{money(order.grand_total_minor, order.currency)}</span>
            </li>
          ))}
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

export default function Dashboard() {
  const auth = useAuth();
  const access = useAccess();
  const quick = navigationFor(access)
    .filter((group) => !group.platform)
    .flatMap((group) => group.items)
    .filter((item) => item.state === 'open' && ['/products', '/orders', '/inventory', '/promotions', '/storefront/theme', '/settings'].includes(item.href));

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
      <PageHeader
        title={auth.activeStore?.name ?? 'Dashboard'}
        description={auth.user ? `Signed in as ${auth.user.name}.` : undefined}
        actions={access.can('products.create') && <ButtonLink href="/products/new" variant="primary">Add product</ButtonLink>}
      />

      <div className="space-y-6">
        {access.can('storefront.manage') && <SetupSection />}
        {access.can('analytics.view') && <SalesSection currency={access.currency} />}

        <div className="grid gap-4 lg:grid-cols-2">
          {access.can('orders.view') && <RecentOrders />}
          <div className="space-y-4">
            {access.can('store_health.view') && <HealthSection />}
            <UsageSection packageName={access.packageName} />
          </div>
        </div>

        {quick.length > 0 && (
          <section aria-labelledby="quick-heading">
            <h2 id="quick-heading" className="mb-3 text-sm font-semibold text-slate-900">
              Go to
            </h2>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {quick.map((item) => (
                <Link key={item.href} href={item.href} className={`rounded-lg border border-slate-200 bg-white p-4 hover:border-slate-400 ${FOCUS_RING}`}>
                  <span className="font-medium text-slate-900">{item.label}</span>
                  <span className="mt-1 block text-sm text-slate-600">{item.description}</span>
                </Link>
              ))}
            </div>
          </section>
        )}
      </div>
    </AuthenticatedLayout>
  );
}
