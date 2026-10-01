import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import { SelectField } from '@/Components/ui/Form';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { Card, QueryState, StatCard, Tabs } from '@/Components/ui/Page';
import { StatusBadge as HealthBadge, type HealthCheck, type HealthStatus } from '@/Components/HealthCheckList';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { fromMinor } from '@/lib/money';
import { dateTimeOrDash, formatDateTime } from '@/lib/datetime';

/**
 * Module 24 §58 "Platform Health Dashboard" and Module 30 monitoring:
 * infrastructure checks, the event queue, store health across the
 * platform, API usage, and recent payment and message failures
 * (/api/v1/super-admin/infrastructure/health, /monitoring/*,
 * /store-health, /payments/failures, /notifications/failures).
 *
 * The server runs every check. This page only shows the results; it has
 * no monitoring logic of its own. Failure lists show what failed and
 * when, not message texts or customer details.
 */
type Infrastructure = { status: string; checks: Record<string, { status: string; detail?: string }> };
type Outbox = { status: HealthStatus; by_status: Record<string, number>; oldest_pending_minutes: number | null; failed_recently: number; failed_jobs: number };
type ApiUsage = { days: number; versions: { version: string; requests: number; stores: number; api_keys: number; server_errors: number; last_seen_at: string | null }[] };
type Snapshot = { store?: { id: string; name: string; slug: string }; status: HealthStatus; checked_at: string; checks: HealthCheck[] };
type PaymentFailure = { id: number; store_id: number; type: string; amount_minor: number; currency: string; failure_code: string | null; created_at: string };
type MessageFailure = { id: number; store_id: number | null; channel: string; message_type: string; created_at: string };

function System() {
  const infrastructure = useApi<{ data: Infrastructure }>('/super-admin/infrastructure/health');
  const outbox = useApi<{ data: Outbox }>('/super-admin/monitoring/outbox');
  const usage = useApi<{ data: ApiUsage }>('/super-admin/monitoring/api-usage');

  const usageColumns: Column<ApiUsage['versions'][number]>[] = [
    { key: 'version', header: 'API version', render: (row) => <span className="font-mono">{row.version}</span> },
    { key: 'requests', header: 'Requests', align: 'right', priority: true, render: (row) => row.requests },
    { key: 'stores', header: 'Stores', align: 'right', render: (row) => row.stores },
    { key: 'keys', header: 'Keys', align: 'right', render: (row) => row.api_keys },
    { key: 'errors', header: 'Server errors', align: 'right', priority: true, render: (row) => row.server_errors },
    { key: 'seen', header: 'Last request', render: (row) => dateTimeOrDash(row.last_seen_at) },
  ];

  return (
    <div className="space-y-4">
      <Card title="Infrastructure" actions={<Button size="sm" onClick={infrastructure.reload}>Check again</Button>}>
        <QueryState state={infrastructure} lines={3}>
          {({ data }) => (
            <ul className="divide-y divide-slate-100 text-sm">
              {Object.entries(data.checks).map(([name, check]) => (
                <li key={name} className="flex items-center justify-between py-2">
                  <span>{humanize(name)}</span>
                  <StatusBadge status={check.status} label={check.status === 'ok' ? 'OK' : check.detail ? `Failed (${check.detail})` : 'Failed'} />
                </li>
              ))}
            </ul>
          )}
        </QueryState>
      </Card>

      <Card title="Event queue" description="Events waiting to be delivered to notifications, webhooks and other parts of the platform.">
        <QueryState state={outbox} lines={3}>
          {({ data }) => (
            <>
              <p className="mb-3"><HealthBadge status={data.status} /></p>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {Object.entries(data.by_status).map(([status, total]) => (
                  <StatCard key={status} label={humanize(status)} value={total} />
                ))}
                <StatCard label="Oldest waiting" value={data.oldest_pending_minutes === null ? 'None waiting' : `${data.oldest_pending_minutes} min`} />
                <StatCard label="Failed recently" value={data.failed_recently} />
                <StatCard label="Failed jobs" value={data.failed_jobs} />
              </div>
            </>
          )}
        </QueryState>
      </Card>

      <Card title="Developer API usage" description={usage.data ? `The last ${usage.data.data.days} days.` : undefined}>
        <DataTable caption="Developer API usage by version" columns={usageColumns} rows={usage.data?.data.versions ?? null} rowKey={(row) => row.version} loading={usage.loading} error={usage.error} onRetry={usage.reload} empty={<p className="text-sm text-slate-600">No Developer API requests in this period.</p>} />
      </Card>
    </div>
  );
}

function StoreHealth() {
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const list = usePagedApi<Snapshot>('/super-admin/store-health', { status, page });
  const columns: Column<Snapshot>[] = [
    { key: 'store', header: 'Store', render: (snapshot) => <span className="font-medium">{snapshot.store?.name ?? '—'}</span> },
    { key: 'status', header: 'Health', priority: true, render: (snapshot) => <HealthBadge status={snapshot.status} /> },
    { key: 'issues', header: 'Needs attention', render: (snapshot) => (snapshot.checks ?? []).filter((check) => check.status !== 'ok').map((check) => check.message).join(' · ') || 'Nothing' },
    { key: 'when', header: 'Checked', render: (snapshot) => formatDateTime(snapshot.checked_at) },
  ];

  return (
    <>
      <div className="mb-3 w-48">
        <SelectField label="Show" value={status} onChange={(value) => { setStatus(value); setPage(1); }} placeholder="All stores" options={[{ value: 'critical', label: 'Critical' }, { value: 'warning', label: 'Needs attention' }, { value: 'ok', label: 'OK' }]} />
      </div>
      <DataTable caption="Latest health result of each store" columns={columns} rows={list.rows} rowKey={(snapshot) => `${snapshot.store?.id}-${snapshot.checked_at}`} loading={list.loading} error={list.error} onRetry={list.reload} empty={<p className="text-sm text-slate-600">No health results recorded{status ? ' with this status' : ''}.</p>} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />
    </>
  );
}

function Failures() {
  const [paymentPage, setPaymentPage] = useState(1);
  const [messagePage, setMessagePage] = useState(1);
  const payments = usePagedApi<PaymentFailure>('/super-admin/payments/failures', { page: paymentPage });
  const messages = usePagedApi<MessageFailure>('/super-admin/notifications/failures', { page: messagePage });
  const storeLink = (id: number | null) =>
    id === null ? 'Platform' : (
      <Link href={`/super-admin/stores/${id}`} className={`rounded text-indigo-700 hover:underline ${FOCUS_RING}`}>
        Store #{id}
      </Link>
    );

  const paymentColumns: Column<PaymentFailure>[] = [
    { key: 'when', header: 'When', render: (row) => formatDateTime(row.created_at) },
    { key: 'store', header: 'Store', priority: true, render: (row) => storeLink(row.store_id) },
    { key: 'type', header: 'Type', render: (row) => humanize(row.type) },
    { key: 'amount', header: 'Amount', align: 'right', render: (row) => `${fromMinor(row.amount_minor, row.currency)} ${row.currency}` },
    { key: 'code', header: 'Reason code', priority: true, render: (row) => row.failure_code ?? '—' },
  ];
  const messageColumns: Column<MessageFailure>[] = [
    { key: 'when', header: 'When', render: (row) => formatDateTime(row.created_at) },
    { key: 'store', header: 'Store', priority: true, render: (row) => storeLink(row.store_id) },
    { key: 'channel', header: 'Channel', priority: true, render: (row) => humanize(row.channel) },
    { key: 'type', header: 'Kind', render: (row) => humanize(row.message_type) },
  ];

  return (
    <div className="space-y-4">
      <Card title="Failed payment transactions" description="The last 7 days, across all stores.">
        <DataTable caption="Failed payment transactions" columns={paymentColumns} rows={payments.rows} rowKey={(row) => row.id} loading={payments.loading} error={payments.error} onRetry={payments.reload} empty={<p className="text-sm text-slate-600">No failed payments in the last 7 days.</p>} />
        <Pagination meta={payments.meta} disabled={payments.loading} onPage={setPaymentPage} />
      </Card>
      <Card title="Failed messages" description="The last 7 days, across all stores. Message texts are not shown here.">
        <DataTable caption="Failed messages" columns={messageColumns} rows={messages.rows} rowKey={(row) => row.id} loading={messages.loading} error={messages.error} onRetry={messages.reload} empty={<p className="text-sm text-slate-600">No failed messages in the last 7 days.</p>} />
        <Pagination meta={messages.meta} disabled={messages.loading} onPage={setMessagePage} />
      </Card>
    </div>
  );
}

export default function Monitoring() {
  const [state, setState] = useUrlState({ tab: 'system' });
  const tab = ['system', 'stores', 'failures'].includes(state.tab) ? state.tab : 'system';

  return (
    <AdminPage title="Monitoring" description="The state of the platform, as the server's own checks report it.">
      <Tabs label="Monitoring sections" tabs={[{ id: 'system', label: 'System' }, { id: 'stores', label: 'Store health' }, { id: 'failures', label: 'Failures' }]} active={tab} onChange={(next) => setState({ tab: next })} />
      <div role="tabpanel">{tab === 'system' ? <System /> : tab === 'stores' ? <StoreHealth /> : <Failures />}</div>
    </AdminPage>
  );
}
