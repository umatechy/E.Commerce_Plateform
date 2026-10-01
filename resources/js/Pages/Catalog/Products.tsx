import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Button, { ButtonLink, FOCUS_RING } from '@/Components/ui/Button';
import { SelectField } from '@/Components/ui/Form';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import { StatusBadge } from '@/Components/ui/Badge';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { EmptyPanel, UsageMeter } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { options } from '@/lib/labels';
import type { Product } from '@/lib/catalog';
import { PRODUCT_STATUSES } from '@/lib/catalog';

/**
 * Module 06 §64 "Product Listing Admin UX": the store's products, one
 * server page at a time, searched and filtered by the server
 * (GET /api/v1/products). The filters live in the URL.
 */
const FILTER_DEFAULTS = { search: '', status: '', page: '1' };

export default function Products() {
  const access = useAccess();
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const list = usePagedApi<Product>('/products', { search: filters.search, status: filters.status, page: filters.page });
  const usage = useApi<{ data: Record<string, { limit: number | null; current: number; unlimited: boolean }> }>('/subscription/usage');
  const [removing, setRemoving] = useState<Product | null>(null);
  const { busy, run } = useAction();

  const limit = usage.data?.data.max_products;
  const filtered = filters.search !== '' || filters.status !== '';

  const columns: Column<Product>[] = [
    {
      key: 'name',
      header: 'Product',
      render: (product) => (
        <div>
          <Link href={`/products/${product.id}`} className={`rounded font-medium text-indigo-700 hover:underline ${FOCUS_RING}`}>
            {product.name}
          </Link>
          {product.sku && <p className="font-mono text-xs text-slate-500">{product.sku}</p>}
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (product) => <StatusBadge status={product.status} /> },
    { key: 'price', header: 'Price', align: 'right', render: (product) => (product.effective_price_minor === null ? '—' : money(product.effective_price_minor, product.currency ?? access.currency)) },
    { key: 'variants', header: 'Variants', align: 'right', render: (product) => product.variants?.length ?? 0 },
    { key: 'brand', header: 'Brand', render: (product) => product.brand?.name ?? '—' },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'right',
      priority: true,
      render: (product) =>
        access.can('products.delete') && (
          <Button size="sm" variant="ghost" onClick={() => setRemoving(product)}>
            Delete<span className="sr-only"> {product.name}</span>
          </Button>
        ),
    },
  ];

  return (
    <AdminPage
      title="Products"
      description="What you sell: names, prices, variants and images."
      actions={access.can('products.create') && <ButtonLink href="/products/new" variant="primary">Add product</ButtonLink>}
    >
      {limit && !limit.unlimited && (
        <div className="mb-4 rounded-lg border border-slate-200 bg-white p-3">
          <UsageMeter label="Products on your package" current={limit.current} limit={limit.limit} />
        </div>
      )}

      <FilterBar>
        <SearchField label="Search" placeholder="Name or SKU" value={filters.search} onChange={(search) => setFilters({ search, page: '1' })} />
        <div className="w-44">
          <SelectField label="Status" value={filters.status} onChange={(status) => setFilters({ status, page: '1' })} options={options(PRODUCT_STATUSES)} placeholder="Any status" />
        </div>
        {filtered && <Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filters</Button>}
      </FilterBar>

      <DataTable
        caption="Products"
        columns={columns}
        rows={list.rows}
        rowKey={(product) => product.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={
          filtered ? (
            <EmptyPanel title="No products match" description="Try another search or status." action={<Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filters</Button>} />
          ) : (
            <EmptyPanel
              title="No products yet"
              description="Add your first product to start selling."
              action={access.can('products.create') ? <ButtonLink href="/products/new" variant="primary">Add product</ButtonLink> : undefined}
            />
          )
        }
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />

      <ConfirmDialog
        open={removing !== null}
        title="Delete this product?"
        confirmLabel="Delete product"
        busy={busy === 'delete'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/products/${removing.id}`, { method: 'DELETE' }), { success: 'Product deleted.' }).then((result) => {
            if (result !== undefined) {
              setRemoving(null);
              list.reload();
              usage.reload();
            }
          })
        }
      >
        <p>
          <strong>{removing?.name}</strong> will be removed from your catalog and your storefront. Orders that already contain it keep their record of it.
        </p>
        <p>This cannot be undone from the admin.</p>
      </ConfirmDialog>
    </AdminPage>
  );
}
