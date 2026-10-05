import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { StatusBadge } from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import TranslationsPanel from '@/Components/TranslationsPanel';
import CategoryAttributesDialog from '@/Components/Catalog/CategoryAttributesDialog';
import StarterTemplateDialog from '@/Components/Catalog/StarterTemplateDialog';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { options } from '@/lib/labels';
import { CATEGORY_STATUSES, CATEGORY_VISIBILITIES, categoryTree, LISTING_SORTS, type Category } from '@/lib/catalog';

/**
 * Module 07 "Category Management": the store's category tree
 * (/api/v1/categories). The server refuses a category as its own parent
 * and any circular tree; its message is shown on the Parent field.
 */
type Values = { name: string; parent_id: string; description: string; status: string; visibility: string; sort_order: string; default_sort: string };

const BLANK: Values = { name: '', parent_id: '', description: '', status: 'active', visibility: 'public', sort_order: '0', default_sort: '' };

export default function Categories() {
  const access = useAccess();
  const canManage = access.can('categories.manage');
  const list = useApi<{ data: Category[] }>('/categories');
  const [editing, setEditing] = useState<Category | 'new' | null>(null);
  const [removing, setRemoving] = useState<Category | null>(null);
  // Phase B38: the category's name and description in other storefront languages.
  const [translating, setTranslating] = useState<Category | null>(null);
  // Phase B41: the attributes that apply to a category and its storefront filters.
  const [attributing, setAttributing] = useState<Category | null>(null);
  const canAttributes = canManage || access.can('attributes.manage');
  // Phase B45 (Module 07 §105): a starter template writes categories and attributes, so it needs both.
  const canTemplate = access.can('categories.manage') && access.can('attributes.manage');
  const [templating, setTemplating] = useState(false);
  const form = useForm<Values>(BLANK);
  const { busy, run } = useAction();

  const categories = list.data?.data ?? null;
  const tree = categoryTree(categories ?? []);
  const rows = categories === null ? null : tree;

  function open(target: Category | 'new') {
    form.reset(
      target === 'new'
        ? BLANK
        : { name: target.name, parent_id: target.parent_id === null ? '' : String(target.parent_id), description: target.description ?? '', status: target.status, visibility: target.visibility, sort_order: String(target.sort_order), default_sort: target.default_sort ?? '' },
    );
    setEditing(target);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const target = editing;
    const v = form.values;
    const body = {
      name: v.name,
      parent_id: v.parent_id === '' ? null : Number(v.parent_id),
      description: v.description === '' ? null : v.description,
      status: v.status,
      visibility: v.visibility,
      sort_order: v.sort_order === '' ? 0 : Number(v.sort_order),
      default_sort: v.default_sort === '' ? null : v.default_sort,
    };
    const saved = await form.submit(
      () => adminFetch(target === 'new' ? '/categories' : `/categories/${(target as Category).id}`, { method: target === 'new' ? 'POST' : 'PUT', body }),
      target === 'new' ? 'Category created.' : 'Category saved.',
    );
    if (saved !== undefined) {
      setEditing(null);
      list.reload();
    }
  }

  const columns: Column<{ category: Category; depth: number }>[] = [
    {
      key: 'name',
      header: 'Category',
      render: ({ category, depth }) => (
        <span style={{ paddingLeft: `${depth * 18}px` }} className="font-medium">
          {depth > 0 && <span aria-hidden="true" className="text-slate-400">↳ </span>}
          {category.name}
        </span>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: ({ category }) => <StatusBadge status={category.status} /> },
    { key: 'visibility', header: 'Visibility', render: ({ category }) => <StatusBadge status={category.visibility} /> },
    { key: 'order', header: 'Order', align: 'right', render: ({ category }) => category.sort_order },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: ({ category }) =>
        (canManage || canAttributes) && (
          <span className="flex flex-wrap justify-end gap-1">
            {canAttributes && <Button size="sm" variant="ghost" onClick={() => setAttributing(category)}>Attributes &amp; filters<span className="sr-only"> of {category.name}</span></Button>}
            {canManage && <Button size="sm" variant="ghost" onClick={() => open(category)}>Edit<span className="sr-only"> {category.name}</span></Button>}
            {canManage && <Button size="sm" variant="ghost" onClick={() => setTranslating(category)}>Translate<span className="sr-only"> {category.name}</span></Button>}
            {canManage && <Button size="sm" variant="ghost" onClick={() => setRemoving(category)}>Delete<span className="sr-only"> {category.name}</span></Button>}
          </span>
        ),
    },
  ];

  // A category cannot be put under itself; the server also refuses its own descendants.
  const parentOptions = tree
    .filter(({ category }) => editing === 'new' || editing === null || category.id !== editing.id)
    .map(({ category, depth }) => ({ value: String(category.id), label: `${'— '.repeat(depth)}${category.name}` }));

  return (
    <AdminPage
      title="Categories"
      description="How your products are grouped on the storefront."
      actions={
        (canManage || canTemplate) && (
          <>
            {canTemplate && <Button onClick={() => setTemplating(true)}>Start from a template</Button>}
            {canManage && <Button variant="primary" onClick={() => open('new')}>Add category</Button>}
          </>
        )
      }
    >
      <DataTable
        caption="Categories"
        columns={columns}
        rows={rows}
        rowKey={({ category }) => category.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={
          <EmptyPanel
            title="No categories yet"
            description="Categories help customers find products. Start with the ready-made structure for what you sell, or add your own."
            action={
              canManage || canTemplate ? (
                <span className="flex flex-wrap justify-center gap-2">
                  {canTemplate && <Button variant="primary" onClick={() => setTemplating(true)}>Start from a template</Button>}
                  {canManage && <Button onClick={() => open('new')}>Add category</Button>}
                </span>
              ) : undefined
            }
          />
        }
      />

      <Dialog open={editing !== null} title={editing === 'new' ? 'Add category' : 'Edit category'} onClose={() => setEditing(null)} busy={form.busy}>
        <form onSubmit={save} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} data-autofocus />
          <SelectField label="Parent" optional value={form.values.parent_id} onChange={(v) => form.set('parent_id', v)} error={form.errors.parent_id} placeholder="None (top level)" options={parentOptions} />
          <TextAreaField label="Description" optional rows={3} value={form.values.description} onChange={(v) => form.set('description', v)} error={form.errors.description} />
          <div className="grid gap-4 sm:grid-cols-3">
            <SelectField label="Status" value={form.values.status} onChange={(v) => form.set('status', v)} options={options(CATEGORY_STATUSES)} error={form.errors.status} />
            <SelectField label="Visibility" value={form.values.visibility} onChange={(v) => form.set('visibility', v)} options={options(CATEGORY_VISIBILITIES)} error={form.errors.visibility} />
            <TextField label="Order" type="number" min={0} value={form.values.sort_order} onChange={(v) => form.set('sort_order', v)} error={form.errors.sort_order} hint="Lower comes first." />
            {/* Phase B43 (Module 07 §17). */}
            <SelectField
              label="Products on its page"
              optional
              value={form.values.default_sort}
              onChange={(v) => form.set('default_sort', v)}
              error={form.errors.default_sort}
              placeholder="Your store's default order"
              options={Object.entries(LISTING_SORTS).map(([value, label]) => ({ value, label }))}
              hint="The order shoppers see first; they can still choose another."
            />
          </div>
          <div className="flex justify-end gap-2">
            <Button onClick={() => setEditing(null)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save category</Button>
          </div>
        </form>
      </Dialog>

      {templating && (
        <StarterTemplateDialog
          onClose={() => setTemplating(false)}
          onApplied={() => {
            setTemplating(false);
            list.reload();
          }}
        />
      )}

      {attributing && <CategoryAttributesDialog category={attributing} canEdit={canManage} onClose={() => setAttributing(null)} />}

      <Dialog open={translating !== null} title={`Translations — ${translating?.name ?? ''}`} onClose={() => setTranslating(null)}>
        {translating && <TranslationsPanel type="category" id={translating.id} canEdit={canManage} />}
        <div className="mt-4 flex justify-end">
          <Button onClick={() => setTranslating(null)}>Close</Button>
        </div>
      </Dialog>

      <ConfirmDialog
        open={removing !== null}
        title="Delete this category?"
        confirmLabel="Delete category"
        busy={busy === 'delete'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/categories/${removing.id}`, { method: 'DELETE' }), { success: 'Category deleted.' }).then((result) => {
            if (result !== undefined) {
              setRemoving(null);
              list.reload();
            }
          })
        }
      >
        <p>
          <strong>{removing?.name}</strong> will be removed. Its sub-categories move to the top level; its products stay in your catalog. This cannot be undone from the admin.
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}
