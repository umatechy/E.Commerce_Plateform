import { useEffect, useState } from 'react';
import Button from '@/Components/ui/Button';
import { CheckboxField, FormError, SelectField, TextField } from '@/Components/ui/Form';
import { Card, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { hasValues, type Attribute, type SpecificationValue, type Specifications } from '@/lib/catalog';

/**
 * Phase B41 (Module 07 §41, §45): a product's specifications — the
 * attributes its main category suggests first (required ones marked), then
 * any other attribute added by hand. Each is entered by its type; the server
 * checks the values and the required ones.
 */
export default function SpecificationsCard({ productId, canEdit }: { productId: string; canEdit: boolean }) {
  const state = useApi<{ data: Specifications }>(`/products/${productId}/specifications`);
  const attributes = useApi<{ data: Attribute[] }>('/attributes');
  const [values, setValues] = useState<Map<number, SpecificationValue> | null>(null);
  const [shown, setShown] = useState<number[]>([]);
  const [dirty, setDirty] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { busy, run } = useAction();
  const byId = new Map((attributes.data?.data ?? []).map((a) => [a.id, a]));

  useEffect(() => {
    if (!state.data) return;
    const data = state.data.data;
    setValues(new Map(data.values.map((v) => [v.attribute_id, v.value])));
    setShown([...new Set([...data.suggested.map((s) => s.attribute_id), ...data.values.map((v) => v.attribute_id)])]);
    setDirty(false);
  }, [state.data]);

  const required = new Set((state.data?.data.suggested ?? []).filter((s) => s.is_required).map((s) => s.attribute_id));

  function set(id: number, value: SpecificationValue) {
    const next = new Map(values ?? []);
    next.set(id, value);
    setValues(next);
    setDirty(true);
  }

  async function save() {
    if (values === null) return;
    setError(null);
    const specifications = shown.map((id) => ({ attribute_id: id, value: values.get(id) ?? null }));
    const saved = await run('save', () => adminFetch<{ data: Specifications }>(`/products/${productId}/specifications`, { method: 'PUT', body: { specifications } }), { success: 'Specifications saved.', onError: setError });
    if (saved) state.setData(saved);
  }

  function input(attribute: Attribute) {
    const value = values?.get(attribute.id) ?? null;
    const label = `${attribute.name}${required.has(attribute.id) ? ' (required)' : ''}`;
    const offered = (attribute.options ?? []).filter((o) => o.is_active || (Array.isArray(value) ? value.includes(o.id) : value === o.id));
    switch (attribute.type) {
      case 'select':
      case 'color':
        return <SelectField label={label} disabled={!canEdit} value={value === null ? '' : String(value)} onChange={(x) => set(attribute.id, x === '' ? null : Number(x))} placeholder="Not set" options={offered.map((o) => ({ value: String(o.id), label: o.value }))} />;
      case 'multi_select':
        return (
          <fieldset>
            <legend className="text-sm font-medium text-slate-700">{label}</legend>
            <div className="mt-1 flex flex-wrap gap-3">
              {offered.map((o) => {
                const list = Array.isArray(value) ? value : [];

                return <CheckboxField key={o.id} label={o.value} disabled={!canEdit} checked={list.includes(o.id)} onChange={(c) => set(attribute.id, c ? [...list, o.id] : list.filter((x) => x !== o.id))} />;
              })}
            </div>
          </fieldset>
        );
      case 'boolean':
        return <SelectField label={label} disabled={!canEdit} value={value === null ? '' : value ? 'yes' : 'no'} onChange={(x) => set(attribute.id, x === '' ? null : x === 'yes')} placeholder="Not set" options={[{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }]} />;
      case 'numeric':
        return <TextField label={`${label}${attribute.unit ? ` — ${attribute.unit}` : ''}`} optional={!required.has(attribute.id)} disabled={!canEdit} inputMode="decimal" value={value === null ? '' : String(value)} onChange={(x) => set(attribute.id, x.trim() === '' ? null : x.trim())} />;
      default:
        return <TextField label={label} optional={!required.has(attribute.id)} disabled={!canEdit} value={typeof value === 'string' ? value : ''} onChange={(x) => set(attribute.id, x === '' ? null : x)} maxLength={500} />;
    }
  }

  const addable = (attributes.data?.data ?? []).filter((a) => a.is_active !== false && !shown.includes(a.id));

  return (
    <Card title="Specifications" description="Details such as material, RAM or size, shown on the product page. Your category settings decide which are suggested, required and used as filters.">
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : values === null || attributes.loading ? (
        <Skeleton lines={3} />
      ) : (
        <div className="space-y-4">
          {shown.length === 0 && <p className="text-sm text-slate-600">No specifications yet. Choose the product&apos;s main category to see its suggested ones, or add any attribute.</p>}
          <div className="grid gap-4 sm:grid-cols-2">
            {shown.map((id) => {
              const attribute = byId.get(id);

              return attribute ? (
                <div key={id} className={hasValues(attribute.type) && attribute.type === 'multi_select' ? 'sm:col-span-2' : ''}>
                  {input(attribute)}
                </div>
              ) : null;
            })}
          </div>
          {canEdit && addable.length > 0 && (
            <div className="w-64">
              <SelectField label="Add an attribute" value="" onChange={(id) => id && setShown([...shown, Number(id)])} placeholder="Choose" options={addable.map((a) => ({ value: String(a.id), label: a.name }))} />
            </div>
          )}
          {error && <FormError message={error} />}
          {canEdit && (
            <div className="flex items-center gap-3">
              <Button variant="primary" onClick={save} busy={busy === 'save'} busyLabel="Saving…" disabled={!dirty}>Save specifications</Button>
              {dirty && <span className="text-sm text-amber-800">You have unsaved changes.</span>}
            </div>
          )}
        </div>
      )}
    </Card>
  );
}
