import { useEffect, useState } from 'react';
import Button from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField } from '@/Components/ui/Form';
import { ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import type { Attribute, AttributeSet, Category, CategoryAttributeItem } from '@/lib/catalog';

/**
 * Phase B41 (Module 07 §18–19, §44–45): the attributes that apply to a
 * category — in order, required for its products or not, a storefront filter
 * or not. A category without its own list follows its parent's, which is
 * shown. An attribute set adds its attributes in one step.
 */
export default function CategoryAttributesDialog({ category, canEdit, onClose }: { category: Category; canEdit: boolean; onClose: () => void }) {
  const state = useApi<{ data: { attributes: CategoryAttributeItem[]; inherited: CategoryAttributeItem[] } }>(`/categories/${category.id}/attributes`);
  const attributes = useApi<{ data: Attribute[] }>('/attributes');
  const sets = useApi<{ data: AttributeSet[] }>('/attribute-sets');
  const [items, setItems] = useState<CategoryAttributeItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { busy, run } = useAction();
  const all = attributes.data?.data ?? [];
  const byId = new Map(all.map((a) => [a.id, a]));

  useEffect(() => {
    if (state.data) setItems(state.data.data.attributes);
  }, [state.data]);

  async function save(applySet?: number) {
    if (items === null) return;
    setError(null);
    const saved = await run('save', () => adminFetch<{ data: { attributes: CategoryAttributeItem[]; inherited: CategoryAttributeItem[] } }>(`/categories/${category.id}/attributes`, {
      method: 'PUT',
      body: { attributes: items, ...(applySet ? { apply_set: applySet } : {}) },
    }), { success: 'Category attributes saved.', onError: setError });
    if (saved) state.setData(saved);
  }

  const inherited = state.data?.data.inherited ?? [];
  const unused = all.filter((a) => a.is_active !== false && !(items ?? []).some((i) => i.attribute_id === a.id));

  return (
    <Dialog open wide title={`Attributes and filters — ${category.name}`} onClose={onClose} busy={busy === 'save'}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : items === null || attributes.loading ? (
        <Skeleton lines={4} />
      ) : (
        <div className="space-y-4">
          <p className="text-sm text-slate-700">
            Products of this category ask for these attributes; <strong>required</strong> ones must be filled in, and <strong>filters</strong> appear on the category page for shoppers.
            Child categories without their own list use this one.
          </p>
          {items.length === 0 && inherited.length > 0 && (
            <p role="status" className="rounded-md bg-slate-50 p-2 text-sm text-slate-700">
              Now following its parent: {inherited.map((i) => byId.get(i.attribute_id)?.name).filter(Boolean).join(', ')}. Add attributes here to give it its own list.
            </p>
          )}
          {items.length > 0 && (
            <ol className="space-y-2">
              {items.map((item, index) => {
                const attribute = byId.get(item.attribute_id);
                const update = (patch: Partial<CategoryAttributeItem>) => setItems(items.map((it, i) => (i === index ? { ...it, ...patch } : it)));

                return (
                  <li key={item.attribute_id} className="flex flex-wrap items-center gap-3 rounded-md border border-slate-200 p-2 text-sm">
                    <span className="min-w-32 flex-1 font-medium">{attribute?.name ?? `#${item.attribute_id}`}</span>
                    <CheckboxField label="Required" disabled={!canEdit} checked={item.is_required} onChange={(x) => update({ is_required: x })} />
                    <CheckboxField label="Filter" disabled={!canEdit || attribute?.type === 'text'} checked={item.is_filter} onChange={(x) => update({ is_filter: x })} />
                    {canEdit && (
                      <span className="flex gap-1">
                        <Button size="sm" variant="ghost" disabled={index === 0} onClick={() => { const n = [...items]; [n[index - 1], n[index]] = [n[index], n[index - 1]]; setItems(n); }}>Up<span className="sr-only"> {attribute?.name}</span></Button>
                        <Button size="sm" variant="ghost" onClick={() => setItems(items.filter((_, i) => i !== index))}>Remove<span className="sr-only"> {attribute?.name}</span></Button>
                      </span>
                    )}
                  </li>
                );
              })}
            </ol>
          )}
          {canEdit && (
            <div className="grid gap-3 sm:grid-cols-2">
              <SelectField
                label="Add an attribute"
                value=""
                onChange={(id) => id && setItems([...items, { attribute_id: Number(id), is_required: false, is_filter: byId.get(Number(id))?.type !== 'text' && byId.get(Number(id))?.type !== 'numeric' }])}
                placeholder={all.length === 0 ? 'Create attributes first' : 'Choose'}
                options={unused.map((a) => ({ value: String(a.id), label: a.name }))}
              />
              <SelectField
                label="Add an attribute set"
                value=""
                onChange={(id) => id && save(Number(id))}
                placeholder={(sets.data?.data ?? []).length === 0 ? 'No sets yet' : 'Choose — saves at once'}
                options={(sets.data?.data ?? []).map((s) => ({ value: String(s.id), label: s.name }))}
              />
            </div>
          )}
          {error && <FormError message={error} />}
          <div className="flex justify-end gap-2">
            <Button onClick={onClose}>Close</Button>
            {canEdit && <Button variant="primary" onClick={() => save()} busy={busy === 'save'} busyLabel="Saving…">Save</Button>}
          </div>
        </div>
      )}
    </Dialog>
  );
}
