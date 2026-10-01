import AdminPage from '@/Components/AdminPage';
import { ButtonLink } from '@/Components/ui/Button';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { Card, QueryState, StatCard } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { fromMinor } from '@/lib/money';
import { recoveryPointText, type BackupSummary } from '@/lib/backups';

/**
 * Module 30 §7 "Super Admin Dashboard": figures across all stores
 * (/api/v1/super-admin/dashboard), infrastructure checks and the backup
 * state. Platform staff only; the API checks the account, its two-step
 * sign-in and, for changes, the password again.
 *
 * Order and payment totals add up every store's amounts as recorded,
 * each in its own currency. They are not converted, so they are shown as
 * plain numbers, not as money in one currency.
 */
type Summary = {
  stores: { total: number; active_subscriptions: number; trial_subscriptions: number; suspended_subscriptions: number };
  orders: { total_last_30_days: number; revenue_minor_last_30_days: number };
  payments: { collected_amount_minor_last_30_days: number; failed_last_30_days: number };
};

type Infrastructure = { status: string; checks: Record<string, { status: string; detail?: string }> };

export default function Dashboard() {
  const summary = useApi<{ data: Summary }>('/super-admin/dashboard');
  const infrastructure = useApi<{ data: Infrastructure }>('/super-admin/infrastructure/health');
  const backups = useApi<{ data: BackupSummary }>('/super-admin/backups/summary');

  return (
    <AdminPage title="Platform overview" description="Umar Techy staff only. These figures cover every store on the platform.">
      <div className="space-y-6">
        <QueryState state={summary} lines={4}>
          {({ data }) => (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <StatCard label="Stores" value={data.stores.total} href="/super-admin/stores" />
              <StatCard label="Active subscriptions" value={data.stores.active_subscriptions} />
              <StatCard label="On trial" value={data.stores.trial_subscriptions} />
              <StatCard label="Suspended" value={data.stores.suspended_subscriptions} />
              <StatCard label="Orders, last 30 days" value={data.orders.total_last_30_days} />
              <StatCard label="Order totals, last 30 days" value={fromMinor(data.orders.revenue_minor_last_30_days, 'USD')} hint="Sum of every store's totals, each in its own currency. Not converted." />
              <StatCard label="Payments collected, last 30 days" value={fromMinor(data.payments.collected_amount_minor_last_30_days, 'USD')} hint="Same: not converted between currencies." />
              <StatCard label="Failed payments, last 30 days" value={data.payments.failed_last_30_days} href="/super-admin/monitoring" />
            </div>
          )}
        </QueryState>

        <div className="grid gap-4 lg:grid-cols-2">
          <Card title="Infrastructure" actions={<ButtonLink href="/super-admin/monitoring" size="sm">Monitoring</ButtonLink>}>
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

          <Card title="Backups" actions={<ButtonLink href="/super-admin/backups" size="sm">Backups</ButtonLink>}>
            <QueryState state={backups} lines={3}>
              {({ data }) => (
                <div className="space-y-2 text-sm text-slate-700">
                  <p>
                    <StatusBadge status={data.overdue ? 'overdue' : 'ok'} label={data.overdue ? 'Overdue' : 'Within target'} /> {recoveryPointText(data)}
                  </p>
                  <p>Scheduled failures in a row: {data.consecutive_scheduled_failures}. Failed backups in 30 days: {data.failed_backups_last_30_days}.</p>
                  <p>Last restore rehearsal: {data.last_rehearsal_status ? humanize(data.last_rehearsal_status) : 'never run'}.</p>
                </div>
              )}
            </QueryState>
          </Card>
        </div>
      </div>
    </AdminPage>
  );
}
