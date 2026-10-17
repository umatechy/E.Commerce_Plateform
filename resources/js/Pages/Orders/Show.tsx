import { FormEvent, useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink, FOCUS_RING } from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField } from '@/Components/ui/Form';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { AccessNotice, Card, Details, EmptyPanel, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import PaymentDrawer from '@/Components/Orders/PaymentDialogs';
import ReturnsCard from '@/Components/Orders/ReturnsCard';
import { ORDER_RETURN_LABELS } from '@/lib/returns';
import { CreateShipmentDialog, ShipmentDrawer } from '@/Components/Orders/ShipmentDialogs';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { formatDateTime } from '@/lib/datetime';
import { options } from '@/lib/labels';
import { ContactButtons, WhatsAppDialog } from '@/Components/Orders/ContactCustomer';
import Icon, { type IconName } from '@/Components/ui/Icon';
import { useAuth } from '@/lib/access';
import { orderPhone } from '@/lib/contact';
import { useAdminT } from '@/lib/adminI18n';
import { addressLines, CANCELLATION_REASONS, CARRIER_LABELS, orderCustomer, PAYMENT_METHOD_LABELS, ratePercent, type Order, type OrderItem, type Payment, type Shipment, type TimelineEvent } from '@/lib/orders';

/**
 * Module 09 §37 "Admin Order View": one order with its items, totals,
 * customer, payments, shipments and timeline.
 *
 * The order's status is moved by the server's state machine, through
 * payments, shipments and cancellation. This page has no "set status"
 * control because the backend has none. Cancelling is offered; whether
 * it is allowed is the server's answer.
 *
 * UI-15: a "next step" bar says what to do with the order now (confirm it
 * with the customer, pack and ship it, tell them it is on its way) with the
 * buttons for it: WhatsApp and call from the store's own phone, the packing
 * slip, and creating the shipment.
 */
function CancelDialog({ order, onClose, onDone }: { order: Order; onClose: () => void; onDone: (order: Order) => void }) {
  const form = useForm({ reason: 'customer_request', note: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const saved = await form.submit(
      () => adminFetch<{ data: Order }>(`/orders/${order.id}/cancel`, { method: 'POST', body: { reason: form.values.reason, note: form.values.note === '' ? null : form.values.note } }),
      'Order cancelled.',
    );
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open title={`Cancel order ${order.order_number}?`} description="The reserved stock is released. A cancelled order cannot be reopened." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <SelectField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} options={options(CANCELLATION_REASONS)} />
        <TextAreaField label="Note" optional rows={3} value={form.values.note} onChange={(v) => form.set('note', v)} error={form.errors.note} maxLength={1000} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy} data-autofocus>Keep order</Button>
          <Button type="submit" variant="danger" busy={form.busy} busyLabel="Cancelling…">Cancel order</Button>
        </div>
      </form>
    </Dialog>
  );
}

function PaymentsCard({ order, onChanged }: { order: Order; onChanged: () => void }) {
  const list = usePagedApi<Payment>('/payments', { order: order.id });
  const [open, setOpen] = useState<string | null>(null);
  const columns: Column<Payment>[] = [
    { key: 'method', header: 'Method', render: (payment) => PAYMENT_METHOD_LABELS[payment.method] ?? humanize(payment.method) },
    { key: 'status', header: 'Status', priority: true, render: (payment) => <StatusBadge status={payment.status} /> },
    { key: 'amount', header: 'Amount', align: 'right', priority: true, render: (payment) => money(payment.amount_minor, payment.currency) },
    { key: 'open', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (payment) => <Button size="sm" variant="ghost" onClick={() => setOpen(payment.id)}>Open</Button> },
  ];

  return (
    <Card title="Payments">
      <DataTable caption="Payments of this order" columns={columns} rows={list.rows} rowKey={(payment) => payment.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={
          <p className="text-sm text-slate-600">
            No payment has been started for this order.
            {order.source === 'admin' && ' It was created in the admin without a payment method, and the server does not let an order without a payment be shipped. Cancel it if it will not be paid.'}
          </p>
        } />
      {open && (
        <PaymentDrawer
          paymentId={open}
          onClose={() => setOpen(null)}
          onChanged={() => {
            list.reload();
            onChanged();
          }}
        />
      )}
    </Card>
  );
}

function ShipmentsCard({ order, onChanged, createRequest = 0 }: { order: Order; onChanged: () => void; createRequest?: number }) {
  const access = useAccess();
  const list = usePagedApi<Shipment>('/shipments', { order: order.id });
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  // The next-step bar's "Create shipment".
  useEffect(() => {
    if (createRequest > 0) setCreating(true);
  }, [createRequest]);
  const columns: Column<Shipment>[] = [
    { key: 'carrier', header: 'Carrier', render: (shipment) => CARRIER_LABELS[shipment.carrier] ?? humanize(shipment.carrier) },
    { key: 'status', header: 'Status', priority: true, render: (shipment) => <StatusBadge status={shipment.status} /> },
    { key: 'tracking', header: 'Tracking', render: (shipment) => shipment.tracking_number ?? '—' },
    { key: 'open', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (shipment) => <Button size="sm" variant="ghost" onClick={() => setOpen(shipment.id)}>Open</Button> },
  ];
  const changed = () => {
    list.reload();
    onChanged();
  };

  return (
    <Card title="Shipments" actions={access.can('shipments.fulfill') && order.status !== 'cancelled' && <Button size="sm" onClick={() => setCreating(true)}>Create shipment</Button>}>
      <DataTable caption="Shipments of this order" columns={columns} rows={list.rows} rowKey={(shipment) => shipment.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<p className="text-sm text-slate-600">Nothing has been shipped yet.</p>} />
      {open && <ShipmentDrawer shipmentId={open} onClose={() => setOpen(null)} onChanged={changed} />}
      {creating && (
        <CreateShipmentDialog
          order={order}
          onClose={() => setCreating(false)}
          onDone={() => {
            setCreating(false);
            changed();
          }}
        />
      )}
    </Card>
  );
}

function Timeline({ orderId }: { orderId: string }) {
  const t = useAdminT();
  const state = useApi<{ data: TimelineEvent[] }>(`/orders/${orderId}/timeline`);

  return (
    <Card title="Timeline">
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : state.data === null ? (
        <Skeleton lines={3} />
      ) : state.data.data.length === 0 ? (
        <p className="text-sm text-slate-600">{t('No events yet.')}</p>
      ) : (
        <ol className="space-y-3 text-sm">
          {state.data.data.map((event, index) => (
            <li key={`${event.created_at}-${index}`} className="border-s-2 border-slate-200 ps-3">
              <p className="font-medium text-slate-900">
                {t(humanize(event.event_type))}
                {event.to_status && <span className="font-normal text-slate-600"> {'→'} {t(humanize(event.to_status))}</span>}
              </p>
              {(event.reason || event.note) && <p className="text-slate-700">{[event.reason ? humanize(event.reason) : null, event.note].filter(Boolean).join(' · ')}</p>}
              <p className="text-xs text-slate-500">{formatDateTime(event.created_at)}</p>
            </li>
          ))}
        </ol>
      )}
    </Card>
  );
}

function Address({ title, address }: { title: string; address: Record<string, unknown> | null }) {
  const t = useAdminT();
  const lines = addressLines(address);

  return (
    <div>
      <h3 className="text-xs font-medium uppercase tracking-wide text-slate-500">{t(title)}</h3>
      {lines.length === 0 ? <p className="mt-0.5 text-sm text-slate-600">{t('Not given')}</p> : <address className="mt-0.5 text-sm not-italic text-slate-900">{lines.map((line, index) => <span key={index} className="block">{line}</span>)}</address>}
    </div>
  );
}

// t('Confirm this order with the customer') t('A quick WhatsApp message or call before you pack cuts fake cash-on-delivery orders.') t('Pack and ship') t('Confirm with the customer if you have not yet, print the packing slip, then create the shipment.') t('Print the packing slip, pack the items, then create the shipment.') t('On its way') t('Let the customer know, and update the shipment when it is delivered.')
type Step = { tone: 'amber' | 'brand' | 'emerald' | 'slate'; icon: IconName; title: string; text: string };

/** What the order needs now, from its status (the server moves the status; this only reads it). */
function nextStep(order: Order): Step | null {
  const unpaid = order.payment_status !== 'paid';
  switch (order.status) {
    case 'pending_confirmation':
      return { tone: 'amber', icon: 'chat', title: 'Confirm this order with the customer', text: 'A quick WhatsApp message or call before you pack cuts fake cash-on-delivery orders.' };
    case 'confirmed':
    case 'processing':
    case 'ready_to_fulfill':
      return {
        tone: 'brand',
        icon: 'box',
        title: 'Pack and ship',
        text: unpaid ? 'Confirm with the customer if you have not yet, print the packing slip, then create the shipment.' : 'Print the packing slip, pack the items, then create the shipment.',
      };
    case 'fulfilling':
    case 'shipped':
      return { tone: 'emerald', icon: 'truck', title: 'On its way', text: 'Let the customer know, and update the shipment when it is delivered.' };
    default:
      return null;
  }
}

const STEP_TONES: Record<Step['tone'], string> = {
  amber: 'border-amber-200 bg-amber-50/70 [&_.step-icon]:bg-amber-100 [&_.step-icon]:text-amber-700',
  brand: 'border-brand-200 bg-brand-50/60 [&_.step-icon]:bg-brand-100 [&_.step-icon]:text-brand-700',
  emerald: 'border-emerald-200 bg-emerald-50/60 [&_.step-icon]:bg-emerald-100 [&_.step-icon]:text-emerald-700',
  slate: 'border-slate-200 bg-white [&_.step-icon]:bg-slate-100 [&_.step-icon]:text-slate-600',
};

function NextStepBar({ order, canShip, onCreateShipment }: { order: Order; canShip: boolean; onCreateShipment: () => void }) {
  const t = useAdminT();
  const auth = useAuth();
  const [writing, setWriting] = useState(false);
  const step = nextStep(order);
  if (!step) return null;
  const storeName = auth.activeStore?.name ?? '';
  const shipping = step.icon === 'box';
  const onItsWay = step.icon === 'truck';

  return (
    <section aria-label={t('Next step')} className={`rounded-2xl border p-4 sm:p-5 ${STEP_TONES[step.tone]}`}>
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex items-start gap-3">
          <span aria-hidden="true" className="step-icon flex h-10 w-10 flex-none items-center justify-center rounded-xl"><Icon name={step.icon} size={20} /></span>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{t('Next step')}</p>
            <h2 className="font-semibold text-slate-900">{t(step.title)}</h2>
            <p className="mt-0.5 text-sm text-slate-600">{t(step.text)}</p>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {onItsWay && orderPhone(order) ? (
            <Button onClick={() => setWriting(true)} className="!border-emerald-200 !text-emerald-800 hover:!bg-emerald-50"><Icon name="chat" size={16} />{t('Tell them on WhatsApp')}</Button>
          ) : (
            <ContactButtons order={order} storeName={storeName} />
          )}
          {(shipping || step.tone === 'amber') && (
            <a href={`/orders/${order.id}/print?print=1`} target="_blank" rel="noreferrer" className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 shadow-sm transition hover:-translate-y-px hover:bg-slate-50 motion-reduce:transform-none focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2">
              <Icon name="printer" size={16} />{t('Packing slip')}<span className="sr-only"> {t('(opens in a new tab)')}</span>
            </a>
          )}
          {shipping && canShip && <Button variant="primary" onClick={onCreateShipment}><Icon name="truck" size={16} />{t('Create shipment')}</Button>}
        </div>
      </div>
      {writing && <WhatsAppDialog order={order} storeName={storeName} initialKind="shipped" onClose={() => setWriting(false)} />}
    </section>
  );
}

export default function Show({ orderId }: { orderId: string }) {
  const access = useAccess();
  const auth = useAuth();
  const t = useAdminT();
  const state = useApi<{ data: Order }>(`/orders/${orderId}`);
  const [cancelling, setCancelling] = useState(false);
  const [version, setVersion] = useState(0);
  const [shipRequest, setShipRequest] = useState(0);
  const order = state.data?.data ?? null;

  const refresh = () => {
    state.reload();
    setVersion((v) => v + 1);
  };

  const itemColumns: Column<OrderItem>[] = [
    {
      key: 'product',
      header: 'Item',
      render: (item) => (
        <div>
          <span className="font-medium">{item.product_name}</span>
          {item.sku && <p className="font-mono text-xs text-slate-500">{item.sku}</p>}
        </div>
      ),
    },
    { key: 'quantity', header: 'Qty', align: 'right', priority: true, render: (item) => item.quantity },
    { key: 'price', header: 'Unit price', align: 'right', render: (item) => money(item.unit_price_minor, order?.currency) },
    { key: 'discount', header: 'Discount', align: 'right', render: (item) => (item.discount_minor > 0 ? `− ${money(item.discount_minor, order?.currency)}` : '—') },
    { key: 'fulfilment', header: 'Fulfilment', render: (item) => <StatusBadge status={item.fulfillment_status} /> },
    { key: 'total', header: 'Total', align: 'right', priority: true, render: (item) => <span className="font-medium">{money(item.line_total_minor, order?.currency)}</span> },
  ];

  // The statuses the server lets an order be cancelled from; it checks again.
  const cancellable = order !== null && ['draft', 'pending_confirmation', 'confirmed', 'processing', 'ready_to_fulfill'].includes(order.status);

  return (
    <AdminPage
      title={order ? t('Order {number}', { number: order.order_number }) : t('Order')}
      trail={[{ label: order?.order_number ?? 'Order' }]}
      description={order ? t('Placed {date} · {source}', { date: formatDateTime(order.created_at), source: t(humanize(order.source)) }) : undefined}
      actions={
        <>
          <ButtonLink href="/orders">{t('All orders')}</ButtonLink>
          {order && (
            <a href={`/orders/${order.id}/print`} target="_blank" rel="noreferrer" className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 shadow-sm transition hover:-translate-y-px hover:bg-slate-50 motion-reduce:transform-none focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2">
              <Icon name="printer" size={16} />{t('Print')}<span className="sr-only"> {t('packing slip (opens in a new tab)')}</span>
            </a>
          )}
          {order && access.can('orders.cancel') && cancellable && <Button variant="danger" onClick={() => setCancelling(true)}>{t('Cancel order')}</Button>}
        </>
      }
    >
      {state.error ? (
        state.errorStatus === 403 ? <AccessNotice message={state.error} /> : state.errorStatus === 404 ? (
          <EmptyPanel title="Order not found" description="It does not exist in this store." action={<ButtonLink href="/orders">Back to orders</ButtonLink>} />
        ) : <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : order === null ? (
        <Skeleton lines={6} />
      ) : (
        <div className="grid gap-4 lg:grid-cols-3">
          <div className="space-y-4 lg:col-span-2">
            <NextStepBar order={order} canShip={access.can('shipments.view') && access.can('shipments.fulfill')} onCreateShipment={() => setShipRequest((n) => n + 1)} />
            <Card>
              <Details
                items={[
                  { label: 'Status', value: <StatusBadge status={order.status} /> },
                  { label: 'Payment', value: <StatusBadge status={order.payment_status} /> },
                  { label: 'Fulfilment', value: <StatusBadge status={order.fulfillment_status} /> },
                  ...(order.return_status && order.return_status !== 'none' ? [{ label: 'Returns', value: <StatusBadge status={order.return_status} label={ORDER_RETURN_LABELS[order.return_status]} /> }] : []),
                  ...(order.replacement_for ? [{ label: 'Replaces', value: <Link href={`/orders/${order.replacement_for.id}`} className={`rounded font-mono text-brand-700 hover:underline ${FOCUS_RING}`}>{order.replacement_for.order_number}</Link> }] : []),
                  ...(order.cancellation_reason ? [{ label: 'Cancelled because', value: humanize(order.cancellation_reason) }] : []),
                ]}
              />
            </Card>

            <Card title="Items">
              <DataTable caption="Order items" columns={itemColumns} rows={order.items ?? []} rowKey={(item) => item.id} empty={<p className="text-sm text-slate-600">This order has no items.</p>} />
              <dl className="ms-auto mt-4 max-w-xs space-y-1 text-sm">
                <div className="flex justify-between"><dt className="text-slate-600">{t('Subtotal')}</dt><dd>{money(order.subtotal_minor, order.currency)}</dd></div>
                <div className="flex justify-between"><dt className="text-slate-600">{t('Discounts')}</dt><dd>− {money(order.discount_total_minor, order.currency)}</dd></div>
                {/* Phase B46: the tax as it was charged, rate by rate. */}
                {order.tax?.exempt ? (
                  <div className="flex justify-between"><dt className="text-slate-600">{order.tax.label}</dt><dd>Exempt ({order.tax.exemption_reference})</dd></div>
                ) : (
                  <div className="flex justify-between"><dt className="text-slate-600">{order.tax ? `${order.tax.label}${order.tax.prices_include_tax ? ` (${t('included in prices')})` : ''}` : t('Tax')}</dt><dd>{money(order.tax_total_minor, order.currency)}</dd></div>
                )}
                {(order.tax?.breakdown ?? []).map((b) => (
                  <div key={b.name} className="flex justify-between ps-3 text-xs text-slate-600"><dt>{b.name} {ratePercent(b.rate_bps)} % on {money(b.base_minor, order.currency)}</dt><dd>{money(b.tax_minor, order.currency)}</dd></div>
                ))}
                <div className="flex justify-between"><dt className="text-slate-600">{t('Shipping')}</dt><dd>{money(order.shipping_total_minor, order.currency)}</dd></div>
                <div className="flex justify-between border-t border-slate-200 pt-1 font-semibold"><dt>{t('Total')}</dt><dd>{money(order.grand_total_minor, order.currency)}</dd></div>
                {(order.store_credit_minor ?? 0) > 0 && (
                  <>
                    <div className="flex justify-between"><dt className="text-slate-600">Paid with store credit</dt><dd>− {money(order.store_credit_minor ?? 0, order.currency)}</dd></div>
                    <div className="flex justify-between font-semibold"><dt>Left to pay</dt><dd>{money(order.payable_minor ?? 0, order.currency)}</dd></div>
                  </>
                )}
              </dl>
            </Card>

            {access.can('payments.view') && <PaymentsCard order={order} onChanged={refresh} />}
            {access.can('shipments.view') && <ShipmentsCard order={order} onChanged={refresh} createRequest={shipRequest} />}
            {access.can('returns.view') && <ReturnsCard order={order} />}
          </div>

          <div className="space-y-4">
            <Card title="Customer">
              <div className="space-y-3">
                <div>
                  <p className="text-sm font-medium text-slate-900">
                    {order.customer && access.can('customers.view') ? (
                      <Link href={`/customers/${order.customer.id}`} className={`rounded text-brand-700 hover:underline ${FOCUS_RING}`}>{orderCustomer(order)}</Link>
                    ) : (
                      orderCustomer(order)
                    )}
                  </p>
                  <p className="text-sm text-slate-700">{order.customer?.email ?? order.guest_email ?? ''}</p>
                  {order.guest_phone && <p className="text-sm text-slate-700">{order.guest_phone}</p>}
                </div>
                {orderPhone(order) && <div className="flex flex-wrap gap-2"><ContactButtons order={order} storeName={auth.activeStore?.name ?? ''} size="sm" /></div>}
                <Address title="Ship to" address={order.shipping_address} />
                <Address title="Bill to" address={order.billing_address} />
                {order.notes && (
                  <div>
                    <h3 className="text-xs font-medium uppercase tracking-wide text-slate-500">{t('Note')}</h3>
                    <p className="mt-0.5 whitespace-pre-wrap text-sm text-slate-900">{order.notes}</p>
                  </div>
                )}
              </div>
            </Card>
            {/* Remounted (key) after a change, so it shows the new events. */}
            <Timeline key={version} orderId={order.id} />
          </div>
        </div>
      )}

      {order && cancelling && (
        <CancelDialog
          order={order}
          onClose={() => setCancelling(false)}
          onDone={() => {
            setCancelling(false);
            refresh();
          }}
        />
      )}
    </AdminPage>
  );
}
