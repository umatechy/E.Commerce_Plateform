import { useState } from 'react';
import { SearchField } from '@/Components/ui/Filters';
import Button, { ButtonLink } from '@/Components/ui/Button';
import { usePagedApi } from '@/lib/useApi';
import { money } from '@/lib/money';
import { variantLabel, type Product, type Variant } from '@/lib/catalog';

/**
 * Finds a product (and, where it has them, one of its variants) by
 * asking the server (GET /api/v1/products?search=). Nothing is searched
 * in the browser: the store may have far more products than one page.
 *
 * UI-26: `forSale` (an order) shows each product's price and the stock
 * left to sell, and a product with none cannot be chosen — "Add stock"
 * opens its stock instead, so the order is not refused after it is filled
 * in. The server still checks the stock when the order is placed.
 */
export type PickedProduct = { product: Product; variant: Variant | null };

function StockChip({ available }: { available: number }) {
  return available > 0 ? (
    <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{available} in stock</span>
  ) : (
    <span className="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Out of stock</span>
  );
}

export default function ProductPicker({ onPick, label = 'Find a product', forSale = false }: { onPick: (picked: PickedProduct) => void; label?: string; forSale?: boolean }) {
  const [search, setSearch] = useState('');
  const results = usePagedApi<Product>(search.trim().length < 2 ? null : '/products', { search });

  return (
    <div>
      <SearchField label={label} placeholder="Type at least 2 letters of the name or SKU" value={search} onChange={setSearch} />
      <div className="mt-2" aria-live="polite">
        {search.trim().length >= 2 && results.error && <p className="text-sm text-red-700">{results.error}</p>}
        {search.trim().length >= 2 && !results.error && results.rows === null && <p className="text-sm text-slate-500">Searching…</p>}
        {results.rows !== null && results.rows.length === 0 && <p className="text-sm text-slate-600">No product matches.</p>}
        {results.rows !== null && results.rows.length > 0 && (
          <ul className="max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200">
            {results.rows.map((product) => {
              const variants = (product.variants ?? []).filter((variant) => variant.status !== 'archived');
              const available = product.stock_available;
              const soldOut = forSale && available !== undefined && available <= 0;

              return (
                <li key={product.id} className="p-3 text-sm">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="flex min-w-0 flex-wrap items-center gap-2">
                      <span className="font-medium text-slate-900">{product.name}</span>
                      {product.sku && <span className="font-mono text-xs text-slate-500">{product.sku}</span>}
                      {forSale && product.effective_price_minor !== null && <span className="text-slate-600">{money(product.effective_price_minor, product.currency ?? 'PKR')}</span>}
                      {forSale && available !== undefined && <StockChip available={available} />}
                      {forSale && product.status !== 'active' && <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Not for sale ({product.status})</span>}
                    </span>
                    {variants.length === 0 && (soldOut ? (
                      <ButtonLink href={`/inventory?product=${product.internal_id}`} size="sm">
                        Add stock<span className="sr-only"> for {product.name}</span>
                      </ButtonLink>
                    ) : (
                      <Button size="sm" onClick={() => onPick({ product, variant: null })}>
                        Choose<span className="sr-only"> {product.name}</span>
                      </Button>
                    ))}
                  </div>
                  {variants.length > 0 && (
                    <ul className="mt-2 space-y-1 ps-3">
                      {variants.map((variant) => (
                        <li key={variant.id} className="flex flex-wrap items-center justify-between gap-2">
                          <span className="text-slate-700">{variantLabel(variant)}</span>
                          <Button size="sm" disabled={soldOut} onClick={() => onPick({ product, variant })}>
                            Choose<span className="sr-only"> {product.name}, {variantLabel(variant)}</span>
                          </Button>
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}
