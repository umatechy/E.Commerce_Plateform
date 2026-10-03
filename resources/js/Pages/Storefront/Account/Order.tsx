import { formatDate, formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import OrderReturns from '@/Components/Storefront/OrderReturns';
import { formatMoney } from '@/lib/money';
import { errorMessage, storefrontFetch } from '@/Storefront/api';
import { formatAddress, useCustomer } from '@/Storefront/account';
import { paymentMethodLabel, statusLabel, type OrderDetail } from '@/Storefront/orders';
import type { StorefrontPageProps } from '@/Storefront/types';

function variantText(variant: OrderDetail['items'][number]['variant']): string {
  if (!variant) return '';
  if (typeof variant === 'string') return variant;

  return Object.entries(variant)
    .map(([k, v]) => `${k}: ${v}`)
    .join(' · ');
}

export default function Order({ storefront, seo, order_id }: StorefrontPageProps & { order_id: string }) {
  const { customer } = useCustomer(storefront, { required: true });
  const [order, setOrder] = useState<OrderDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const base = storefront.base_path;

  useEffect(() => {
    if (!customer) return;
    storefrontFetch<{ data: OrderDetail }>(storefront, `/customer/orders/${encodeURIComponent(order_id)}`)
      .then((res) => setOrder(res.data))
      .catch((e) => setError(errorMessage(e)));
  }, [customer, order_id, storefront]);

  const money = (minor: number) => formatMoney(minor, order?.currency ?? storefront.store.currency);

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account/orders" title={order ? `Order ${order.number}` : 'Order'}>
      <Link href={`${base}/account/orders`} className="text-sm text-sf-accent">
        ← All orders
      </Link>
      {error && <p className="mt-4 text-sf-error">{error}</p>}
      {order && (
        <div className="mt-4 space-y-8">
          <p className="text-sm text-sf-muted">
            Placed {formatDateTime(order.placed_at)} · <span className="font-medium text-sf-text">{statusLabel(order.status)}</span>
            {order.cancellation_reason && ` (${order.cancellation_reason.replace(/_/g, ' ')})`}
            {' · '}
            <Link href={`${base}/account/support/new?order=${encodeURIComponent(order.id)}`} className="text-sf-accent">
              Get help with this order
            </Link>
          </p>

          <table className="w-full text-left text-sm">
            <tbody>
              {order.items.map((item, index) => (
                <tr key={index} className="border-b border-sf-border">
                  <td className="py-3">
                    <span className="font-medium">{item.name}</span>
                    {variantText(item.variant) && <span className="block text-sf-muted">{variantText(item.variant)}</span>}
                  </td>
                  <td className="py-3 text-right">× {item.quantity}</td>
                  <td className="py-3 text-right">{money(item.line_total_minor)}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <dl className="ml-auto max-w-xs space-y-1 text-sm">
            <div className="flex justify-between"><dt>Subtotal</dt><dd>{money(order.subtotal_minor)}</dd></div>
            {order.discount_total_minor > 0 && <div className="flex justify-between text-sf-success"><dt>Discount</dt><dd>−{money(order.discount_total_minor)}</dd></div>}
            <div className="flex justify-between"><dt>Shipping</dt><dd>{money(order.shipping_total_minor)}</dd></div>
            {order.tax_total_minor > 0 && <div className="flex justify-between"><dt>Tax</dt><dd>{money(order.tax_total_minor)}</dd></div>}
            <div className="flex justify-between border-t border-sf-border pt-2 font-semibold"><dt>Total</dt><dd>{money(order.grand_total_minor)}</dd></div>
          </dl>

          <div className="grid gap-6 sm:grid-cols-2">
            <div>
              <h2 className="mb-2 font-semibold">Delivery address</h2>
              <p className="text-sm text-sf-muted">{order.shipping_address?.name}</p>
              <p className="text-sm text-sf-muted">{formatAddress(order.shipping_address) || '—'}</p>
            </div>
            <div>
              <h2 className="mb-2 font-semibold">Payment</h2>
              {order.payments.map((payment, index) => (
                <p key={index} className="text-sm text-sf-muted">
                  {paymentMethodLabel(payment.method)} · {payment.status.replace(/_/g, ' ')} · {money(payment.amount_minor)}
                </p>
              ))}
            </div>
          </div>

          {order.shipments.length > 0 && (
            <div>
              <h2 className="mb-2 font-semibold">Shipments</h2>
              <ul className="space-y-2 text-sm">
                {order.shipments.map((shipment) => (
                  <li key={shipment.id} className="rounded-sf border border-sf-border p-3">
                    <span className="font-medium">{shipment.carrier ?? 'Shipment'}</span> · {shipment.status.replace(/_/g, ' ')}
                    {shipment.tracking_number && <span className="block text-sf-muted">Tracking number: {shipment.tracking_number}</span>}
                    {shipment.estimated_delivery_at && !shipment.delivered_at && (
                      <span className="block text-sf-muted">Expected by {formatDate(shipment.estimated_delivery_at)}</span>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}

          <OrderReturns shell={storefront} orderId={order.id} />
        </div>
      )}
    </AccountLayout>
  );
}
