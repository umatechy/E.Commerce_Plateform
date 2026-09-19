import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LoadingState from '@/Components/LoadingState';
import ErrorState from '@/Components/ErrorState';

/**
 * Module 04 §40-41 "Customer Package Visibility / Usage Dashboard".
 * Reads from /api/v1/subscription and /api/v1/subscription/usage — the
 * store's OWN data only, resolved server-side (this page sends no
 * store/tenant ID anywhere). Upgrade/downgrade ACTIONS are not built
 * here yet (Module 29 Billing owns checkout/payment; this page only
 * presents current state, per this milestone's "Do not implement
 * payment checkout here").
 */
type Subscription = {
  status: string;
  grants_access: boolean;
  package: { code: string; name: string };
  trial_ends_at: string | null;
  current_period_ends_at: string | null;
};

type UsageEntry = { limit: number | null; current: number; unlimited: boolean; remaining: number | null };

export default function Overview() {
  const [subscription, setSubscription] = useState<Subscription | null>(null);
  const [usage, setUsage] = useState<Record<string, UsageEntry> | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([
      fetch('/api/v1/subscription').then((r) => (r.ok ? r.json() : Promise.reject(r))),
      fetch('/api/v1/subscription/usage').then((r) => (r.ok ? r.json() : Promise.reject(r))),
    ])
      .then(([subRes, usageRes]) => {
        setSubscription(subRes.data);
        setUsage(usageRes.data);
      })
      .catch(() => setError('Could not load your subscription. Please try again.'));
  }, []);

  return (
    <AuthenticatedLayout>
      <h1 className="text-lg font-semibold">Your Plan</h1>

      {error && <ErrorState message={error} />}
      {!error && !subscription && <LoadingState />}

      {subscription && (
        <div className="mt-4 space-y-6">
          <div className="rounded border bg-white p-4">
            <p className="text-sm text-gray-500">Current package</p>
            <p className="text-xl font-semibold">{subscription.package.name}</p>
            <p className="mt-1 text-sm text-gray-500">
              Status: {subscription.status}
              {!subscription.grants_access && (
                <span className="ml-2 rounded bg-red-100 px-2 py-0.5 text-red-700">
                  Access restricted
                </span>
              )}
            </p>
            {subscription.trial_ends_at && (
              <p className="mt-1 text-sm text-gray-500">
                Trial ends: {new Date(subscription.trial_ends_at).toLocaleDateString()}
              </p>
            )}
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
