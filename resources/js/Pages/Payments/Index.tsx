import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import { SelectField } from '@/Components/ui/Form';
import { FilterBar } from '@/Components/ui/Filters';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import PaymentDrawer from '@/Components/Orders/PaymentDialogs';
import { usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { money } from '@/lib/money';
import { dateTimeOrDash } from '@/lib/datetime';
import { options } from '@/lib/labels';
import { PAYMENT_METHOD_LABELS, PAYMENT_STATUSES, type Payment } from '@/lib/orders';

/**
 * Module 12 §68 "Store Admin Payment View": the store's payments
 * (GET /api/v1/payments). Only what the backend has is shown: the three
 * payment methods of Phase B7. No payment provider is connected, and
 * this page asks for no provider credentials.
 */
const FILTER_DEFAULTS = { status: '', page: '1' };

export default function Index() {
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const list = usePagedApi<Payment>('/payments', { status: filters.status, page: filters.page });
  const [open, setOpen] = useState<string | null>(null);

  const columns: Column<Payment>[] = [
    {
      key: 'order',
      header: 'Order',
      render: (payment) =>
        payment.order ? (
          <Link href={`/orders/${payment.order.id}`} className={`rounded font-mono text-indigo-700 hover:underline ${FOCUS_RING}`}>
            {payment.order.order_number}
          </Link>
        ) : '—',
    },
    { key: 'created', header: 'Created', render: (payment) => dateTimeOrDash(payment.created_at) },
    { key: 'method', header: 'Method', render: (payment) => PAYMENT_METHOD_LABELS[payment.method] ?? humanize(payment.method) },
    { key: 'status', header: 'Status', priority: true, render: (payment) => <StatusBadge status={payment.status} /> },
    { key: 'amount', header: 'Amount', align: 'right', priority: true, render: (payment) => <span className="font-medium">{money(payment.amount_minor, payment.currency)}</span> },
    { key: 'open', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (payment) => <Button size="sm" variant="ghost" onClick={() => setOpen(payment.id)}>Open</Button> },
  ];

  return (
    <AdminPage title="Payments" description="Payments for your orders. Open one to see its transactions, record a payment received, or refund.">
      <FilterBar>
        <div className="w-52">
          <SelectField label="Status" value={filters.status} onChange={(status) => setFilters({ status, page: '1' })} options={options(PAYMENT_STATUSES)} placeholder="Any status" />
        </div>
        {filters.status !== '' && <Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filter</Button>}
      </FilterBar>

      <DataTable
        caption="Payments"
        columns={columns}
        rows={list.rows}
        rowKey={(payment) => payment.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={filters.status !== '' ? <EmptyPanel title="No payments with this status" action={<Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filter</Button>} /> : <EmptyPanel title="No payments yet" description="A payment appears when a customer checks out." />}
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />

      {open && <PaymentDrawer paymentId={open} onClose={() => setOpen(null)} onChanged={list.reload} />}
    </AdminPage>
  );
}
