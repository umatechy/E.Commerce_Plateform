import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LoadingState from '@/Components/LoadingState';
import EmptyState from '@/Components/EmptyState';
import ErrorState from '@/Components/ErrorState';

/**
 * Module 09 "Admin Order View" — list + status + cancel action where
 * permitted. Server remains authoritative throughout: this page never
 * computes a total or decides whether cancellation is allowed — it
 * just displays what the API returns and lets a 422 from the server
 * (invalid transition) surface as an error message.
 */
type OrderRow = {
  id: string;
  order_number: string;
  status: string;
  grand_total_minor: number;
  currency: string;
  is_guest_order: boolean;
  guest_name?: string;
};

export default function Index() {
  const [orders, setOrders] = useState<OrderRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    fetch('/api/v1/orders')
      .then((r) => (r.ok ? r.json() : Promise.reject(r)))
      .then((body) => setOrders(body.data))
      .catch(() => setError('Could not load orders.'));
  }

  useEffect(load, []);

  function cancel(orderId: string) {
    const reason = prompt(
      'Reason (customer_request, out_of_stock, payment_failed, address_issue, fraud_review, store_cancellation, other):',
      'customer_request'
    );
    if (!reason) return;

    fetch(`/api/v1/orders/${orderId}/cancel`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ reason }),
    })
      .then((r) => (r.ok ? load() : r.json().then((b) => setError(b.message))))
      .catch(() => setError('Could not cancel this order.'));
  }

  return (
    <AuthenticatedLayout>
      <h1 className="text-lg font-semibold">Orders</h1>

      {error && <ErrorState message={error} />}
      {!error && orders === null && <LoadingState />}
      {!error && orders !== null && orders.length === 0 && (
        <EmptyState title="No orders yet" description="Orders will appear here once customers start buying." />
      )}

      {orders && orders.length > 0 && (
        <table className="mt-4 w-full text-sm">
          <thead>
            <tr className="border-b text-left text-gray-500">
              <th className="py-2">Order #</th>
              <th>Customer</th>
              <th>Status</th>
              <th>Total</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {orders.map((order) => (
              <tr key={order.id} className="border-b">
                <td className="py-2 font-mono">{order.order_number}</td>
                <td>{order.is_guest_order ? `${order.guest_name} (guest)` : 'Customer'}</td>
                <td className="capitalize">{order.status.replace(/_/g, ' ')}</td>
                <td>
                  {(order.grand_total_minor / 100).toFixed(2)} {order.currency}
                </td>
                <td>
                  {['draft', 'pending_confirmation', 'confirmed', 'processing', 'ready_to_fulfill'].includes(
                    order.status
                  ) && (
                    <button className="text-gray-500 hover:text-red-600" onClick={() => cancel(order.id)}>
                      Cancel
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </AuthenticatedLayout>
  );
}
