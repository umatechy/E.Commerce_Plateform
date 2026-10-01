import { useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import { Card, QueryState } from '@/Components/ui/Page';
import HealthCheckList, { type HealthCheck, type HealthStatus, StatusBadge } from '@/Components/HealthCheckList';
import { usePagedApi, useApi } from '@/lib/useApi';
import { formatDateTime } from '@/lib/datetime';

/**
 * Module 24 "Store Health": the store's live health report
 * (GET /api/v1/store/health) and the recorded history
 * (/store/health/history). Every status is the server's
 * (StoreHealthService); this page computes none and runs no checks of
 * its own.
 */
type HealthReport = { status: HealthStatus; checked_at: string; checks: HealthCheck[] };
type Snapshot = { status: HealthStatus; checked_at: string; checks: HealthCheck[] };

export default function Index() {
  const report = useApi<{ data: HealthReport }>('/store/health');
  const [page, setPage] = useState(1);
  const history = usePagedApi<Snapshot>('/store/health/history', { page });

  const columns: Column<Snapshot>[] = [
    { key: 'when', header: 'Checked', render: (snapshot) => formatDateTime(snapshot.checked_at) },
    { key: 'status', header: 'Result', priority: true, render: (snapshot) => <StatusBadge status={snapshot.status} /> },
    {
      key: 'issues',
      header: 'Needed attention',
      render: (snapshot) => {
        const issues = (snapshot.checks ?? []).filter((check) => check.status !== 'ok');

        return issues.length === 0 ? 'Nothing' : issues.map((check) => check.message).join(' · ');
      },
    },
  ];

  return (
    <AdminPage title="Store health" description="Checks on your store's setup, subscription, usage, domains and backups." actions={<Button onClick={report.reload} busy={report.loading && report.data !== null} busyLabel="Checking…">Check again</Button>}>
      <QueryState state={report} lines={5}>
        {({ data }) => (
          <div className="space-y-2">
            <p className="flex flex-wrap items-center gap-2 text-sm text-slate-700">
              <StatusBadge status={data.status} />
              <span>Checked {formatDateTime(data.checked_at)}</span>
            </p>
            <HealthCheckList checks={data.checks} />
          </div>
        )}
      </QueryState>

      <div className="mt-6">
        <Card title="History" description="Earlier results, newest first.">
          <DataTable caption="Store health history" columns={columns} rows={history.rows} rowKey={(snapshot) => snapshot.checked_at} loading={history.loading} error={history.error} onRetry={history.reload} empty={<p className="text-sm text-slate-600">No results have been recorded yet.</p>} />
          <Pagination meta={history.meta} disabled={history.loading} onPage={setPage} />
        </Card>
      </div>
    </AdminPage>
  );
}
