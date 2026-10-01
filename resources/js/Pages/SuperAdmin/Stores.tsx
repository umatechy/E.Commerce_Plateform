import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import { FOCUS_RING } from '@/Components/ui/Button';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import { StatusBadge } from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { formatDate } from '@/lib/datetime';

/**
 * Module 30 store administration: every store on the platform
 * (GET /api/v1/super-admin/stores), searched by the server. Platform
 * staff only. Opening a store's details is recorded in the audit trail
 * by the server (cross-tenant access, ADR-001 Layer 7).
 */
type Store = { id: number; name: string; slug: string; status: string; created_at: string };

export default function Stores() {
  const [filters, setFilters] = useUrlState({ search: '', page: '1' });
  const list = usePagedApi<Store>('/super-admin/stores', { search: filters.search, page: filters.page });

  const columns: Column<Store>[] = [
    {
      key: 'name',
      header: 'Store',
      render: (store) => (
        <div>
          <Link href={`/super-admin/stores/${store.id}`} className={`rounded font-medium text-indigo-700 hover:underline ${FOCUS_RING}`}>
            {store.name}
          </Link>
          <p className="font-mono text-xs text-slate-500">{store.slug}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (store) => <StatusBadge status={store.status} /> },
    { key: 'created', header: 'Created', render: (store) => formatDate(store.created_at, 'UTC') },
  ];

  return (
    <AdminPage title="Stores" description="Every store on the platform. Dates are UTC.">
      <FilterBar>
        <SearchField label="Search" placeholder="Store name or address" value={filters.search} onChange={(search) => setFilters({ search, page: '1' })} />
      </FilterBar>
      <DataTable caption="Stores" columns={columns} rows={list.rows} rowKey={(store) => store.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title={filters.search ? 'No store matches' : 'No stores yet'} />} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />
    </AdminPage>
  );
}
