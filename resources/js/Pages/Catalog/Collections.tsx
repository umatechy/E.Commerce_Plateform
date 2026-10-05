import { FormEvent, useEffect, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, SwitchField, TextAreaField, TextField } from '@/Components/ui/Form';
import Badge from '@/Components/ui/Badge';
import { EmptyPanel, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import TranslationsPanel from '@/Components/TranslationsPanel';
import ProductPicker from '@/Components/Catalog/ProductPicker';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { fromMinor, toMinor } from '@/lib/money';
import {
  categoryTree,
  COLLECTION_SORTS,
  RULE_FIELDS,
  type Brand,
  type Category,
  type Collection,
  type CollectionRule,
  type ProductRef,
  type Tag,
} from '@/lib/catalog';

/**
 * Phase B39 — Module 05 §16, Module 06 §34: collections group products for
 * the storefront. Hand-picked ones list chosen products in a chosen order;
 * rule-based ones list every product that meets their conditions, now and
 * later. Either can be scheduled. The server checks every id and rule.
 */
type RuleField = (typeof RULE_FIELDS)[number];

const RULE_LABELS: Record<RuleField, string> = {
  category: 'In category', brand: 'Of brand', tag: 'Has tag', price_min: 'Price at least', price_max: 'Price at most',
  on_sale: 'On sale', in_stock: 'In stock', featured: 'Marked as featured', new_within_days: 'Published within (days)',
};

const SORT_LABELS: Record<(typeof COLLECTION_SORTS)[number], string> = {
  manual: 'Your order (hand-picked only)', featured: 'Featured first, then sort priority', newest: 'Newest first', price_asc: 'Price, low to high', price_desc: 'Price, high to low', name: 'Name, A to Z', best_selling: 'Best selling',
};

type Values = {
  name: string;
  description: string;
  type: 'manual' | 'rule';
  status: 'draft' | 'active';
  is_visible: boolean;
  sort: string;
  match: 'all' | 'any';
  starts_at: string;
  ends_at: string;
  rules: { field: RuleField; text: string; ids: number[] }[];
};

const BLANK: Values = { name: '', description: '', type: 'manual', status: 'active', is_visible: true, sort: 'manual', match: 'all', starts_at: '', ends_at: '', rules: [] };

/** An ISO time as the value of a datetime-local field (the browser's time zone), and back. */
function toLocalInput(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function fromLocalInput(value: string): string | null {
  return value === '' ? null : new Date(value).toISOString();
}

const isMoney = (field: RuleField) => field === 'price_min' || field === 'price_max';
const isList = (field: RuleField) => field === 'category' || field === 'brand' || field === 'tag';
const isFlag = (field: RuleField) => field === 'on_sale' || field === 'in_stock' || field === 'featured';

function fromCollection(c: Collection, currency: string): Values {
  return {
    name: c.name, description: c.description ?? '', type: c.type, status: c.status, is_visible: c.is_visible, sort: c.sort, match: c.match,
    starts_at: toLocalInput(c.starts_at), ends_at: toLocalInput(c.ends_at),
    rules: c.rules.map((rule) => ({
      field: rule.field,
      ids: Array.isArray(rule.value) ? rule.value : [],
      text: isMoney(rule.field) ? fromMinor(rule.value as number, currency) : typeof rule.value === 'number' ? String(rule.value) : '',
    })),
  };
}

export default function Collections() {
  const access = useAccess();
  const canManage = access.can('collections.manage');
  const list = useApi<{ data: Collection[] }>('/collections');
  const [editing, setEditing] = useState<Collection | 'new' | null>(null);
  const [picking, setPicking] = useState<Collection | null>(null);
  const [translating, setTranslating] = useState<Collection | null>(null);
  const [removing, setRemoving] = useState<Collection | null>(null);
  const { busy, run } = useAction();

  const columns: Column<Collection>[] = [
    {
      key: 'name', header: 'Collection',
      render: (c) => (
        <div>
          <span className="font-medium">{c.name}</span>
          <p className="font-mono text-xs text-slate-500">/collections/{c.slug}</p>
        </div>
      ),
    },
    { key: 'type', header: 'Kind', render: (c) => (c.type === 'manual' ? 'Hand-picked' : `Rules (${c.rules.length})`) },
    {
      key: 'live', header: 'On the storefront', priority: true,
      render: (c) => (c.is_live ? <Badge tone="green">Shown</Badge> : <Badge tone="neutral">{c.status === 'draft' ? 'Draft' : !c.is_visible ? 'Hidden' : 'Outside its schedule'}</Badge>),
    },
    { key: 'count', header: 'Products', align: 'right', render: (c) => (c.type === 'manual' ? c.product_count : 'By rules') },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (c) =>
        canManage && (
          <span className="flex flex-wrap justify-end gap-1">
            <Button size="sm" variant="ghost" onClick={() => setEditing(c)}>Edit<span className="sr-only"> {c.name}</span></Button>
            {c.type === 'manual' && <Button size="sm" variant="ghost" onClick={() => setPicking(c)}>Products<span className="sr-only"> of {c.name}</span></Button>}
            <Button size="sm" variant="ghost" onClick={() => setTranslating(c)}>Translate<span className="sr-only"> {c.name}</span></Button>
            <Button size="sm" variant="ghost" onClick={() => setRemoving(c)}>Delete<span className="sr-only"> {c.name}</span></Button>
          </span>
        ),
    },
  ];

  return (
    <AdminPage
      title="Collections"
      description="Group products for your storefront: hand-pick them, or let rules choose them. Each collection has its own page, and can fill a home page section."
      actions={canManage && <Button variant="primary" onClick={() => setEditing('new')}>Add collection</Button>}
    >
      <DataTable
        caption="Collections"
        columns={columns}
        rows={list.data?.data ?? null}
        rowKey={(c) => c.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No collections yet" description="For example “Eid picks” or “Under Rs 2,000”." action={canManage ? <Button variant="primary" onClick={() => setEditing('new')}>Add collection</Button> : undefined} />}
      />

      {editing !== null && (
        <CollectionDialog
          collection={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            list.reload();
          }}
        />
      )}

      {picking !== null && (
        <ProductsDialog
          collection={picking}
          onClose={() => setPicking(null)}
          onSaved={() => {
            setPicking(null);
            list.reload();
          }}
        />
      )}

      <Dialog open={translating !== null} title={`Translations — ${translating?.name ?? ''}`} onClose={() => setTranslating(null)}>
        {translating && <TranslationsPanel type="collection" id={translating.id} canEdit={canManage} />}
        <div className="mt-4 flex justify-end">
          <Button onClick={() => setTranslating(null)}>Close</Button>
        </div>
      </Dialog>

      <ConfirmDialog
        open={removing !== null}
        title="Delete this collection?"
        confirmLabel="Delete collection"
        busy={busy === 'delete'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/collections/${removing.id}`, { method: 'DELETE' }), { success: 'Collection deleted.' }).then((result) => {
            if (result !== undefined) {
              setRemoving(null);
              list.reload();
            }
          })
        }
      >
        <p>
          <strong>{removing?.name}</strong> and its page are removed from your storefront. Its products stay in your catalog. A promotion aimed at it no longer applies.
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}

function CollectionDialog({ collection, onClose, onSaved }: { collection: Collection | null; onClose: () => void; onSaved: () => void }) {
  const access = useAccess();
  const form = useForm<Values>(collection ? fromCollection(collection, access.currency) : BLANK);
  const [ruleError, setRuleError] = useState<string | null>(null);
  const { values, set } = form;
  const rulesNeeded = values.type === 'rule';
  const categories = useApi<{ data: Category[] }>(rulesNeeded ? '/categories' : null);
  const brands = useApi<{ data: Brand[] }>(rulesNeeded ? '/brands' : null);
  const tags = useApi<{ data: Tag[] }>(rulesNeeded ? '/tags' : null);

  const choices = (field: RuleField): { id: number; label: string }[] =>
    field === 'category'
      ? categoryTree(categories.data?.data ?? []).map(({ category, depth }) => ({ id: category.id, label: `${'— '.repeat(depth)}${category.name}` }))
      : field === 'brand'
        ? (brands.data?.data ?? []).map((b) => ({ id: b.id, label: b.name }))
        : (tags.data?.data ?? []).map((t) => ({ id: t.id, label: t.name }));

  function rules(): CollectionRule[] | null {
    const out: CollectionRule[] = [];
    for (const rule of values.rules) {
      if (isFlag(rule.field)) out.push({ field: rule.field, value: true });
      else if (isList(rule.field)) {
        if (rule.ids.length === 0) return null;
        out.push({ field: rule.field, value: rule.ids });
      } else if (isMoney(rule.field)) {
        const minor = toMinor(rule.text, access.currency);
        if (minor === null) return null;
        out.push({ field: rule.field, value: minor });
      } else {
        const days = Number(rule.text);
        if (!Number.isInteger(days) || days < 1) return null;
        out.push({ field: rule.field, value: days });
      }
    }

    return out;
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const ruleList = values.type === 'rule' ? rules() : [];
    if (ruleList === null || (values.type === 'rule' && ruleList.length === 0)) {
      setRuleError('Add at least one condition, and fill in each one.');

      return;
    }
    setRuleError(null);
    const body: Record<string, unknown> = {
      name: values.name,
      description: values.description === '' ? null : values.description,
      status: values.status,
      is_visible: values.is_visible,
      sort: values.sort,
      match: values.match,
      starts_at: fromLocalInput(values.starts_at),
      ends_at: fromLocalInput(values.ends_at),
      rules: values.type === 'rule' ? ruleList : null,
    };
    if (collection === null) body.type = values.type;
    const saved = await form.submit(
      () => adminFetch(collection === null ? '/collections' : `/collections/${collection.id}`, { method: collection === null ? 'POST' : 'PUT', body }),
      collection === null ? 'Collection created.' : 'Collection saved.',
    );
    if (saved !== undefined) onSaved();
  }

  const unused = RULE_FIELDS.filter((field) => !values.rules.some((rule) => rule.field === field));

  return (
    <Dialog open title={collection === null ? 'Add collection' : `Edit ${collection.name}`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Name" value={values.name} onChange={(v) => set('name', v)} error={form.errors.name} required maxLength={120} data-autofocus />
        <TextAreaField label="Description" optional rows={2} value={values.description} onChange={(v) => set('description', v)} error={form.errors.description} hint="Shown at the top of the collection's page." />
        {collection === null ? (
          <SelectField
            label="Kind"
            value={values.type}
            onChange={(v) => {
              set('type', v as Values['type']);
              if (v === 'rule' && values.sort === 'manual') set('sort', 'newest');
            }}
            options={[{ value: 'manual', label: 'Hand-picked products' }, { value: 'rule', label: 'Products chosen by rules' }]}
            hint="Cannot be changed later."
          />
        ) : (
          <p className="text-sm text-slate-700">Kind: {values.type === 'manual' ? 'hand-picked products' : 'products chosen by rules'}</p>
        )}

        {values.type === 'rule' && (
          <fieldset className="space-y-3 rounded-md border border-slate-200 p-3">
            <legend className="px-1 text-sm font-medium text-slate-700">Conditions</legend>
            <SelectField label="A product is included when it meets" value={values.match} onChange={(v) => set('match', v as Values['match'])} options={[{ value: 'all', label: 'all conditions' }, { value: 'any', label: 'any condition' }]} />
            {values.rules.map((rule, index) => (
              <div key={rule.field} className="rounded-md bg-slate-50 p-2">
                <div className="flex items-center justify-between gap-2">
                  <p className="text-sm font-medium">{RULE_LABELS[rule.field]}</p>
                  <Button size="sm" variant="ghost" onClick={() => set('rules', values.rules.filter((_, i) => i !== index))}>Remove<span className="sr-only"> {RULE_LABELS[rule.field]}</span></Button>
                </div>
                {isList(rule.field) && (
                  <div className="mt-1 grid max-h-40 gap-1 overflow-y-auto sm:grid-cols-2">
                    {choices(rule.field).length === 0 && <p className="text-sm text-slate-600">Nothing to choose yet.</p>}
                    {choices(rule.field).map((choice) => (
                      <CheckboxField
                        key={choice.id}
                        label={choice.label}
                        checked={rule.ids.includes(choice.id)}
                        onChange={(checked) =>
                          set('rules', values.rules.map((r, i) => (i === index ? { ...r, ids: checked ? [...r.ids, choice.id] : r.ids.filter((id) => id !== choice.id) } : r)))
                        }
                      />
                    ))}
                  </div>
                )}
                {(isMoney(rule.field) || rule.field === 'new_within_days') && (
                  <TextField
                    label={isMoney(rule.field) ? `Amount (${access.currency})` : 'Days'}
                    inputMode={isMoney(rule.field) ? 'decimal' : 'numeric'}
                    value={rule.text}
                    onChange={(v) => set('rules', values.rules.map((r, i) => (i === index ? { ...r, text: v } : r)))}
                  />
                )}
              </div>
            ))}
            {unused.length > 0 && (
              <SelectField
                label="Add a condition"
                value=""
                onChange={(v) => v && set('rules', [...values.rules, { field: v as RuleField, text: '', ids: [] }])}
                placeholder="Choose a condition"
                options={unused.map((field) => ({ value: field, label: RULE_LABELS[field] }))}
              />
            )}
            {(ruleError || form.errors.rules) && <p role="alert" className="text-sm font-medium text-red-700">{ruleError ?? form.errors.rules}</p>}
          </fieldset>
        )}

        <SelectField
          label="Order on its page"
          value={values.sort}
          onChange={(v) => set('sort', v)}
          options={COLLECTION_SORTS.filter((s) => values.type === 'manual' || s !== 'manual').map((s) => ({ value: s, label: SORT_LABELS[s] }))}
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField label="Status" value={values.status} onChange={(v) => set('status', v as Values['status'])} options={[{ value: 'active', label: 'Active' }, { value: 'draft', label: 'Draft' }]} />
          <div className="pt-6">
            <SwitchField label="Visible on the storefront" checked={values.is_visible} onChange={(v) => set('is_visible', v)} />
          </div>
          <TextField label="Show from" optional type="datetime-local" value={values.starts_at} onChange={(v) => set('starts_at', v)} error={form.errors.starts_at} hint="Your computer's time zone." />
          <TextField label="Show until" optional type="datetime-local" value={values.ends_at} onChange={(v) => set('ends_at', v)} error={form.errors.ends_at} />
        </div>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save collection</Button>
        </div>
      </form>
    </Dialog>
  );
}

function ProductsDialog({ collection, onClose, onSaved }: { collection: Collection; onClose: () => void; onSaved: () => void }) {
  const state = useApi<{ data: Collection }>(`/collections/${collection.id}`);
  const [value, setValue] = useState<ProductRef[] | null>(null);
  const { busy, run } = useAction();

  useEffect(() => {
    if (state.data) setValue((state.data.data.products ?? []).map((p) => ({ id: p.id, name: p.name, status: p.status })));
  }, [state.data]);

  return (
    <Dialog open title={`Products of ${collection.name}`} onClose={onClose} busy={busy === 'save'}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : value === null ? (
        <Skeleton lines={4} />
      ) : (
        <div className="space-y-4">
          <ProductPicker label="Products, in the order shown" value={value} onChange={setValue} max={500} />
          <div className="flex justify-end gap-2">
            <Button onClick={onClose} disabled={busy === 'save'}>Cancel</Button>
            <Button
              variant="primary"
              busy={busy === 'save'}
              busyLabel="Saving…"
              onClick={() =>
                run('save', () => adminFetch(`/collections/${collection.id}/products`, { method: 'PUT', body: { products: value.map((p) => p.id) } }), { success: 'Products saved.' }).then((r) => {
                  if (r !== undefined) onSaved();
                })
              }
            >
              Save products
            </Button>
          </div>
        </div>
      )}
    </Dialog>
  );
}
