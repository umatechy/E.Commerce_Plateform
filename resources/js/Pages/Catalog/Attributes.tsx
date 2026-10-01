import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import Badge, { humanize } from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { options } from '@/lib/labels';
import { ATTRIBUTE_TYPES, type Attribute } from '@/lib/catalog';

/**
 * Module 07 "Attribute Management": options such as size and colour
 * (/api/v1/attributes). The API creates and deletes attributes; it has no
 * edit, so this page does not offer one.
 */
type Values = { name: string; key: string; type: string; values: string };

const BLANK: Values = { name: '', key: '', type: 'select', values: '' };

export default function Attributes() {
  const access = useAccess();
  const canManage = access.can('attributes.manage');
  const list = useApi<{ data: Attribute[] }>('/attributes');
  const [adding, setAdding] = useState(false);
  const [removing, setRemoving] = useState<Attribute | null>(null);
  const form = useForm<Values>(BLANK);
  const { busy, run } = useAction();

  function open() {
    form.reset(BLANK);
    setAdding(true);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const v = form.values;
    const body = { name: v.name, key: v.key, type: v.type, values: v.values.split('\n').map((line) => line.trim()).filter((line) => line !== '') };
    const saved = await form.submit(() => adminFetch('/attributes', { method: 'POST', body }), 'Attribute created.');
    if (saved !== undefined) {
      setAdding(false);
      list.reload();
    }
  }

  const columns: Column<Attribute>[] = [
    {
      key: 'name',
      header: 'Attribute',
      render: (attribute) => (
        <div>
          <span className="font-medium">{attribute.name}</span>
          <p className="font-mono text-xs text-slate-500">{attribute.key}</p>
        </div>
      ),
    },
    { key: 'type', header: 'Type', render: (attribute) => humanize(attribute.type) },
    {
      key: 'values',
      header: 'Values',
      render: (attribute) =>
        (attribute.values ?? []).length === 0 ? '—' : (
          <span className="flex flex-wrap gap-1">
            {(attribute.values ?? []).map((value) => (
              <Badge key={value}>{value}</Badge>
            ))}
          </span>
        ),
    },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (attribute) => canManage && <Button size="sm" variant="ghost" onClick={() => setRemoving(attribute)}>Delete<span className="sr-only"> {attribute.name}</span></Button>,
    },
  ];

  return (
    <AdminPage title="Attributes" description="Options your products come in, such as size or colour." actions={canManage && <Button variant="primary" onClick={open}>Add attribute</Button>}>
      <DataTable
        caption="Attributes"
        columns={columns}
        rows={list.data?.data ?? null}
        rowKey={(attribute) => attribute.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No attributes yet" action={canManage ? <Button variant="primary" onClick={open}>Add attribute</Button> : undefined} />}
      />

      <Dialog open={adding} title="Add attribute" description="An attribute cannot be edited afterwards. To change one, delete it and add it again." onClose={() => setAdding(false)} busy={form.busy}>
        <form onSubmit={save} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} data-autofocus />
          <TextField label="Key" value={form.values.key} onChange={(v) => form.set('key', v)} error={form.errors.key} required maxLength={64} hint="Letters, numbers, dashes and underscores. For example: size" />
          <SelectField label="Type" value={form.values.type} onChange={(v) => form.set('type', v)} options={options(ATTRIBUTE_TYPES)} error={form.errors.type} />
          <TextAreaField label="Values" optional rows={4} value={form.values.values} onChange={(v) => form.set('values', v)} error={form.errors.values} hint="One per line. For example: S, M, L on three lines." />
          <div className="flex justify-end gap-2">
            <Button onClick={() => setAdding(false)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Add attribute</Button>
          </div>
        </form>
      </Dialog>

      <ConfirmDialog
        open={removing !== null}
        title="Delete this attribute?"
        confirmLabel="Delete attribute"
        busy={busy === 'delete'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/attributes/${removing.id}`, { method: 'DELETE' }), { success: 'Attribute deleted.' }).then((result) => {
            if (result !== undefined) {
              setRemoving(null);
              list.reload();
            }
          })
        }
      >
        <p>
          <strong>{removing?.name}</strong> and its values are deleted for good. This cannot be undone.
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}
