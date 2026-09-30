import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LoadingState from '@/Components/LoadingState';
import ErrorState from '@/Components/ErrorState';
import InvoiceTable, { formatMoney, type Invoice } from '@/Components/InvoiceTable';

/**
 * Module 04 §40-41 "Customer Package Visibility / Usage Dashboard" and
 * Module 29 (Phase B23) billing. Reads /api/v1/billing,
 * /api/v1/billing/invoices and /api/v1/subscription/usage — the store's
 * OWN data only, resolved server-side (this page sends no store/tenant
 * ID anywhere). A store without billing.view simply sees the error state.
 */
type Billing = {
  subscription: {
    status: string;
    grants_access: boolean;
    package: { code: string; name: string };
    billing_interval: string;
    trial_ends_at: string | null;
    current_period_ends_at: string | null;
    grace_period_ends_at: string | null;
    cancel_at_period_end: boolean;
  };
  upcoming: { period_start: string; currency: string; total_minor: number } | null;
  balance: { open_invoices: number; overdue_invoices: number; amount_due_minor: number };
};

type UsageEntry = { limit: number | null; current: number; unlimited: boolean; remaining: number | null };

const json = (url: string) => fetch(url, { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : Promise.reject(r)));

function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString() : '—';
}

export default function Overview() {
  const [billing, setBilling] = useState<Billing | null>(null);
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [usage, setUsage] = useState<Record<string, UsageEntry> | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([json('/api/v1/billing'), json('/api/v1/billing/invoices'), json('/api/v1/subscription/usage')])
      .then(([billingRes, invoicesRes, usageRes]) => {
        setBilling(billingRes.data);
        setInvoices(invoicesRes.data.data);
        setUsage(usageRes.data);
      })
      .catch(() => setError('Could not load your billing details. Please try again.'));
  }, []);

  const subscription = billing?.subscription;
  const currency = billing?.upcoming?.currency ?? invoices[0]?.currency ?? 'USD';

  return (
    <AuthenticatedLayout>
      <h1 className="text-lg font-semibold">Plan &amp; Billing</h1>

      {error && <ErrorState message={error} />}
      {!error && !billing && <LoadingState />}

      {billing && subscription && (
        <div className="mt-4 space-y-6">
          {billing.balance.overdue_invoices > 0 && (
            <div className="rounded border border-red-200 bg-red-50 p-4 text-sm text-red-800">
              {formatMoney(billing.balance.amount_due_minor, currency)} is overdue.
              {subscription.grace_period_ends_at && ` Your store will be suspended on ${formatDate(subscription.grace_period_ends_at)} unless it is paid.`}
            </div>
          )}

          <div className="grid gap-4 md:grid-cols-2">
            <div className="rounded border bg-white p-4">
              <p className="text-sm text-gray-500">Current package</p>
              <p className="text-xl font-semibold">{subscription.package.name}</p>
              <p className="mt-1 text-sm text-gray-500">
                Status: {subscription.status} · billed {subscription.billing_interval}
                {!subscription.grants_access && (
                  <span className="ml-2 rounded bg-red-100 px-2 py-0.5 text-red-700">Access restricted</span>
                )}
              </p>
              {subscription.trial_ends_at && subscription.status === 'trialing' && (
                <p className="mt-1 text-sm text-gray-500">Trial ends: {formatDate(subscription.trial_ends_at)}</p>
              )}
            </div>

            <div className="rounded border bg-white p-4">
              <p className="text-sm text-gray-500">
                {subscription.cancel_at_period_end ? 'Subscription ends' : 'Next charge'}
              </p>
              {subscription.cancel_at_period_end || !billing.upcoming ? (
                <p className="text-xl font-semibold">{formatDate(subscription.current_period_ends_at)}</p>
              ) : (
                <>
                  <p className="text-xl font-semibold">{formatMoney(billing.upcoming.total_minor, billing.upcoming.currency)}</p>
                  <p className="mt-1 text-sm text-gray-500">on {formatDate(billing.upcoming.period_start)}</p>
                </>
              )}
            </div>
          </div>

          <div className="rounded border bg-white p-4">
            <p className="mb-2 text-sm font-medium text-gray-700">Invoices</p>
            <InvoiceTable invoices={invoices} />
          </div>

          {usage && Object.keys(usage).length > 0 && (
            <div className="rounded border bg-white p-4">
              <p className="text-sm font-medium text-gray-700">Usage</p>
              <ul className="mt-2 space-y-2">
                {Object.entries(usage).map(([key, entry]) => (
                  <li key={key} className="flex items-center justify-between text-sm">
                    <span>{key}</span>
                    <span className="text-gray-600">
                      {entry.unlimited ? 'Unlimited' : `${entry.current} / ${entry.limit}`}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </AuthenticatedLayout>
  );
}
