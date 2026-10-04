import { useState } from 'react';
import Button from '@/Components/ui/Button';
import { SelectField, TextField } from '@/Components/ui/Form';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { toMinor } from '@/lib/money';
import { categoryTree, parseTags, type BulkResult, type Category, type Collection } from '@/lib/catalog';

/**
 * Phase B39 (Module 06 §46–47): one change to the chosen products
 * (POST /products/bulk). The server checks the permission and the package's
 * product limit for each product and reports any it skipped and why;
 * deleting asks for confirmation first.
 */
type ActionKey =
  | 'publish' | 'unpublish' | 'archive' | 'delete' | 'set_visibility' | 'set_featured' | 'set_category' | 'add_category'
  | 'add_to_collection' | 'remove_from_collection' | 'add_tags' | 'remove_tags' | 'set_price' | 'adjust_price' | 'set_sale_percent' | 'clear_sale';

const ACTIONS: { value: ActionKey; label: string; needs?: 'visibility' | 'featured' | 'category' | 'collection' | 'tags' | 'price' | 'percent' }[] = [
  { value: 'publish', label: 'Publish (make active)' },
  { value: 'unpublish', label: 'Move to draft' },
  { value: 'archive', label: 'Archive' },
  { value: 'set_visibility', label: 'Set visibility', needs: 'visibility' },
  { value: 'set_featured', label: 'Mark or unmark as featured', needs: 'featured' },
  { value: 'set_category', label: 'Set main category', needs: 'category' },
  { value: 'add_category', label: 'Also list in a category', needs: 'category' },
  { value: 'add_to_collection', label: 'Add to a collection', needs: 'collection' },
  { value: 'remove_from_collection', label: 'Remove from a collection', needs: 'collection' },
  { value: 'add_tags', label: 'Add tags', needs: 'tags' },
  { value: 'remove_tags', label: 'Remove tags', needs: 'tags' },
  { value: 'set_price', label: 'Set price', needs: 'price' },
  { value: 'adjust_price', label: 'Change price by a percentage', needs: 'percent' },
  { value: 'set_sale_percent', label: 'Put on sale (percentage off)', needs: 'percent' },
  { value: 'clear_sale', label: 'End sale' },
  { value: 'delete', label: 'Delete' },
];

export default function BulkBar({ selected, currency, canCollections, onDone, onClear }: { selected: string[]; currency: string; canCollections: boolean; onDone: (result: BulkResult) => void; onClear: () => void }) {
  const [action, setAction] = useState<ActionKey | ''>('');
  const [param, setParam] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const { busy, run } = useAction();
  const needs = ACTIONS.find((a) => a.value === action)?.needs;
  const categories = useApi<{ data: Category[] }>(needs === 'category' ? '/categories' : null);
  const collections = useApi<{ data: Collection[] }>(needs === 'collection' ? '/collections' : null);

  function params(): Record<string, unknown> | null {
    switch (needs) {
      case 'visibility': return param === '' ? null : { visibility: param };
      case 'featured': return param === '' ? null : { featured: param === 'yes' };
      case 'category': return param === '' ? null : { category_id: Number(param) };
      case 'collection': return param === '' ? null : { collection: param };
      case 'tags': return parseTags(param).length === 0 ? null : { tags: parseTags(param) };
      case 'price': {
        const minor = toMinor(param, currency);

        return minor === null ? null : { price_minor: minor };
      }
      case 'percent': return param.trim() === '' || Number.isNaN(Number(param)) ? null : { percent: Number(param) };
      default: return {};
    }
  }

  async function apply() {
    if (action === '') return;
    const values = params();
    if (values === null) {
      setError('Fill in the value for this change.');

      return;
    }
    setError(null);
    const result = await run('bulk', () => adminFetch<{ data: BulkResult }>('/products/bulk', { method: 'POST', body: { action, products: selected, params: values } }), { onError: setError });
    setConfirming(false);
    if (result) {
      onDone(result.data);
      setAction('');
      setParam('');
    }
  }

  return (
    <section aria-label="Change the chosen products" className="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3">
      <div className="flex flex-wrap items-end gap-3">
        <p className="text-sm font-medium text-indigo-900" aria-live="polite">{selected.length} chosen</p>
        <div className="w-64">
          <SelectField
            label="Change"
            value={action}
            onChange={(v) => {
              setAction(v as ActionKey | '');
              setParam('');
              setError(null);
            }}
            placeholder="Choose a change"
            options={ACTIONS.filter((a) => canCollections || a.needs !== 'collection').map((a) => ({ value: a.value, label: a.label }))}
          />
        </div>
        {needs === 'visibility' && (
          <div className="w-48">
            <SelectField label="Visibility" value={param} onChange={setParam} placeholder="Choose" options={[
              { value: 'public', label: 'Public' }, { value: 'catalog_only', label: 'Catalog only' }, { value: 'search_only', label: 'Search only' }, { value: 'hidden', label: 'Hidden' },
            ]} />
          </div>
        )}
        {needs === 'featured' && (
          <div className="w-48">
            <SelectField label="Featured" value={param} onChange={setParam} placeholder="Choose" options={[{ value: 'yes', label: 'Featured' }, { value: 'no', label: 'Not featured' }]} />
          </div>
        )}
        {needs === 'category' && (
          <div className="w-56">
            <SelectField label="Category" value={param} onChange={setParam} placeholder="Choose" options={categoryTree(categories.data?.data ?? []).map(({ category, depth }) => ({ value: String(category.id), label: `${'— '.repeat(depth)}${category.name}` }))} />
          </div>
        )}
        {needs === 'collection' && (
          <div className="w-56">
            <SelectField
              label="Hand-picked collection"
              value={param}
              onChange={setParam}
              placeholder="Choose"
              options={(collections.data?.data ?? []).filter((c) => c.type === 'manual').map((c) => ({ value: c.id, label: c.name }))}
            />
          </div>
        )}
        {needs === 'tags' && <div className="w-56"><TextField label="Tags" value={param} onChange={setParam} hint="Separate with commas." /></div>}
        {needs === 'price' && <div className="w-40"><TextField label={`New price (${currency})`} inputMode="decimal" value={param} onChange={setParam} /></div>}
        {needs === 'percent' && (
          <div className="w-40">
            <TextField label="Percent" inputMode="decimal" value={param} onChange={setParam} hint={action === 'adjust_price' ? '-90 to 500, e.g. 10 or -15' : '1 to 99'} />
          </div>
        )}
        <Button variant="primary" disabled={action === ''} busy={busy === 'bulk' && !confirming} busyLabel="Applying…" onClick={() => (action === 'delete' ? setConfirming(true) : apply())}>
          Apply
        </Button>
        <Button variant="ghost" onClick={onClear}>Clear selection</Button>
      </div>
      {error && <p role="alert" className="mt-2 text-sm font-medium text-red-700">{error}</p>}

      <ConfirmDialog open={confirming} title={`Delete ${selected.length} products?`} confirmLabel="Delete products" busy={busy === 'bulk'} onClose={() => setConfirming(false)} onConfirm={apply}>
        <p>They are removed from your catalog and your storefront. Orders that already contain them keep their record. This cannot be undone from the admin.</p>
      </ConfirmDialog>
    </section>
  );
}
