import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink, FOCUS_RING } from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField } from '@/Components/ui/Form';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { AccessNotice, Card, Details, EmptyPanel, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import PaymentDrawer from '@/Components/Orders/PaymentDialogs';
import { CreateShipmentDialog, ShipmentDrawer } from '@/Components/Orders/ShipmentDialogs';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { formatDateTime } from '@/lib/datetime';
import { options } from '@/lib/labels';
import { addressLines, CANCELLATION_REASONS, CARRIER_LABELS, orderCustomer, PAYMENT_METHOD_LABELS, type Order, type OrderItem, type Payment, type Shipment, type TimelineEvent } from '@/lib/orders';

/**
 * Module 09 §37 "Admin Order View": one order with its items, totals,
 * customer, payments, shipments and timeline.
 *
 * The order's status is moved by the server's state machine, through
 * payments, shipments and cancellation. This page has no "set status"
 * control because the backend has none. Cancelling is offered; whether
 * it is allowed is the server's answer.
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

function ShipmentsCard({ order, onChanged }: { order: Order; onChanged: () => void }) {
  const access = useAccess();
  const list = usePagedApi<Shipment>('/shipments', { order: order.id });
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
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
  const state = useApi<{ data: TimelineEvent[] }>(`/orders/${orderId}/timeline`);

  return (
    <Card title="Timeline">
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : state.data === null ? (
        <Skeleton lines={3} />
      ) : state.data.data.length === 0 ? (
        <p className="text-sm text-slate-600">No events yet.</p>
      ) : (
        <ol className="space-y-3 text-sm">
          {state.data.data.map((event, index) => (
            <li key={`${event.created_at}-${index}`} className="border-l-2 border-slate-200 pl-3">
              <p className="font-medium text-slate-900">
                {humanize(event.event_type)}
                {event.to_status && <span className="font-normal text-slate-600"> → {humanize(event.to_status)}</span>}
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
  const lines = addressLines(address);

  return (
    <div>
      <h3 className="text-xs font-medium uppercase tracking-wide text-slate-500">{title}</h3>
      {lines.length === 0 ? <p className="mt-0.5 text-sm text-slate-600">Not given</p> : <address className="mt-0.5 text-sm not-italic text-slate-900">{lines.map((line, index) => <span key={index} className="block">{line}</span>)}</address>}
    </div>
  );
}

export default function Show({ orderId }: { orderId: string }) {
  const access = useAccess();
  const state = useApi<{ data: Order }>(`/orders/${orderId}`);
  const [cancelling, setCancelling] = useState(false);
  const [version, setVersion] = useState(0);
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
      title={order ? `Order ${order.order_number}` : 'Order'}
      trail={[{ label: order?.order_number ?? 'Order' }]}
      description={order ? `Placed ${formatDateTime(order.created_at)} · ${humanize(order.source)}` : undefined}
      actions={
        <>
          <ButtonLink href="/orders">All orders</ButtonLink>
          {order && access.can('orders.cancel') && cancellable && <Button variant="danger" onClick={() => setCancelling(true)}>Cancel order</Button>}
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
            <Card>
              <Details
                items={[
                  { label: 'Status', value: <StatusBadge status={order.status} /> },
                  { label: 'Payment', value: <StatusBadge status={order.payment_status} /> },
                  { label: 'Fulfilment', value: <StatusBadge status={order.fulfillment_status} /> },
                  ...(order.cancellation_reason ? [{ label: 'Cancelled because', value: humanize(order.cancellation_reason) }] : []),
                ]}
              />
            </Card>

            <Card title="Items">
              <DataTable caption="Order items" columns={itemColumns} rows={order.items ?? []} rowKey={(item) => item.id} empty={<p className="text-sm text-slate-600">This order has no items.</p>} />
              <dl className="ml-auto mt-4 max-w-xs space-y-1 text-sm">
                <div className="flex justify-between"><dt className="text-slate-600">Subtotal</dt><dd>{money(order.subtotal_minor, order.currency)}</dd></div>
                <div className="flex justify-between"><dt className="text-slate-600">Discounts</dt><dd>− {money(order.discount_total_minor, order.currency)}</dd></div>
                <div className="flex justify-between"><dt className="text-slate-600">Tax</dt><dd>{money(order.tax_total_minor, order.currency)}</dd></div>
                <div className="flex justify-between"><dt className="text-slate-600">Shipping</dt><dd>{money(order.shipping_total_minor, order.currency)}</dd></div>
                <div className="flex justify-between border-t border-slate-200 pt-1 font-semibold"><dt>Total</dt><dd>{money(order.grand_total_minor, order.currency)}</dd></div>
              </dl>
            </Card>

            {access.can('payments.view') && <PaymentsCard order={order} onChanged={refresh} />}
            {access.can('shipments.view') && <ShipmentsCard order={order} onChanged={refresh} />}
          </div>

          <div className="space-y-4">
            <Card title="Customer">
              <div className="space-y-3">
                <div>
                  <p className="text-sm font-medium text-slate-900">
                    {order.customer && access.can('customers.view') ? (
                      <Link href={`/customers/${order.customer.id}`} className={`rounded text-indigo-700 hover:underline ${FOCUS_RING}`}>{orderCustomer(order)}</Link>
                    ) : (
                      orderCustomer(order)
                    )}
                  </p>
                  <p className="text-sm text-slate-700">{order.customer?.email ?? order.guest_email ?? ''}</p>
                  {order.guest_phone && <p className="text-sm text-slate-700">{order.guest_phone}</p>}
                </div>
                <Address title="Ship to" address={order.shipping_address} />
                <Address title="Bill to" address={order.billing_address} />
                {order.notes && (
                  <div>
                    <h3 className="text-xs font-medium uppercase tracking-wide text-slate-500">Note</h3>
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
