import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, TextAreaField, TextField } from '@/Components/ui/Form';
import { EmptyPanel } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import TranslationsPanel from '@/Components/TranslationsPanel';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import type { Brand } from '@/lib/catalog';

/** Module 07 "Brand Management": the brands a store sells (/api/v1/brands). */
type Values = { name: string; description: string };

const BLANK: Values = { name: '', description: '' };

export default function Brands() {
  const access = useAccess();
  const canManage = access.can('brands.manage');
  const list = useApi<{ data: Brand[] }>('/brands');
  const [editing, setEditing] = useState<Brand | 'new' | null>(null);
  const [removing, setRemoving] = useState<Brand | null>(null);
  // Phase B38: the brand's name and description in other storefront languages.
  const [translating, setTranslating] = useState<Brand | null>(null);
  const form = useForm<Values>(BLANK);
  const { busy, run } = useAction();

  function open(target: Brand | 'new') {
    form.reset(target === 'new' ? BLANK : { name: target.name, description: target.description ?? '' });
    setEditing(target);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const target = editing;
    const body = { name: form.values.name, description: form.values.description === '' ? null : form.values.description };
    const saved = await form.submit(
      () => adminFetch(target === 'new' ? '/brands' : `/brands/${(target as Brand).id}`, { method: target === 'new' ? 'POST' : 'PUT', body }),
      target === 'new' ? 'Brand created.' : 'Brand saved.',
    );
    if (saved !== undefined) {
      setEditing(null);
      list.reload();
    }
  }

  const columns: Column<Brand>[] = [
    { key: 'name', header: 'Brand', render: (brand) => <span className="font-medium">{brand.name}</span> },
    { key: 'description', header: 'Description', render: (brand) => <span className="text-slate-600">{brand.description ?? '—'}</span> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (brand) =>
        canManage && (
          <span className="flex justify-end gap-1">
            <Button size="sm" variant="ghost" onClick={() => open(brand)}>Edit<span className="sr-only"> {brand.name}</span></Button>
            <Button size="sm" variant="ghost" onClick={() => setTranslating(brand)}>Translate<span className="sr-only"> {brand.name}</span></Button>
            <Button size="sm" variant="ghost" onClick={() => setRemoving(brand)}>Delete<span className="sr-only"> {brand.name}</span></Button>
          </span>
        ),
    },
  ];

  return (
    <AdminPage title="Brands" description="The brands you sell. A product can carry one brand." actions={canManage && <Button variant="primary" onClick={() => open('new')}>Add brand</Button>}>
      <DataTable
        caption="Brands"
        columns={columns}
        rows={list.data?.data ?? null}
        rowKey={(brand) => brand.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No brands yet" action={canManage ? <Button variant="primary" onClick={() => open('new')}>Add brand</Button> : undefined} />}
      />

      <Dialog open={editing !== null} title={editing === 'new' ? 'Add brand' : 'Edit brand'} onClose={() => setEditing(null)} busy={form.busy}>
        <form onSubmit={save} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} data-autofocus />
          <TextAreaField label="Description" optional rows={3} value={form.values.description} onChange={(v) => form.set('description', v)} error={form.errors.description} />
          <div className="flex justify-end gap-2">
            <Button onClick={() => setEditing(null)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save brand</Button>
          </div>
        </form>
      </Dialog>

      <Dialog open={translating !== null} title={`Translations — ${translating?.name ?? ''}`} onClose={() => setTranslating(null)}>
        {translating && <TranslationsPanel type="brand" id={translating.id} canEdit={canManage} />}
        <div className="mt-4 flex justify-end">
          <Button onClick={() => setTranslating(null)}>Close</Button>
        </div>
      </Dialog>

      <ConfirmDialog
        open={removing !== null}
        title="Delete this brand?"
        confirmLabel="Delete brand"
        busy={busy === 'delete'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/brands/${removing.id}`, { method: 'DELETE' }), { success: 'Brand deleted.' }).then((result) => {
            if (result !== undefined) {
              setRemoving(null);
              list.reload();
            }
          })
        }
      >
        <p>
          <strong>{removing?.name}</strong> will be removed. Its products stay in your catalog, without a brand. This cannot be undone from the admin.
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}
