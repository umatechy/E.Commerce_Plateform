import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import EmptyState from '@/Components/EmptyState';
import { formatMoney } from '@/lib/money';
import { storefrontFetch } from '@/Storefront/api';
import { useCustomer } from '@/Storefront/account';
import { statusLabel, type OrderSummary } from '@/Storefront/orders';
import type { StorefrontPageProps } from '@/Storefront/types';

type Page = { orders: OrderSummary[]; pagination: { page: number; last_page: number; total: number } };

export default function Orders({ storefront, seo }: StorefrontPageProps) {
  const { customer } = useCustomer(storefront, { required: true });
  const [page, setPage] = useState(1);
  const [data, setData] = useState<Page | null>(null);
  const base = storefront.base_path;

  useEffect(() => {
    if (!customer) return;
    storefrontFetch<{ data: Page }>(storefront, '/customer/orders', { query: { page: String(page) } }).then((res) => setData(res.data));
  }, [customer, page, storefront]);

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account/orders" title="Your orders">
      {data?.orders.length === 0 && <EmptyState title="No orders yet" description="Orders you place while signed in appear here." />}
      {data && data.orders.length > 0 && (
        <>
          <table className="w-full text-left text-sm">
            <thead className="text-sf-muted">
              <tr>
                <th className="py-2">Order</th>
                <th className="py-2">Placed</th>
                <th className="py-2">Status</th>
                <th className="py-2 text-right">Items</th>
                <th className="py-2 text-right">Total</th>
              </tr>
            </thead>
            <tbody>
              {data.orders.map((order) => (
                <tr key={order.id} className="border-t border-sf-border">
                  <td className="py-3">
                    <Link href={`${base}/account/orders/${order.id}`} className="font-medium text-sf-accent">
                      {order.number}
                    </Link>
                  </td>
                  <td className="py-3">{new Date(order.placed_at).toLocaleDateString()}</td>
                  <td className="py-3">{statusLabel(order.status)}</td>
                  <td className="py-3 text-right">{order.item_count}</td>
                  <td className="py-3 text-right">{formatMoney(order.grand_total_minor, order.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {data.pagination.last_page > 1 && (
            <div className="mt-6 flex items-center justify-center gap-4 text-sm">
              <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-sf border border-sf-border px-3 py-1 disabled:opacity-40">
                Previous
              </button>
              <span>
                Page {data.pagination.page} of {data.pagination.last_page}
              </span>
              <button type="button" disabled={page >= data.pagination.last_page} onClick={() => setPage(page + 1)} className="rounded-sf border border-sf-border px-3 py-1 disabled:opacity-40">
                Next
              </button>
            </div>
          )}
        </>
      )}
    </AccountLayout>
  );
}
