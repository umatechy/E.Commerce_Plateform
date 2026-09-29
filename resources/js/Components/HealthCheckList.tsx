/**
 * Module 24 — renders a store-health report exactly as the API returned
 * it. Presentation only: every status is computed server-side
 * (StoreHealthService); this component never derives one itself.
 */
export type HealthStatus = 'ok' | 'warning' | 'critical';

export type HealthCheck = {
  key: string;
  status: HealthStatus;
  message: string;
};

const STATUS_LABEL: Record<HealthStatus, string> = {
  ok: 'OK',
  warning: 'Needs attention',
  critical: 'Critical',
};

const STATUS_CLASS: Record<HealthStatus, string> = {
  ok: 'bg-green-100 text-green-800',
  warning: 'bg-amber-100 text-amber-800',
  critical: 'bg-red-100 text-red-800',
};

const CHECK_TITLE: Record<string, string> = {
  setup: 'Store setup',
  subscription: 'Subscription',
  resource_usage: 'Package usage',
  domains: 'Domains',
  inventory: 'Inventory',
  event_delivery: 'Event delivery',
  notifications: 'Notifications',
  payment_webhooks: 'Payment webhooks',
  developer_webhooks: 'Developer webhooks',
  backups: 'Backups',
};

export function StatusBadge({ status }: { status: HealthStatus }) {
  return <span className={`rounded px-2 py-0.5 text-xs font-medium ${STATUS_CLASS[status]}`}>{STATUS_LABEL[status]}</span>;
}

export default function HealthCheckList({ checks }: { checks: HealthCheck[] }) {
  return (
    <ul className="divide-y rounded border bg-white">
      {checks.map((check) => (
        <li key={check.key} className="flex items-start justify-between gap-4 p-3">
          <div>
            <p className="font-medium">{CHECK_TITLE[check.key] ?? check.key}</p>
            <p className="text-sm text-gray-600">{check.message}</p>
          </div>
          <StatusBadge status={check.status} />
        </li>
      ))}
    </ul>
  );
}
