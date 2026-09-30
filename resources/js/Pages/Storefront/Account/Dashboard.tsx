import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import { formatMoney } from '@/lib/money';
import { storefrontFetch } from '@/Storefront/api';
import { useCustomer } from '@/Storefront/account';
import { statusLabel, type OrderSummary } from '@/Storefront/orders';
import type { StorefrontPageProps } from '@/Storefront/types';

export default function Dashboard({ storefront, seo }: StorefrontPageProps) {
  const { customer } = useCustomer(storefront, { required: true });
  const [orders, setOrders] = useState<OrderSummary[] | null>(null);
  const base = storefront.base_path;

  useEffect(() => {
    if (!customer) return;
    storefrontFetch<{ data: { orders: OrderSummary[] } }>(storefront, '/customer/orders', { query: { per_page: '3' } })
      .then((res) => setOrders(res.data.orders))
      .catch(() => setOrders([]));
  }, [customer, storefront]);

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account" title={`Hello, ${customer?.name.split(' ')[0] ?? ''}`}>
      <div className="grid gap-4 sm:grid-cols-3">
        {[
          { href: '/account/orders', title: 'Orders', text: 'Track and review your orders' },
          { href: '/account/addresses', title: 'Addresses', text: 'Manage delivery addresses' },
          { href: '/account/profile', title: 'Profile & security', text: 'Details, password and privacy' },
        ].map((card) => (
          <Link key={card.href} href={`${base}${card.href}`} className="rounded-sf border border-sf-border p-4 hover:border-sf-accent">
            <span className="font-semibold">{card.title}</span>
            <span className="mt-1 block text-sm text-sf-muted">{card.text}</span>
          </Link>
        ))}
      </div>

      <h2 className="mb-3 mt-10 text-xl font-semibold">Recent orders</h2>
      {orders === null && <p className="text-sm text-sf-muted">Loading…</p>}
      {orders?.length === 0 && (
        <p className="text-sm text-sf-muted">
          No orders yet.{' '}
          <Link href={`${base}/products`} className="text-sf-accent">
            Start shopping
          </Link>
        </p>
      )}
      <ul className="divide-y divide-sf-border">
        {orders?.map((order) => (
          <li key={order.id}>
            <Link href={`${base}/account/orders/${order.id}`} className="flex justify-between gap-4 py-3 hover:text-sf-accent">
              <span>
                <span className="font-medium">{order.number}</span>
                <span className="ml-3 text-sm text-sf-muted">{new Date(order.placed_at).toLocaleDateString()}</span>
              </span>
              <span className="text-sm">
                {statusLabel(order.status)} · {formatMoney(order.grand_total_minor, order.currency)}
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </AccountLayout>
  );
}
