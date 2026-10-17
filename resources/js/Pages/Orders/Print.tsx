import { useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import Button from '@/Components/ui/Button';
import Icon from '@/Components/ui/Icon';
import { ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useAuth } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { money } from '@/lib/money';
import { formatDateTime } from '@/lib/datetime';
import { orderPhone } from '@/lib/contact';
import { addressLines, orderCustomer, type Order } from '@/lib/orders';

/**
 * UI-15 — the packing slip of one order, to print and put in the parcel:
 * who it goes to (name, phone, address), what to pack (with a tick box per
 * line for the packer), the totals and, when it is not paid yet, the
 * amount the courier collects. Opened from the order with ?print=1 it
 * calls the browser's print dialog once loaded.
 */
export default function Print({ orderId }: { orderId: string }) {
  const auth = useAuth();
  const state = useApi<{ data: Order }>(`/orders/${orderId}`);
  const order = state.data?.data ?? null;
  const autoPrint = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('print') === '1';

  useEffect(() => {
    if (order && autoPrint) window.setTimeout(() => window.print(), 300);
  }, [order, autoPrint]);

  const due = order ? (order.payment_status === 'paid' ? 0 : order.payable_minor ?? order.grand_total_minor) : 0;
  const phone = order ? orderPhone(order) : null;

  return (
    <div className="min-h-screen bg-slate-100 py-8 text-slate-900 print:bg-white print:py-0">
      <Head title={order ? `Packing slip ${order.order_number}` : 'Packing slip'} />
      <div className="mx-auto mb-4 flex max-w-3xl items-center justify-between gap-3 px-4 print:hidden">
        <Link href={`/orders/${orderId}`} className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">
          <Icon name="arrowLeft" size={16} />Back to the order
        </Link>
        <Button variant="primary" onClick={() => window.print()} disabled={!order}><Icon name="printer" size={16} />Print</Button>
      </div>

      <main className="mx-auto max-w-3xl px-4 print:max-w-none print:px-0">
        {state.error ? (
          <ErrorPanel message={state.error} onRetry={state.reload} />
        ) : order === null ? (
          <Skeleton lines={8} />
        ) : (
          <article className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200 print:rounded-none print:p-0 print:shadow-none print:ring-0">
            <header className="flex flex-wrap items-start justify-between gap-4 border-b-2 border-slate-900 pb-4">
              <div>
                <p className="text-xl font-bold">{auth.activeStore?.name}</p>
                <p className="text-sm text-slate-500">Packing slip</p>
              </div>
              <div className="text-right">
                <p className="font-mono text-2xl font-bold tracking-wide">{order.order_number}</p>
                <p className="text-sm text-slate-500">{formatDateTime(order.created_at)}</p>
              </div>
            </header>

            <section className="mt-6 grid gap-6 sm:grid-cols-2 print:grid-cols-2">
              <div>
                <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Ship to</h2>
                <p className="mt-1 text-lg font-semibold">{orderCustomer(order).replace(/\s*\(guest\)$/, '')}</p>
                {phone && <p className="font-mono text-base">{phone}</p>}
                <address className="mt-1 not-italic leading-relaxed">
                  {addressLines(order.shipping_address).filter((line) => line !== phone && line !== orderCustomer(order).replace(/s*(guest)$/, "")).map((line, index) => <span key={index} className="block">{line}</span>)}
                </address>
              </div>
              <div className={`self-start rounded-xl border-2 p-4 ${due > 0 ? 'border-slate-900' : 'border-slate-200'}`}>
                <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-500">{due > 0 ? 'Collect on delivery' : 'Payment'}</h2>
                <p className="mt-1 text-3xl font-bold tabular-nums">{due > 0 ? money(due, order.currency) : 'Paid'}</p>
                {due > 0 && <p className="mt-1 text-xs text-slate-500">Cash on delivery — the courier collects this amount.</p>}
              </div>
            </section>

            <table className="mt-8 w-full text-left text-sm">
              <thead className="border-b border-slate-300 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                  <th className="w-10 py-2"><span className="sr-only">Packed</span></th>
                  <th className="py-2">Item</th>
                  <th className="py-2 text-right">Qty</th>
                  <th className="py-2 text-right">Total</th>
                </tr>
              </thead>
              <tbody>
                {(order.items ?? []).map((item) => (
                  <tr key={item.id} className="border-b border-slate-200">
                    <td className="py-3"><span aria-hidden="true" className="block h-4 w-4 rounded border-2 border-slate-400" /></td>
                    <td className="py-3">
                      <span className="font-medium">{item.product_name}</span>
                      {item.sku && <span className="block font-mono text-xs text-slate-500">{item.sku}</span>}
                    </td>
                    <td className="py-3 text-right text-base font-bold tabular-nums">{item.quantity}</td>
                    <td className="py-3 text-right tabular-nums">{money(item.line_total_minor, order.currency)}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            <dl className="ml-auto mt-4 max-w-xs space-y-1 text-sm">
              <div className="flex justify-between"><dt className="text-slate-600">Subtotal</dt><dd className="tabular-nums">{money(order.subtotal_minor, order.currency)}</dd></div>
              {order.discount_total_minor > 0 && <div className="flex justify-between"><dt className="text-slate-600">Discounts</dt><dd className="tabular-nums">− {money(order.discount_total_minor, order.currency)}</dd></div>}
              {order.tax_total_minor > 0 && <div className="flex justify-between"><dt className="text-slate-600">{order.tax?.label ?? 'Tax'}</dt><dd className="tabular-nums">{money(order.tax_total_minor, order.currency)}</dd></div>}
              <div className="flex justify-between"><dt className="text-slate-600">Shipping</dt><dd className="tabular-nums">{money(order.shipping_total_minor, order.currency)}</dd></div>
              <div className="flex justify-between border-t border-slate-300 pt-1 text-base font-bold"><dt>Total</dt><dd className="tabular-nums">{money(order.grand_total_minor, order.currency)}</dd></div>
            </dl>

            {order.notes && (
              <section className="mt-6 rounded-xl bg-slate-50 p-4 print:border print:border-slate-300 print:bg-white">
                <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Customer note</h2>
                <p className="mt-1 whitespace-pre-wrap text-sm">{order.notes}</p>
              </section>
            )}

            <p className="mt-10 text-center text-sm text-slate-500">Thank you for shopping with {auth.activeStore?.name}.</p>
          </article>
        )}
      </main>
    </div>
  );
}
