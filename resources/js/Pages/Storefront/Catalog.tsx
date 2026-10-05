import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import ProductGrid from '@/Components/Storefront/ProductGrid';
import Pagination from '@/Components/Storefront/Pagination';
import EmptyState from '@/Components/EmptyState';
import type { CategoryNode, ProductCard, StorefrontPageProps } from '@/Storefront/types';
import { currencyDigits } from '@/lib/money';
import { useT } from '@/Storefront/i18n';
import AttributeFilters, { type FilterFacet } from '@/Components/Storefront/AttributeFilters';

type Filters = { q?: string; category?: string; brand?: string; collection?: string; tag?: string; min_price?: number; max_price?: number; in_stock?: boolean; sort?: string; page?: number; attr?: Record<string, string> };
type Context =
  | { type: 'all' | 'search'; title: string }
  // Phase B39 (Module 06 §34–35).
  | { type: 'collection'; title: string; collection: { slug: string; name: string; description: string | null } }
  | { type: 'tag'; title: string }
  | { type: 'category'; title: string; category: CategoryNode & { children: CategoryNode[] } }
  | { type: 'brand'; title: string; brand: { slug: string; name: string; description: string | null } };

type Props = StorefrontPageProps & {
  context: Context;
  filters: Filters;
  listing: { products: ProductCard[]; pagination: { page: number; per_page: number; total: number; last_page: number }; filters?: FilterFacet[]; sort?: string };
  facets: { categories: CategoryNode[]; brands: { slug: string; name: string }[] };
};

// Phase B43 (Module 05 §19): featured (the store's merchandising order) and best selling join; relevance on
// searches, the collection's own order on collection pages.
const SORTS = ['featured', 'newest', 'best_selling', 'price_asc', 'price_desc', 'name', 'relevance', 'manual'] as const;

/** Listing for all products, search results, a category or a brand. */
export default function Catalog({ storefront, seo, context, filters, listing, facets }: Props) {
  const base = storefront.base_path;
  const t = useT();
  const sortLabel: Record<(typeof SORTS)[number], string> = {
    featured: t('Featured'),
    newest: t('Newest'),
    best_selling: t('Best selling'),
    price_asc: t('Price: low to high'),
    price_desc: t('Price: high to low'),
    name: t('Name'),
    relevance: t('Most relevant'),
    manual: t('Recommended'),
  };
  // Phase B38: the page's own titles in the visitor's language; category and brand names come translated from the server.
  const title = context.type === 'search' ? (filters.q ? t('Results for “{q}”', { q: filters.q }) : t('Search results')) : context.type === 'all' ? t('All products') : context.title;
  const path =
    context.type === 'category'
      ? `${base}/categories/${context.category.slug}`
      : context.type === 'brand'
        ? `${base}/brands/${context.brand.slug}`
        : context.type === 'collection'
          ? `${base}/collections/${context.collection.slug}`
          : context.type === 'tag'
            ? `${base}/tags/${filters.tag ?? ''}`
            : `${base}/${context.type === 'search' ? 'search' : 'products'}`;
  // Category/brand pages carry that filter in the path, not the query.
  const query = Object.fromEntries(
    Object.entries(filters).filter(
      ([key]) =>
        !(key === 'category' && context.type === 'category') &&
        !(key === 'brand' && context.type === 'brand') &&
        !(key === 'collection' && context.type === 'collection') &&
        !(key === 'tag' && context.type === 'tag') &&
        key !== 'attr',
    ),
  );
  // Phase B41: attribute filters travel flat as attr[key]=value.
  for (const [key, value] of Object.entries(filters.attr ?? {})) {
    query[`attr[${key}]`] = value;
  }
  // ISO 4217 decimals, as the server stores them (browsers show PKR without any).
  const digits = currencyDigits(storefront.store.currency);
  const [minPrice, setMinPrice] = useState(filters.min_price !== undefined ? String(filters.min_price / 10 ** digits) : '');
  const [maxPrice, setMaxPrice] = useState(filters.max_price !== undefined ? String(filters.max_price / 10 ** digits) : '');

  function visit(changes: Record<string, string | number | boolean | undefined>) {
    const next = { ...query, ...changes, page: undefined, ...(changes.page !== undefined ? { page: changes.page } : {}) };
    const params = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== undefined && v !== '' && v !== false));
    router.get(path, params as Record<string, string>, { preserveState: true, preserveScroll: true });
  }

  function applyPrice(event: React.FormEvent) {
    event.preventDefault();
    const toMinor = (value: string) => (value === '' ? undefined : Math.round(Number(value) * 10 ** digits));
    visit({ min_price: toMinor(minPrice), max_price: toMinor(maxPrice) });
  }

  // The order shown: the shopper's choice, or the page's default (category, collection, store or relevance).
  const currentSort = filters.sort ?? listing.sort ?? 'newest';
  const sortChoices = SORTS.filter(
    (value) =>
      (value !== 'relevance' || context.type === 'search' || Boolean(filters.q) || currentSort === value) &&
      (value !== 'manual' || context.type === 'collection' || currentSort === value),
  );

  const pageHref = (page: number) => `${path}?${new URLSearchParams({ ...(query as Record<string, string>), page: String(page) }).toString()}`;

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="mb-6">
        <h1 className="text-3xl font-bold">{title}</h1>
        {context.type === 'category' && context.category.description && <p className="mt-2 text-sf-muted">{context.category.description}</p>}
        {context.type === 'brand' && context.brand.description && <p className="mt-2 text-sf-muted">{context.brand.description}</p>}
        {context.type === 'collection' && context.collection.description && <p className="mt-2 text-sf-muted">{context.collection.description}</p>}
        <p className="mt-1 text-sm text-sf-muted">{t('{count} products', { count: listing.pagination.total })}</p>
      </div>

      <div className="grid gap-8 md:grid-cols-[220px_1fr]">
        <aside className="space-y-6 text-sm">
          <div>
            <h2 className="mb-2 font-semibold">{t('Categories')}</h2>
            <ul className="space-y-1">
              {facets.categories.map((category) => (
                <li key={category.id}>
                  <Link href={`${base}/categories/${category.slug}`} className="hover:text-sf-accent">
                    {category.name} <span className="text-sf-muted">({category.product_count})</span>
                  </Link>
                  {category.children.length > 0 && (
                    <ul className="ms-3 mt-1 space-y-1">
                      {category.children.map((child) => (
                        <li key={child.id}>
                          <Link href={`${base}/categories/${child.slug}`} className="text-sf-muted hover:text-sf-accent">
                            {child.name}
                          </Link>
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              ))}
            </ul>
          </div>

          {facets.brands.length > 0 && context.type !== 'brand' && (
            <div>
              <h2 className="mb-2 font-semibold">{t('Brand')}</h2>
              <select
                value={filters.brand ?? ''}
                onChange={(e) => visit({ brand: e.target.value || undefined })}
                className="w-full rounded-sf border border-sf-border px-2 py-1"
                aria-label={t('Brand')}
              >
                <option value="">{t('All brands')}</option>
                {facets.brands.map((brand) => (
                  <option key={brand.slug} value={brand.slug}>
                    {brand.name}
                  </option>
                ))}
              </select>
            </div>
          )}

          <form onSubmit={applyPrice}>
            <h2 className="mb-2 font-semibold">{t('Price')}</h2>
            <div className="flex items-center gap-2">
              <input value={minPrice} onChange={(e) => setMinPrice(e.target.value)} inputMode="decimal" placeholder={t('Min')} aria-label={t('Minimum price')} className="w-20 rounded-sf border border-sf-border px-2 py-1" />
              <span>–</span>
              <input value={maxPrice} onChange={(e) => setMaxPrice(e.target.value)} inputMode="decimal" placeholder={t('Max')} aria-label={t('Maximum price')} className="w-20 rounded-sf border border-sf-border px-2 py-1" />
            </div>
            <button type="submit" className="mt-2 rounded-sf border border-sf-border px-3 py-1">
              {t('Apply')}
            </button>
          </form>

          <label className="flex items-center gap-2">
            <input type="checkbox" checked={Boolean(filters.in_stock)} onChange={(e) => visit({ in_stock: e.target.checked ? 1 : undefined })} />
            {t('In stock only')}
          </label>

          <AttributeFilters facets={listing.filters ?? []} onChange={(key, value) => visit({ [`attr[${key}]`]: value })} />
          {Object.keys(filters.attr ?? {}).length > 0 && (
            <button type="button" className="text-sf-accent underline" onClick={() => visit(Object.fromEntries(Object.keys(filters.attr ?? {}).map((key) => [`attr[${key}]`, undefined])))}>
              {t('Clear filters')}
            </button>
          )}
        </aside>

        <div>
          <div className="mb-4 flex justify-end">
            <select
              value={currentSort}
              onChange={(e) => visit({ sort: e.target.value })}
              className="rounded-sf border border-sf-border px-2 py-1 text-sm"
              aria-label={t('Sort by')}
            >
              {sortChoices.map((value) => (
                <option key={value} value={value}>
                  {sortLabel[value]}
                </option>
              ))}
            </select>
          </div>

          {listing.products.length === 0 ? (
            <EmptyState title={t('No products found')} description={t('Try removing a filter or searching for something else.')} />
          ) : (
            <ProductGrid products={listing.products} basePath={base} />
          )}
          <Pagination page={listing.pagination.page} lastPage={listing.pagination.last_page} hrefFor={pageHref} />
        </div>
      </div>
    </StoreLayout>
  );
}
