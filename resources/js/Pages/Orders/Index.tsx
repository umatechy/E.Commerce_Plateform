import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Button, { ButtonLink, FOCUS_RING } from '@/Components/ui/Button';
import { SelectField } from '@/Components/ui/Form';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import { StatusBadge } from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { usePagedApi } from '@/lib/useApi';
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
 */
const FILTER_DEFAULTS = { search: '', status: '', payment_status: '', page: '1' };

export default function Index() {
  const access = useAccess();
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const list = usePagedApi<Order>('/orders', { search: filters.search, status: filters.status, payment_status: filters.payment_status, page: filters.page });
  const filtered = filters.search !== '' || filters.status !== '' || filters.payment_status !== '';

  const columns: Column<Order>[] = [
    {
      key: 'number',
      header: 'Order',
      render: (order) => (
        <div>
          <Link href={`/orders/${order.id}`} className={`rounded font-mono font-medium text-indigo-700 hover:underline ${FOCUS_RING}`}>
            {order.order_number}
          </Link>
          <p className="text-xs text-slate-500">{formatDateTime(order.created_at)}</p>
        </div>
      ),
    },
    { key: 'customer', header: 'Customer', render: (order) => orderCustomer(order) },
    { key: 'status', header: 'Status', priority: true, render: (order) => <StatusBadge status={order.status} /> },
    { key: 'payment', header: 'Payment', render: (order) => <StatusBadge status={order.payment_status} /> },
    { key: 'fulfilment', header: 'Fulfilment', render: (order) => <StatusBadge status={order.fulfillment_status} /> },
    { key: 'total', header: 'Total', align: 'right', priority: true, render: (order) => <span className="font-medium">{money(order.grand_total_minor, order.currency)}</span> },
  ];

  return (
    <AdminPage
      title="Orders"
      description="Every order of your store. Open one to see its items, payments and deliveries."
      actions={access.can('orders.create') && <ButtonLink href="/orders/new" variant="primary">Create order</ButtonLink>}
    >
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
    </AdminPage>
  );
}
