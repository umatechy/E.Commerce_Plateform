import { useState } from 'react';
import { SearchField } from '@/Components/ui/Filters';
import Button from '@/Components/ui/Button';
import { usePagedApi } from '@/lib/useApi';
import { variantLabel, type Product, type Variant } from '@/lib/catalog';

/**
 * Finds a product (and, where it has them, one of its variants) by
 * asking the server (GET /api/v1/products?search=). Nothing is searched
 * in the browser: the store may have far more products than one page.
 */
export type PickedProduct = { product: Product; variant: Variant | null };

export default function ProductPicker({ onPick, label = 'Find a product' }: { onPick: (picked: PickedProduct) => void; label?: string }) {
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
          <ul className="max-h-60 divide-y divide-slate-100 overflow-y-auto rounded-md border border-slate-200">
            {results.rows.map((product) => {
              const variants = (product.variants ?? []).filter((variant) => variant.status !== 'archived');

              return (
                <li key={product.id} className="p-2 text-sm">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span>
                      <span className="font-medium">{product.name}</span>
                      {product.sku && <span className="ml-2 font-mono text-xs text-slate-500">{product.sku}</span>}
                    </span>
                    {variants.length === 0 && (
                      <Button size="sm" onClick={() => onPick({ product, variant: null })}>
                        Choose<span className="sr-only"> {product.name}</span>
                      </Button>
                    )}
                  </div>
                  {variants.length > 0 && (
                    <ul className="mt-1 space-y-1 pl-3">
                      {variants.map((variant) => (
                        <li key={variant.id} className="flex flex-wrap items-center justify-between gap-2">
                          <span className="text-slate-700">{variantLabel(variant)}</span>
                          <Button size="sm" onClick={() => onPick({ product, variant })}>
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
