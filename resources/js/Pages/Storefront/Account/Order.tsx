import { formatDate, formatDateTime } from '@/lib/datetime';
import { useT } from '@/Storefront/i18n';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import OrderReturns, { customerReturnsApi } from '@/Components/Storefront/OrderReturns';
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
  const t = useT();
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
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account/orders" title={order ? `${t('Order')} ${order.number}` : t('Order')}>
      <Link href={`${base}/account/orders`} className="text-sm text-sf-accent">
        <span className="inline-block rtl:rotate-180" aria-hidden="true">←</span> {t('All orders')}
      </Link>
      {error && <p className="mt-4 text-sf-error">{error}</p>}
      {order && (
        <div className="mt-4 space-y-8">
          <p className="text-sm text-sf-muted">
            {t('Placed {date}', { date: formatDateTime(order.placed_at) })} · <span className="font-medium text-sf-text">{t(statusLabel(order.status))}</span>
            {order.cancellation_reason && ` (${order.cancellation_reason.replace(/_/g, ' ')})`}
            {' · '}
            <Link href={`${base}/account/support/new?order=${encodeURIComponent(order.id)}`} className="text-sf-accent">
              {t('Get help with this order')}
            </Link>
          </p>

          <table className="w-full text-start text-sm">
            <tbody>
              {order.items.map((item, index) => (
                <tr key={index} className="border-b border-sf-border">
                  <td className="py-3">
                    <span className="font-medium">{item.name}</span>
                    {variantText(item.variant) && <span className="block text-sf-muted">{variantText(item.variant)}</span>}
                  </td>
                  <td className="py-3 text-end">× {item.quantity}</td>
                  <td className="py-3 text-end">{money(item.line_total_minor)}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <dl className="ms-auto max-w-xs space-y-1 text-sm">
            <div className="flex justify-between"><dt>{t('Subtotal')}</dt><dd>{money(order.subtotal_minor)}</dd></div>
            {order.discount_total_minor > 0 && <div className="flex justify-between text-sf-success"><dt>{t('Discount')}</dt><dd>−{money(order.discount_total_minor)}</dd></div>}
            <div className="flex justify-between"><dt>{t('Shipping')}</dt><dd>{money(order.shipping_total_minor)}</dd></div>
            {order.tax_total_minor > 0 && !order.tax?.prices_include_tax && <div className="flex justify-between"><dt>{order.tax?.label ?? t('Tax')}</dt><dd>{money(order.tax_total_minor)}</dd></div>}
            {order.tax?.exempt && <div className="flex justify-between"><dt>{order.tax.label}</dt><dd>{t('Exempt')}</dd></div>}
            <div className="flex justify-between border-t border-sf-border pt-2 font-semibold"><dt>{t('Total')}</dt><dd>{money(order.grand_total_minor)}</dd></div>
            {/* Phase B46: with tax-inclusive prices the tax is inside the total. */}
            {order.tax_total_minor > 0 && order.tax?.prices_include_tax && <div className="flex justify-between text-sf-muted"><dt>{t('Includes {label}', { label: order.tax.label })}</dt><dd>{money(order.tax_total_minor)}</dd></div>}
            {(order.store_credit_minor ?? 0) > 0 && <div className="flex justify-between"><dt>{t('Paid with store credit')}</dt><dd>−{money(order.store_credit_minor ?? 0)}</dd></div>}
          </dl>

          <div className="grid gap-6 sm:grid-cols-2">
            <div>
              <h2 className="mb-2 font-semibold">{t('Delivery address')}</h2>
              <p className="text-sm text-sf-muted">{order.shipping_address?.name}</p>
              <p className="text-sm text-sf-muted">{formatAddress(order.shipping_address) || '—'}</p>
            </div>
            <div>
              <h2 className="mb-2 font-semibold">{t('Payment')}</h2>
              {order.payments.map((payment, index) => (
                <p key={index} className="text-sm text-sf-muted">
                  {t(paymentMethodLabel(payment.method))} · {t(payment.status.replace(/_/g, ' '))} · {money(payment.amount_minor)}
                </p>
              ))}
            </div>
          </div>

          {order.shipments.length > 0 && (
            <div>
              <h2 className="mb-2 font-semibold">{t('Shipments')}</h2>
              <ul className="space-y-2 text-sm">
                {order.shipments.map((shipment) => (
                  <li key={shipment.id} className="rounded-sf border border-sf-border p-3">
                    <span className="font-medium">{shipment.carrier ?? t('Shipment')}</span> · {t(shipment.status.replace(/_/g, ' '))}
                    {shipment.tracking_number && <span className="block text-sf-muted">{t('Tracking number:')} {shipment.tracking_number}</span>}
                    {shipment.estimated_delivery_at && !shipment.delivered_at && (
                      <span className="block text-sf-muted">{t('Expected by {date}', { date: formatDate(shipment.estimated_delivery_at) })}</span>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}

          <OrderReturns shell={storefront} api={customerReturnsApi(storefront, order.id)} />
        </div>
      )}
    </AccountLayout>
  );
}
