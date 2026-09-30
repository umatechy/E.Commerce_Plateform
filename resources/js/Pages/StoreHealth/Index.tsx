import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LoadingState from '@/Components/LoadingState';
import ErrorState from '@/Components/ErrorState';
import HealthCheckList, { HealthCheck, HealthStatus, StatusBadge } from '@/Components/HealthCheckList';

/**
 * Module 24 "Store Health" — the store's live health report from
 * GET /api/v1/store/health (server-authoritative; see HealthCheckList).
 */
type HealthReport = {
  status: HealthStatus;
  checked_at: string;
  checks: HealthCheck[];
};

export default function Index() {
  const [report, setReport] = useState<HealthReport | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetch('/api/v1/store/health', { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(r)))
      .then((body) => setReport(body.data))
      .catch(() => setError('Could not load store health.'));
  }, []);

  return (
    <AuthenticatedLayout>
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">Store health</h1>
        {report && <StatusBadge status={report.status} />}
      </div>

      {error && <ErrorState message={error} />}
      {!error && report === null && <LoadingState />}

      {report && (
        <div className="mt-4 space-y-2">
          <HealthCheckList checks={report.checks} />
          <p className="text-xs text-gray-500">Checked {new Date(report.checked_at).toLocaleString()}</p>
        </div>
      )}
    </AuthenticatedLayout>
  );
}
