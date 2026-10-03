import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import { CheckboxField, SelectField } from '@/Components/ui/Form';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import { StatusBadge } from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { money } from '@/lib/money';
import { formatDateTime } from '@/lib/datetime';
import { options } from '@/lib/labels';
import { RESOLUTION_LABELS, RETURN_STATUSES, RETURN_STATUS_LABELS, type ReturnRecord } from '@/lib/returns';

/**
 * Module 09 §45–54 (Phase B33, gap G8): the store's returns
 * (/api/v1/returns). A return is opened from its order (Orders → the
 * order → Returns) or by the customer in their account, when the store
 * allows that in Settings.
 */
const FILTER_DEFAULTS = { search: '', status: '', open: '', page: '1' };

export default function Index() {
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const list = usePagedApi<ReturnRecord>('/returns', { search: filters.search, status: filters.status, open: filters.open === '1' ? 1 : undefined, page: filters.page });
  const filtered = filters.search !== '' || filters.status !== '' || filters.open !== '';

  const columns: Column<ReturnRecord>[] = [
    {
      key: 'number',
      header: 'Return',
      render: (record) => (
        <Link href={`/returns/${record.id}`} className={`rounded font-mono text-indigo-700 hover:underline ${FOCUS_RING}`}>{record.return_number}</Link>
      ),
    },
    {
      key: 'order',
      header: 'Order',
      render: (record) => record.order ? (
        <div>
          <Link href={`/orders/${record.order.id}`} className={`rounded font-mono text-indigo-700 hover:underline ${FOCUS_RING}`}>{record.order.order_number}</Link>
          {record.order.customer_name && <p className="text-xs text-slate-600">{record.order.customer_name}</p>}
        </div>
      ) : '—',
    },
    { key: 'status', header: 'Status', priority: true, render: (record) => <StatusBadge status={record.status} label={RETURN_STATUS_LABELS[record.status]} /> },
    { key: 'wants', header: 'Wants', render: (record) => RESOLUTION_LABELS[record.resolution]?.split(' (')[0] ?? record.resolution },
    { key: 'units', header: 'Units', align: 'right', render: (record) => (record.items ?? []).reduce((sum, item) => sum + item.quantity, 0) },
    { key: 'refund', header: 'Refunded', align: 'right', render: (record) => (record.refunded_minor > 0 ? money(record.refunded_minor, record.currency) : '—') },
    { key: 'asked', header: 'Asked', render: (record) => formatDateTime(record.created_at) },
  ];

  return (
    <AdminPage title="Returns" description="Items customers send back: approve, receive, inspect, then refund or replace.">
      <FilterBar>
        <SearchField label="Search" placeholder="Return or order number" value={filters.search} onChange={(search) => setFilters({ search, page: '1' })} />
        <div className="w-64">
          <SelectField label="Status" value={filters.status} onChange={(status) => setFilters({ status, page: '1' })} placeholder="Any" options={options(RETURN_STATUSES, RETURN_STATUS_LABELS)} />
        </div>
        <CheckboxField label="Only open returns" checked={filters.open === '1'} onChange={(open) => setFilters({ open: open ? '1' : '', page: '1' })} />
        {filtered && <Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filters</Button>}
      </FilterBar>

      <DataTable
        caption="Returns"
        columns={columns}
        rows={list.rows}
        rowKey={(record) => record.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={
          filtered ? (
            <EmptyPanel title="No returns match" action={<Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filters</Button>} />
          ) : (
            <EmptyPanel title="No returns yet" description="To record one, open the delivered order and choose “Start a return”. Customers can ask themselves once you allow it in Settings." />
          )
        }
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />
    </AdminPage>
  );
}
