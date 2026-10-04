import { useId, useState } from 'react';
import Button from '@/Components/ui/Button';
import { SearchField } from '@/Components/ui/Filters';
import { StatusBadge } from '@/Components/ui/Badge';
import { useApi } from '@/lib/useApi';
import type { Product, ProductRef } from '@/lib/catalog';

/**
 * Phase B39: a chosen list of products, in order — search the store's
 * products to add one, move one up or down, remove one. Used for a
 * hand-picked collection and for a product's related products. The server
 * re-checks every id against the store.
 */
export default function ProductPicker({
  label,
  value,
  onChange,
  max,
  exclude = [],
  disabled = false,
}: {
  label: string;
  value: ProductRef[];
  onChange: (next: ProductRef[]) => void;
  max: number;
  exclude?: string[];
  disabled?: boolean;
}) {
  const listId = useId();
  const [search, setSearch] = useState('');
  const results = useApi<{ data: Product[] }>(search.trim().length >= 2 ? '/products' : null, { search: search.trim() });
  const chosen = new Set(value.map((product) => product.id));
  const found = (results.data?.data ?? []).filter((product) => !chosen.has(product.id) && !exclude.includes(product.id)).slice(0, 8);
  const full = value.length >= max;

  function move(index: number, by: number) {
    const next = [...value];
    const [item] = next.splice(index, 1);
    next.splice(index + by, 0, item);
    onChange(next);
  }

  return (
    <div>
      <p id={listId} className="text-sm font-medium text-slate-700">{label}</p>
      {value.length === 0 ? (
        <p className="mt-1 text-sm text-slate-600">None chosen yet.</p>
      ) : (
        <ol aria-labelledby={listId} className="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200">
          {value.map((product, index) => (
            <li key={product.id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
              <span className="w-6 text-slate-500">{index + 1}.</span>
              <span className="min-w-0 flex-1 truncate font-medium">{product.name}</span>
              {product.status !== 'active' && <StatusBadge status={product.status} />}
              {!disabled && (
                <span className="flex gap-1">
                  <Button size="sm" variant="ghost" disabled={index === 0} onClick={() => move(index, -1)}>Up<span className="sr-only"> {product.name}</span></Button>
                  <Button size="sm" variant="ghost" disabled={index === value.length - 1} onClick={() => move(index, 1)}>Down<span className="sr-only"> {product.name}</span></Button>
                  <Button size="sm" variant="ghost" onClick={() => onChange(value.filter((p) => p.id !== product.id))}>Remove<span className="sr-only"> {product.name}</span></Button>
                </span>
              )}
            </li>
          ))}
        </ol>
      )}
      {!disabled && (
        <div className="mt-3">
          {full ? (
            <p className="text-sm text-slate-600">The most is {max}. Remove one to add another.</p>
          ) : (
            <>
              <SearchField label="Add a product" placeholder="Type at least 2 letters of a name or SKU" value={search} onChange={setSearch} />
              {results.loading && <p className="mt-2 text-sm text-slate-600">Searching…</p>}
              {search.trim().length >= 2 && !results.loading && found.length === 0 && <p className="mt-2 text-sm text-slate-600">No other products match.</p>}
              {found.length > 0 && (
                <ul className="mt-2 space-y-1" aria-label="Matching products">
                  {found.map((product) => (
                    <li key={product.id} className="flex items-center justify-between gap-2 rounded-md bg-slate-50 px-3 py-1.5 text-sm">
                      <span className="truncate">{product.name}{product.sku ? <span className="ms-2 font-mono text-xs text-slate-500">{product.sku}</span> : null}</span>
                      <Button size="sm" onClick={() => onChange([...value, { id: product.id, name: product.name, status: product.status }])}>
                        Add<span className="sr-only"> {product.name}</span>
                      </Button>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </div>
      )}
    </div>
  );
}
