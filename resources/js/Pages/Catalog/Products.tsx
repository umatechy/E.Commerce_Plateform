import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
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
import { adminErrorMessage, adminFetch, idempotencyKey } from '@/lib/adminApi';
import { fromMinor, money, toMinor } from '@/lib/money';
import InlineEdit from '@/Components/ui/InlineEdit';
import SavedViews, { viewFilters, viewQuery } from '@/Components/ui/SavedViews';
import { useAdminT } from '@/lib/adminI18n';
import { options } from '@/lib/labels';
import type { BulkResult, Product } from '@/lib/catalog';
import { PRODUCT_STATUSES } from '@/lib/catalog';
import BulkBar from '@/Components/Catalog/BulkBar';
import ProductImportDialog from '@/Components/Catalog/ProductImportDialog';
import { toast } from '@/Components/ui/toast';
import { downloadFile } from '@/lib/download';
import RowMenu from '@/Components/ui/RowMenu';
import Icon from '@/Components/ui/Icon';

/**
 * Module 06 §64 "Product Listing Admin UX": the store's products, one
 * server page at a time, searched and filtered by the server
 * (GET /api/v1/products). The filters live in the URL.
 *
 * UI-21: the price of a simple product and its stock (in its one warehouse)
 * change right in the list; named filters can be saved as views.
 */
const FILTER_DEFAULTS = { search: '', status: '', page: '1' };

export default function Products() {
  const access = useAccess();
  const t = useAdminT();
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const list = usePagedApi<Product>('/products', { search: filters.search, status: filters.status, page: filters.page });
  const usage = useApi<{ data: Record<string, { limit: number | null; current: number; unlimited: boolean }> }>('/subscription/usage');
  const [removing, setRemoving] = useState<Product | null>(null);
  const { busy, run } = useAction();
  // Phase B39 (Module 06 §46): products chosen for one change, on this page.
  const [selected, setSelected] = useState<string[]>([]);
  // Phase B40 (Module 06 §48–51): CSV import and export (the export takes the list's filters).
  const [importing, setImporting] = useState(false);
  const [exporting, setExporting] = useState(false);
  const canImport = access.can('products.create') || access.can('products.update');
  const [report, setReport] = useState<BulkResult | null>(null);
  const canBulk = access.can('products.update') || access.can('products.delete');
  const pageIds = (list.rows ?? []).map((product) => product.id);
  const allOnPage = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));

  const limit = usage.data?.data.max_products;

  /** Saves a simple product's price from the list. */
  async function savePrice(product: Product, text: string): Promise<boolean> {
    const currency = product.currency ?? access.currency;
    const minor = toMinor(text, currency);
    if (minor === null || minor < 0) {
      toast.error(t('Enter a price such as 2500 ({currency}).', { currency }));

      return false;
    }
    try {
      await adminFetch(`/products/${product.id}`, { method: 'PUT', body: { price_minor: minor } });
      toast.success(t('Price saved.'));
      list.reload();

      return true;
    } catch (e) {
      toast.error(adminErrorMessage(e));

      return false;
    }
  }

  /**
   * Sets a product's stock available to sell, from the list: in its one stock
   * record (creating it in the default warehouse with opening stock when there is
   * none). With stock in several warehouses the Inventory page does it.
   */
  async function saveStock(product: Product, text: string): Promise<boolean> {
    const wanted = Number(text);
    if (text === '' || !Number.isInteger(wanted) || wanted < 0) {
      toast.error(t('Enter the stock as a whole number, or leave it empty.'));

      return false;
    }
    try {
      const records = (await adminFetch<{ data: { id: string; on_hand: number; reserved: number }[] }>('/inventory', { query: { product: product.internal_id } })).data;
      if (records.length > 1) {
        toast.warning(t('This product has stock in several warehouses. Change it on the Inventory page.'));

        return false;
      }
      if (records.length === 0) {
        const warehouses = (await adminFetch<{ data: { internal_id: number; is_default: boolean }[] }>('/warehouses')).data;
        const warehouse = warehouses.find((w) => w.is_default) ?? warehouses[0];
        if (!warehouse) throw new Error(t('This store has no warehouse yet.'));
        const record = await adminFetch<{ data: { id: string } }>('/inventory', { method: 'POST', body: { warehouse_id: warehouse.internal_id, product_id: product.internal_id, product_variant_id: null } });
        if (wanted > 0) await adminFetch(`/inventory/${record.data.id}/opening-stock`, { method: 'POST', body: { quantity: wanted, reason: 'Stock set from the product list', idempotency_key: idempotencyKey() } });
      } else {
        const delta = wanted - (records[0].on_hand - records[0].reserved);
        if (delta !== 0) await adminFetch(`/inventory/${records[0].id}/adjust`, { method: 'POST', body: { quantity: delta, reason: 'Stock set from the product list', idempotency_key: idempotencyKey() } });
      }
      toast.success(t('Stock saved.'));
      list.reload();

      return true;
    } catch (e) {
      toast.error(adminErrorMessage(e));

      return false;
    }
  }  const filtered = filters.search !== '' || filters.status !== '';

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
              className={`h-4 w-4 rounded border-slate-300 text-brand-600 ${FOCUS_RING}`}
            />
          ),
        } satisfies Column<Product>]
      : []),
    {
      key: 'name',
      header: 'Product',
      priority: true, // the select box is the first column; the name must stay on a phone too
      render: (product) => (
        <div className="flex items-center gap-3">
          {product.thumbnail_url ? (
            <img src={product.thumbnail_url} alt="" loading="lazy" className="h-11 w-11 flex-none rounded-lg border border-slate-200 object-cover" />
          ) : (
            <span aria-hidden="true" className="flex h-11 w-11 flex-none items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 text-slate-400">
              <Icon name="box" size={18} />
            </span>
          )}
          <div className="min-w-0">
            <Link href={`/products/${product.id}`} className={`rounded font-medium text-slate-900 hover:text-brand-700 hover:underline ${FOCUS_RING}`}>
              {product.name}
            </Link>
            {product.sku && <p className="font-mono text-xs text-slate-500">{product.sku}</p>}
          </div>
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (product) => <StatusBadge status={product.status} /> },
    {
      key: 'price',
      header: 'Price',
      align: 'right',
      render: (product) => {
        const currency = product.currency ?? access.currency;
        const shown = product.effective_price_minor === null ? '—' : money(product.effective_price_minor, currency);
        // A simple product's own price; variants keep theirs on the product page.
        const editable = access.can('products.update') && product.type === 'simple' && (product.variants?.length ?? 0) === 0;

        return editable ? (
          <InlineEdit display={<span className="tabular-nums">{shown}</span>} initial={product.price_minor === null ? '' : fromMinor(product.price_minor, currency)} label={t('Price of {name}', { name: product.name })} onSave={(text) => savePrice(product, text)} />
        ) : (
          shown
        );
      },
    },
    {
      key: 'stock',
      header: 'Stock',
      align: 'right',
      render: (product) => {
        const shown =
          product.stock_available === undefined ? (
            '—'
          ) : product.stock_available <= 0 ? (
            <span className="font-medium text-red-700">{t('Out of stock')}</span>
          ) : (
            <span className={product.stock_available <= 5 ? 'font-medium text-amber-700' : 'text-slate-700'}>{t('{count} in stock', { count: product.stock_available.toLocaleString() })}</span>
          );
        const editable = access.can('inventory.adjust') && product.type === 'simple' && (product.variants?.length ?? 0) === 0;

        return editable ? (
          <InlineEdit display={shown} initial={String(Math.max(0, product.stock_available ?? 0))} inputMode="numeric" label={t('Stock of {name}', { name: product.name })} onSave={(text) => saveStock(product, text)} />
        ) : (
          shown
        );
      },
    },
    { key: 'variants', header: 'Variants', align: 'right', render: (product) => product.variants?.length ?? 0 },
    { key: 'brand', header: 'Brand', render: (product) => product.brand?.name ?? '—' },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'right',
      priority: true,
      render: (product) => (
        <RowMenu
          label={`Actions for ${product.name}`}
          actions={[
            { label: 'Edit', icon: 'settings', onSelect: () => router.visit(`/products/${product.id}`) },
            // Phase B39 (Module 06 §60): a hidden draft copy.
            ...(access.can('products.create')
              ? [{
                  label: busy === `copy-${product.id}` ? 'Copying…' : 'Duplicate',
                  icon: 'layers' as const,
                  disabled: busy !== null,
                  onSelect: () =>
                    void run(`copy-${product.id}`, () => adminFetch(`/products/${product.id}/duplicate`, { method: 'POST' }), { success: 'Copy created as a hidden draft.' }).then((copy) => {
                      if (copy !== undefined) {
                        list.reload();
                        usage.reload();
                      }
                    }),
                }]
              : []),
            ...(access.can('products.delete') ? [{ label: 'Delete', icon: 'x' as const, danger: true, onSelect: () => setRemoving(product) }] : []),
          ]}
        />
      ),
    },
  ];

  return (
    <AdminPage
      title="Products"
      description="What you sell: names, prices, variants and images."
      actions={
        <span className="flex flex-wrap gap-2">
          <Button
            busy={exporting}
            busyLabel="Exporting…"
            onClick={() => {
              setExporting(true);
              downloadFile('/products/export', { search: filters.search, status: filters.status }, 'products.csv')
                .catch((e) => toast.error(adminErrorMessage(e)))
                .finally(() => setExporting(false));
            }}
          >
            Export CSV
          </Button>
          {canImport && <Button onClick={() => setImporting(true)}>Import CSV</Button>}
          {access.can('products.create') && <ButtonLink href="/products/new" variant="primary">Add product</ButtonLink>}
        </span>
      }
    >
      {importing && (
        <ProductImportDialog
          onClose={() => setImporting(false)}
          onDone={() => {
            list.reload();
            usage.reload();
          }}
        />
      )}
      {limit && !limit.unlimited && (
        <div className="mb-4 rounded-lg border border-slate-200 bg-white p-3">
          <UsageMeter label="Products on your package" current={limit.current} limit={limit.limit} />
        </div>
      )}

      <SavedViews list="products" query={viewQuery(filters, FILTER_DEFAULTS)} onApply={(query) => setFilters(viewFilters(query, FILTER_DEFAULTS) as typeof FILTER_DEFAULTS)} />
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
