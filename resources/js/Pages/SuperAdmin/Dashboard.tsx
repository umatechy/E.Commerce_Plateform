import AdminPage from '@/Components/AdminPage';
import { ButtonLink } from '@/Components/ui/Button';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { Card, QueryState, StatCard } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { fromMinor } from '@/lib/money';
import Icon from '@/Components/ui/Icon';
import { Link } from '@inertiajs/react';
import { FOCUS_RING } from '@/Components/ui/Button';
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

/** A sum of amounts in several currencies: grouped digits, two decimals, no currency sign (it would be wrong). */
function plainAmount(minor: number): string {
  return Number(fromMinor(minor, 'USD')).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

type Attention = { text: string; href: string };

/** UI-5: what needs someone now, from the same three answers the page shows below. */
function attentionItems(summary?: Summary, infrastructure?: Infrastructure, backups?: BackupSummary): Attention[] {
  const items: Attention[] = [];
  if (backups?.overdue) items.push({ text: 'The newest verified backup is older than the target.', href: '/super-admin/backups' });
  if (backups && backups.consecutive_scheduled_failures > 0) items.push({ text: `${backups.consecutive_scheduled_failures} scheduled backup${backups.consecutive_scheduled_failures === 1 ? '' : 's'} failed in a row.`, href: '/super-admin/backups' });
  const failing = Object.entries(infrastructure?.checks ?? {}).filter(([, check]) => check.status !== 'ok').map(([name]) => humanize(name));
  if (failing.length > 0) items.push({ text: `Infrastructure check failing: ${failing.join(', ')}.`, href: '/super-admin/monitoring' });
  if (summary && summary.payments.failed_last_30_days > 0) items.push({ text: `${summary.payments.failed_last_30_days} failed payment${summary.payments.failed_last_30_days === 1 ? '' : 's'} in the last 30 days.`, href: '/super-admin/monitoring' });

  return items;
}

export default function Dashboard() {
  const summary = useApi<{ data: Summary }>('/super-admin/dashboard');
  const infrastructure = useApi<{ data: Infrastructure }>('/super-admin/infrastructure/health');
  const backups = useApi<{ data: BackupSummary }>('/super-admin/backups/summary');
  const loaded = summary.data !== null && infrastructure.data !== null && backups.data !== null;
  const attention = attentionItems(summary.data?.data, infrastructure.data?.data, backups.data?.data);

  return (
    <AdminPage title="Platform overview" description="Umar Techy staff only. These figures cover every store on the platform.">
      <div className="space-y-6">
        {loaded && (
          attention.length > 0 ? (
            <section aria-labelledby="attention-heading" className="rounded-xl border border-amber-200 bg-amber-50 p-4">
              <h2 id="attention-heading" className="flex items-center gap-2 font-semibold text-amber-900"><Icon name="bell" size={18} />Needs attention</h2>
              <ul className="mt-2 space-y-1.5 text-sm text-amber-900">
                {attention.map((item) => (
                  <li key={item.text} className="flex flex-wrap items-center justify-between gap-2">
                    <span>{item.text}</span>
                    <Link href={item.href} className={`rounded font-semibold underline ${FOCUS_RING}`}>Open</Link>
                  </li>
                ))}
              </ul>
            </section>
          ) : (
            <p role="status" className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
              <Icon name="checkCircle" size={18} />All clear: backups on time, infrastructure checks passing, no failed payments.
            </p>
          )
        )}
        <QueryState state={summary} lines={4}>
          {({ data }) => (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <StatCard icon="store" label="Stores" value={data.stores.total.toLocaleString()} href="/super-admin/stores" />
              <StatCard icon="checkCircle" label="Active subscriptions" value={data.stores.active_subscriptions.toLocaleString()} />
              <StatCard icon="clock" label="On trial" value={data.stores.trial_subscriptions.toLocaleString()} />
              <StatCard icon="lock" label="Suspended" value={data.stores.suspended_subscriptions.toLocaleString()} />
              <StatCard icon="cart" label="Orders, last 30 days" value={data.orders.total_last_30_days.toLocaleString()} />
              <StatCard icon="chart" label="Order totals, last 30 days" value={plainAmount(data.orders.revenue_minor_last_30_days)} hint="Sum of every store's totals, each in its own currency. Not converted." />
              <StatCard icon="wallet" label="Payments collected, last 30 days" value={plainAmount(data.payments.collected_amount_minor_last_30_days)} hint="Same: not converted between currencies." />
              <StatCard icon="activity" label="Failed payments, last 30 days" value={data.payments.failed_last_30_days.toLocaleString()} href="/super-admin/monitoring" />
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
