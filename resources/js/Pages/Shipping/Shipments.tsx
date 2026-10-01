import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import { SelectField } from '@/Components/ui/Form';
import { FilterBar } from '@/Components/ui/Filters';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { ShipmentDrawer } from '@/Components/Orders/ShipmentDialogs';
import { usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { dateTimeOrDash } from '@/lib/datetime';
import { options } from '@/lib/labels';
import { CARRIER_LABELS, SHIPMENT_STATUSES, type Shipment } from '@/lib/orders';

/**
 * Module 13 §75 "Admin Shipping View": the store's shipments
 * (GET /api/v1/shipments). A shipment is created from its order's page.
 */
const FILTER_DEFAULTS = { status: '', page: '1' };

export default function Shipments() {
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const list = usePagedApi<Shipment>('/shipments', { status: filters.status, page: filters.page });
  const [open, setOpen] = useState<string | null>(null);

  const columns: Column<Shipment>[] = [
    {
      key: 'order',
      header: 'Order',
      render: (shipment) =>
        shipment.order ? (
          <Link href={`/orders/${shipment.order.id}`} className={`rounded font-mono text-indigo-700 hover:underline ${FOCUS_RING}`}>
            {shipment.order.order_number}
          </Link>
        ) : '—',
    },
    { key: 'created', header: 'Created', render: (shipment) => dateTimeOrDash(shipment.created_at) },
    { key: 'carrier', header: 'Carrier', render: (shipment) => CARRIER_LABELS[shipment.carrier] ?? humanize(shipment.carrier) },
    { key: 'tracking', header: 'Tracking', render: (shipment) => shipment.tracking_number ?? '—' },
    { key: 'status', header: 'Status', priority: true, render: (shipment) => <StatusBadge status={shipment.status} /> },
    { key: 'open', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (shipment) => <Button size="sm" variant="ghost" onClick={() => setOpen(shipment.id)}>Open</Button> },
  ];

  return (
    <AdminPage title="Shipments" description="Deliveries of your orders. Open one to see its tracking or update its status.">
      <FilterBar>
        <div className="w-52">
          <SelectField label="Status" value={filters.status} onChange={(status) => setFilters({ status, page: '1' })} options={options(SHIPMENT_STATUSES)} placeholder="Any status" />
        </div>
        {filters.status !== '' && <Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filter</Button>}
      </FilterBar>

      <DataTable
        caption="Shipments"
        columns={columns}
        rows={list.rows}
        rowKey={(shipment) => shipment.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={filters.status !== '' ? <EmptyPanel title="No shipments with this status" action={<Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filter</Button>} /> : <EmptyPanel title="No shipments yet" description="Create a shipment from an order's page when you send it out." />}
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />

      {open && <ShipmentDrawer shipmentId={open} onClose={() => setOpen(null)} onChanged={list.reload} />}
    </AdminPage>
  );
}
