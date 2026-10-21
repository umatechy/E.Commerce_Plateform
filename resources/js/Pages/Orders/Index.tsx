import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import RowMenu from '@/Components/ui/RowMenu';
import { WhatsAppDialog } from '@/Components/Orders/ContactCustomer';
import { orderPhone } from '@/lib/contact';
import { useAdminT } from '@/lib/adminI18n';
import SavedViews, { viewFilters, viewQuery } from '@/Components/ui/SavedViews';
import AdminPage from '@/Components/AdminPage';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Button, { ButtonLink, FOCUS_RING } from '@/Components/ui/Button';
import { SelectField } from '@/Components/ui/Form';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import { StatusBadge } from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { useAccess, useAuth } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import Icon from '@/Components/ui/Icon';
import { useUrlState } from '@/lib/useUrlState';
import { money } from '@/lib/money';
import { formatDateTime } from '@/lib/datetime';
import { options } from '@/lib/labels';
import { ORDER_PAYMENT_STATUSES, ORDER_STATUSES, orderCustomer, type Order } from '@/lib/orders';

/**
 * Module 09 "Admin Order View": the store's orders, newest first, one
 * server page at a time (GET /api/v1/orders). Search and the two status
 * filters are applied by the server and kept in the URL. Totals are the
 * server's; nothing is recalculated here.
 *
 * UI-15: each row's "⋯" menu reaches the customer (WhatsApp, call) and
 * prints the packing slip without opening the order.
 */
const FILTER_DEFAULTS = { search: '', status: '', payment_status: '', page: '1' };

/**
 * UI-3: the statuses a store looks at most, one click away (the Status filter still has them all).
 * UI-26: named by the work they wait for, each with how many orders it holds (GET /orders/counts).
 */
// t('All') t('To confirm') t('To pack') t('Packing') t('Shipped') t('Delivered') t('Cancelled')
const QUICK: { status: string; label: string; urgent?: boolean }[] = [
  { status: '', label: 'All' },
  { status: 'pending_confirmation', label: 'To confirm', urgent: true },
  { status: 'confirmed', label: 'To pack', urgent: true },
  { status: 'processing', label: 'Packing' },
  { status: 'shipped', label: 'Shipped' },
  { status: 'delivered', label: 'Delivered' },
  { status: 'cancelled', label: 'Cancelled' },
];

/** UI-26: orders waiting to be packed and sent; their row offers "Ship" (it opens the order with the shipment form). */
const TO_SHIP = ['confirmed', 'processing', 'ready_to_fulfill'];

export default function Index() {
  const access = useAccess();
  const auth = useAuth();
  const t = useAdminT();
  const [writingTo, setWritingTo] = useState<Order | null>(null);
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const list = usePagedApi<Order>('/orders', { search: filters.search, status: filters.status, payment_status: filters.payment_status, page: filters.page });
  const filtered = filters.search !== '' || filters.status !== '' || filters.payment_status !== '';
  const counts = useApi<{ data: { all: number; by_status: Record<string, number> } }>('/orders/counts');
  const countOf = (status: string): number | null => (counts.data ? (status === '' ? counts.data.data.all : counts.data.data.by_status[status] ?? 0) : null);
  const canShip = access.can('shipments.view') && access.can('shipments.fulfill');

  const columns: Column<Order>[] = [
    {
      key: 'number',
      header: 'Order',
      render: (order) => (
        <div>
          <Link href={`/orders/${order.id}`} className={`rounded font-mono font-medium text-brand-700 hover:underline ${FOCUS_RING}`}>
            {order.order_number}
          </Link>
          <p className="text-xs text-slate-500">{formatDateTime(order.created_at)}</p>
        </div>
      ),
    },
    {
      key: 'customer',
      header: 'Customer',
      render: (order) => (
        <div>
          <span>{orderCustomer(order)}</span>
          {orderPhone(order) && <p className="font-mono text-xs text-slate-500">{orderPhone(order)}</p>}
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (order) => <StatusBadge status={order.status} /> },
    { key: 'payment', header: 'Payment', render: (order) => <StatusBadge status={order.payment_status} /> },
    { key: 'fulfilment', header: 'Fulfilment', render: (order) => <StatusBadge status={order.fulfillment_status} /> },
    { key: 'total', header: 'Total', align: 'right', priority: true, render: (order) => <span className="font-medium">{money(order.grand_total_minor, order.currency)}</span> },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'right',
      priority: true,
      render: (order) => {
        const phone = orderPhone(order);

        return (
          <div className="flex items-center justify-end gap-1">
            {canShip && TO_SHIP.includes(order.status) && (
              <ButtonLink href={`/orders/${order.id}?ship=1`} size="sm" variant="soft">
                <Icon name="truck" size={14} />{t('Ship')}<span className="sr-only"> {order.order_number}</span>
              </ButtonLink>
            )}
          <RowMenu
            label={t('Actions for {name}', { name: order.order_number })}
            actions={[
              { label: t('Open order'), icon: 'arrowRight', onSelect: () => router.visit(`/orders/${order.id}`) },
              ...(phone
                ? [
                    { label: t('Message on WhatsApp'), icon: 'chat' as const, onSelect: () => setWritingTo(order) },
                    { label: t('Call the customer'), icon: 'call' as const, onSelect: () => window.location.assign(`tel:${phone.replace(/[^\d+]/g, '')}`) },
                  ]
                : []),
              { label: t('Print packing slip'), icon: 'printer', onSelect: () => window.open(`/orders/${order.id}/print?print=1`, '_blank', 'noopener') },
            ]}
          />
          </div>
        );
      },
    },
  ];
  return (
    <AdminPage
      title="Orders"
      description="Every order of your store. Open one to see its items, payments and deliveries."
      actions={access.can('orders.create') && <ButtonLink href="/orders/new" variant="primary">{t('Create order')}</ButtonLink>}
    >
      <nav aria-label={t('Order status')} className="relative -mx-1 mb-3 flex gap-1 overflow-x-auto px-1 pb-1">
        {QUICK.map((q) => {
          const active = filters.status === q.status;
          const count = countOf(q.status);
          const waiting = q.urgent && count !== null && count > 0;

          return (
            <button
              key={q.label}
              type="button"
              aria-pressed={active}
              onClick={() => setFilters({ status: q.status, page: '1' })}
              className={`inline-flex items-center gap-2 whitespace-nowrap rounded-full py-1.5 pe-2 ps-3.5 text-sm font-medium transition-colors motion-reduce:transition-none ${FOCUS_RING} ${active ? 'tone-solid shadow-sm' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 hover:text-slate-900'} ${count === null ? 'pe-3.5' : ''}`}
            >
              {t(q.label)}
              {count !== null && (
                <span className={`min-w-6 rounded-full px-1.5 py-0.5 text-center text-xs font-semibold tabular-nums ${active ? 'bg-white/25 text-white' : waiting ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>{count}</span>
              )}
            </button>
          );
        })}
      </nav>
      <SavedViews list="orders" query={viewQuery(filters, FILTER_DEFAULTS)} onApply={(query) => setFilters(viewFilters(query, FILTER_DEFAULTS) as typeof FILTER_DEFAULTS)} />
      <FilterBar>
        <SearchField label="Search" placeholder="Order number, guest name or email" value={filters.search} onChange={(search) => setFilters({ search, page: '1' })} />
        <div className="w-48">
          <SelectField label="Status" value={filters.status} onChange={(status) => setFilters({ status, page: '1' })} options={options(ORDER_STATUSES)} placeholder="Any status" />
        </div>
        <div className="w-48">
          <SelectField label="Payment" value={filters.payment_status} onChange={(payment_status) => setFilters({ payment_status, page: '1' })} options={options(ORDER_PAYMENT_STATUSES)} placeholder="Any payment status" />
        </div>
        {filtered && <Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filters</Button>}
      </FilterBar>

      <DataTable
        caption="Orders"
        columns={columns}
        rows={list.rows}
        rowKey={(order) => order.id}
        onRowClick={(order) => router.visit(`/orders/${order.id}`)}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={
          filtered ? (
            <EmptyPanel title="No orders match" description="Try another search or status." action={<Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filters</Button>} />
          ) : (
            <EmptyPanel title="No orders yet" description="Orders appear here once customers start buying." />
          )
        }
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />
      {writingTo && <WhatsAppDialog order={writingTo} storeName={auth.activeStore?.name ?? ''} onClose={() => setWritingTo(null)} />}
    </AdminPage>
  );
}
