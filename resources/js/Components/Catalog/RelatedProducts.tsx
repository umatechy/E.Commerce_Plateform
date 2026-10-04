import { useEffect, useState } from 'react';
import Button from '@/Components/ui/Button';
import { Card, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import ProductPicker from '@/Components/Catalog/ProductPicker';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { RELATION_LABELS, RELATION_TYPES, type ProductRef } from '@/lib/catalog';

type Relations = Record<(typeof RELATION_TYPES)[number], ProductRef[]>;

/**
 * Phase B39 (Module 06 §38): the products shown with this one on its page —
 * related, goes well with (cross-sell), better options (up-sell) and
 * alternatives. Saved together (PUT /products/{id}/relations).
 */
export default function RelatedProducts({ productId, canEdit }: { productId: string; canEdit: boolean }) {
  const state = useApi<{ data: Relations }>(`/products/${productId}/relations`);
  const [value, setValue] = useState<Relations | null>(null);
  const [dirty, setDirty] = useState(false);
  const { busy, run } = useAction();

  useEffect(() => {
    if (state.data) {
      setValue(state.data.data);
      setDirty(false);
    }
  }, [state.data]);

  async function save() {
    if (value === null) return;
    const body = { relations: Object.fromEntries(RELATION_TYPES.map((type) => [type, value[type].map((product) => product.id)])) };
    const saved = await run('save', () => adminFetch<{ data: Relations }>(`/products/${productId}/relations`, { method: 'PUT', body }), { success: 'Related products saved.' });
    if (saved) state.setData(saved);
  }

  return (
    <Card title="Shown with this product" description="Chosen products appear on this product's page, in this order. Products that are not active are not shown to shoppers.">
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : value === null ? (
        <Skeleton lines={3} />
      ) : (
        <div className="space-y-6">
          {RELATION_TYPES.map((type) => (
            <div key={type}>
              <ProductPicker
                label={RELATION_LABELS[type].title}
                value={value[type]}
                onChange={(next) => {
                  setValue({ ...value, [type]: next });
                  setDirty(true);
                }}
                max={20}
                exclude={[productId]}
                disabled={!canEdit}
              />
              <p className="mt-1 text-xs text-slate-500">{RELATION_LABELS[type].hint}</p>
            </div>
          ))}
          {canEdit && (
            <div className="flex items-center gap-3">
              <Button variant="primary" onClick={save} busy={busy === 'save'} busyLabel="Saving…" disabled={!dirty}>Save related products</Button>
              {dirty && <span className="text-sm text-amber-800">You have unsaved changes.</span>}
            </div>
          )}
        </div>
      )}
    </Card>
  );
}
