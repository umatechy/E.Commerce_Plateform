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
import type { BulkResult, Product } from '@/lib/catalog';
import { PRODUCT_STATUSES } from '@/lib/catalog';
import BulkBar from '@/Components/Catalog/BulkBar';

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
  // Phase B39 (Module 06 §46): products chosen for one change, on this page.
  const [selected, setSelected] = useState<string[]>([]);
  const [report, setReport] = useState<BulkResult | null>(null);
  const canBulk = access.can('products.update') || access.can('products.delete');
  const pageIds = (list.rows ?? []).map((product) => product.id);
  const allOnPage = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));

  const limit = usage.data?.data.max_products;
  const filtered = filters.search !== '' || filters.status !== '';

  const columns: Column<Product>[] = [
    ...(canBulk
      ? [{
          key: 'select', header: 'Choose', srOnlyHeader: true, priority: true,
          render: (product: Product) => (
            <input
              type="checkbox"
              aria-label={`Choose ${product.name}`}
              checked={selected.includes(product.id)}
              onChange={(e) => {
                const checked = e.target.checked;
                setSelected((current) => (checked ? [...current, product.id] : current.filter((id) => id !== product.id)));
              }}
              className={`h-4 w-4 rounded border-slate-300 text-indigo-600 ${FOCUS_RING}`}
            />
          ),
        } satisfies Column<Product>]
      : []),
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
      render: (product) => (
        <span className="flex justify-end gap-1">
          {/* Phase B39 (Module 06 §60): a hidden draft copy. */}
          {access.can('products.create') && (
            <Button
              size="sm"
              variant="ghost"
              busy={busy === `copy-${product.id}`}
              onClick={() =>
                run(`copy-${product.id}`, () => adminFetch(`/products/${product.id}/duplicate`, { method: 'POST' }), { success: 'Copy created as a hidden draft.' }).then((copy) => {
                  if (copy !== undefined) {
                    list.reload();
                    usage.reload();
                  }
                })
              }
            >
              Duplicate<span className="sr-only"> {product.name}</span>
            </Button>
          )}
          {access.can('products.delete') && (
            <Button size="sm" variant="ghost" onClick={() => setRemoving(product)}>
              Delete<span className="sr-only"> {product.name}</span>
            </Button>
          )}
        </span>
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

      {canBulk && (list.rows ?? []).length > 0 && (
        <div className="mb-2 flex flex-wrap items-center gap-3">
          <Button size="sm" onClick={() => setSelected(allOnPage ? selected.filter((id) => !pageIds.includes(id)) : [...new Set([...selected, ...pageIds])])}>
            {allOnPage ? 'Unchoose this page' : 'Choose all on this page'}
          </Button>
        </div>
      )}
      {selected.length > 0 && (
        <BulkBar
          selected={selected}
          currency={access.currency}
          canCollections={access.can('collections.manage')}
          onClear={() => setSelected([])}
          onDone={(result) => {
            setReport(result);
            setSelected([]);
            list.reload();
            usage.reload();
          }}
        />
      )}
      {report && (
        <div role="status" className="mb-4 rounded-lg border border-slate-200 bg-white p-3 text-sm">
          <div className="flex items-start justify-between gap-2">
            <p className="font-medium">
              {report.affected} changed{report.skipped.length > 0 ? `, ${report.skipped.length} not changed` : ''}.
            </p>
            <Button size="sm" variant="ghost" onClick={() => setReport(null)}>Dismiss</Button>
          </div>
          {report.skipped.length > 0 && (
            <ul className="mt-2 list-disc space-y-1 ps-5 text-slate-700">
              {report.skipped.map((s) => <li key={s.id}><strong>{s.name || s.id}</strong>: {s.reason}</li>)}
            </ul>
          )}
        </div>
      )}

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
