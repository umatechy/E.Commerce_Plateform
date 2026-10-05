import { useEffect, useState } from 'react';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import Dialog from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField } from '@/Components/ui/Form';
import { ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { useAccess } from '@/lib/access';
import { LISTING_SORTS } from '@/lib/catalog';
import { toast } from '@/Components/ui/toast';

/**
 * Phase B45 (Module 07 §20, §105–106): start the catalogue from the starter
 * template of what the store sells — categories, attributes and filters,
 * and if wanted the suggested theme, brands and default order. It shows
 * what is already in the store; applying only adds what is missing.
 */
export type StarterTemplateSummary = { key: string; name: string; version: number; summary: string; categories: number; attributes: number; brands: number };

type Preview = {
  key: string;
  name: string;
  summary: string;
  categories: { name: string; description: string | null; exists: boolean; children: { name: string; exists: boolean }[]; attributes: { key: string; name: string; filter: boolean; required: boolean }[] }[];
  attributes: { key: string; name: string; type: string; unit: string | null; values: string[]; exists: boolean; kept: boolean }[];
  brands: { name: string; exists: boolean }[];
  theme: { key: string; name: string; preferred: boolean };
  default_sort: string;
  default_sort_is_set: boolean;
  store_live: boolean;
};

type Result = { categories_added: number; attributes_added: number; values_added: number; category_attributes_added: number; brands_added: number; attributes_kept: string[]; theme: { name: string; published: boolean } | null; default_sort: string | null };

const MAX_VALUES_SHOWN = 8;

function plural(n: number, one: string, many: string): string {
  return `${n} ${n === 1 ? one : many}`;
}

/** One sentence on what was added (for the toast). */
export function describeResult(r: Result): string {
  const parts = [plural(r.categories_added, 'category', 'categories'), plural(r.attributes_added, 'attribute', 'attributes'), plural(r.values_added, 'value', 'values'), plural(r.category_attributes_added, 'filter or field', 'filters and fields')];
  if (r.brands_added > 0) parts.push(plural(r.brands_added, 'brand', 'brands'));
  let text = `Template applied: added ${parts.join(', ')}.`;
  if (r.theme) text += r.theme.published ? ` The ${r.theme.name} theme is live.` : ` The ${r.theme.name} theme is in your theme draft — publish it when you are ready.`;
  if (r.attributes_kept.length > 0) text += ` Kept your own: ${r.attributes_kept.join(', ')}.`;

  return text;
}

export default function StarterTemplateDialog({ onClose, onApplied }: { onClose: () => void; onApplied: () => void }) {
  const access = useAccess();
  const list = useApi<{ data: StarterTemplateSummary[]; recommended: string | null; history: { key: string; applied_at: string | null }[] }>('/starter-templates');
  const [key, setKey] = useState('');
  const preview = useApi<{ data: Preview }>(key === '' ? null : `/starter-templates/${key}`);
  const [options, setOptions] = useState({ theme: true, brands: false, default_sort: true });
  const [error, setError] = useState<string | null>(null);
  const { busy, run } = useAction();
  const canTheme = access.can('theme.publish');
  const canBrands = access.can('brands.manage');

  useEffect(() => {
    if (key === '' && list.data) setKey(list.data.recommended ?? list.data.data[0]?.key ?? '');
  }, [list.data, key]);

  const p = preview.data?.data;
  const applied = list.data?.history.find((h) => h.key === key);

  async function apply() {
    if (!p) return;
    setError(null);
    const body = { theme: canTheme && options.theme, brands: canBrands && options.brands && p.brands.length > 0, default_sort: options.default_sort && !p.default_sort_is_set };
    const result = await run('apply', () => adminFetch<{ data: Result }>(`/starter-templates/${p.key}/apply`, { method: 'POST', body }), { onError: setError });
    if (result) {
      toast.success(describeResult(result.data));
      onApplied();
    }
  }

  return (
    <Dialog open wide title="Start from a template" onClose={onClose} busy={busy === 'apply'}>
      {list.error ? (
        <ErrorPanel message={list.error} onRetry={list.reload} />
      ) : !list.data ? (
        <Skeleton lines={4} />
      ) : (
        <div className="space-y-4">
          <FormError message={error} />
          <p className="text-sm text-slate-700">
            A ready-made structure for what you sell: categories, product attributes and the filters shoppers use. Nothing in your store is removed or changed — only what is missing is added,
            and you can edit or delete anything afterwards. No products, prices or reviews are added.
          </p>
          <SelectField
            label="What you sell"
            value={key}
            onChange={setKey}
            options={list.data.data.map((t) => ({ value: t.key, label: t.key === list.data?.recommended ? `${t.name} (your store)` : t.name }))}
          />
          {applied && <p className="text-xs text-slate-600">You applied this template on {applied.applied_at ? new Date(applied.applied_at).toLocaleDateString() : 'an earlier date'}; applying it again adds only what is missing.</p>}

          {preview.error ? (
            <ErrorPanel message={preview.error} onRetry={preview.reload} />
          ) : !p || p.key !== key ? (
            <Skeleton lines={5} />
          ) : (
            <>
              <p className="text-sm text-slate-700">{p.summary}</p>
              <section aria-labelledby="tpl-categories">
                <h3 id="tpl-categories" className="text-sm font-semibold text-slate-900">Categories</h3>
                <ul className="mt-2 grid gap-2 sm:grid-cols-2">
                  {p.categories.map((c) => (
                    <li key={c.name} className="rounded-md border border-slate-200 p-2 text-sm">
                      <p className="flex flex-wrap items-center gap-2 font-medium text-slate-900">
                        {c.name} {c.exists && <Badge>already in your store</Badge>}
                      </p>
                      {c.children.length > 0 && <p className="text-slate-600">{c.children.map((ch) => ch.name).join(' · ')}</p>}
                      {c.attributes.some((a) => a.filter) && (
                        <p className="text-xs text-slate-600">Filters: {c.attributes.filter((a) => a.filter).map((a) => a.name).join(', ')}</p>
                      )}
                    </li>
                  ))}
                </ul>
              </section>
              <section aria-labelledby="tpl-attributes">
                <h3 id="tpl-attributes" className="text-sm font-semibold text-slate-900">Attributes</h3>
                <ul className="mt-2 space-y-1 text-sm">
                  {p.attributes.map((a) => (
                    <li key={a.key} className="flex flex-wrap items-center gap-2">
                      <span className="font-medium text-slate-900">{a.name}</span>
                      {a.unit && <span className="text-slate-600">({a.unit})</span>}
                      {a.values.length > 0 && (
                        <span className="text-slate-600">
                          {a.values.slice(0, MAX_VALUES_SHOWN).join(', ')}
                          {a.values.length > MAX_VALUES_SHOWN && ` +${a.values.length - MAX_VALUES_SHOWN} more`}
                        </span>
                      )}
                      {a.kept ? <Badge tone="amber">yours is kept (different type)</Badge> : a.exists && <Badge>already in your store</Badge>}
                    </li>
                  ))}
                </ul>
              </section>
              <div className="space-y-2 rounded-md bg-slate-50 p-3">
                {canTheme && (
                  <CheckboxField
                    label={`Use the ${p.theme.name} theme`}
                    checked={options.theme}
                    onChange={(v) => setOptions({ ...options, theme: v })}
                    hint={`${p.theme.preferred ? 'Suggested for this kind of store.' : 'The closest theme your package includes.'} ${p.store_live ? 'Your store is live, so it goes into your theme draft; you publish it when you are ready.' : 'Applied now; your store is not live yet.'}`}
                  />
                )}
                {canBrands && p.brands.length > 0 && (
                  <CheckboxField
                    label="Also add these brands"
                    checked={options.brands}
                    onChange={(v) => setOptions({ ...options, brands: v })}
                    hint={p.brands.map((b) => b.name).join(', ')}
                  />
                )}
                <CheckboxField
                  label={`Show products “${LISTING_SORTS[p.default_sort] ?? p.default_sort}” by default`}
                  checked={options.default_sort && !p.default_sort_is_set}
                  disabled={p.default_sort_is_set}
                  onChange={(v) => setOptions({ ...options, default_sort: v })}
                  hint={p.default_sort_is_set ? 'Your store already has a default order; it stays (change it in Settings).' : 'Shoppers can still choose another order.'}
                />
              </div>
            </>
          )}
          <div className="flex justify-end gap-2">
            <Button onClick={onClose} disabled={busy === 'apply'}>Cancel</Button>
            <Button variant="primary" onClick={apply} disabled={!p || p.key !== key} busy={busy === 'apply'} busyLabel="Applying…">Apply template</Button>
          </div>
        </div>
      )}
    </Dialog>
  );
}
