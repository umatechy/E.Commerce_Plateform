import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, SwitchField, TextField } from '@/Components/ui/Form';
import Badge, { humanize } from '@/Components/ui/Badge';
import { Card, EmptyPanel } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { options } from '@/lib/labels';
import { ATTRIBUTE_TYPES, hasValues, type Attribute, type AttributeSet } from '@/lib/catalog';
import AttributeTranslationsDialog from '@/Components/Catalog/AttributeTranslationsDialog';

/**
 * Module 07 "Attribute Management" (/api/v1/attributes). Phase B41: an
 * attribute is edited in place — name, group, unit, active, and its values
 * in order (a colour attribute's values carry a colour code). A value that
 * products use is made inactive instead of being deleted. Attribute sets
 * group attributes to apply to a category in one step.
 */
type Row = { id?: number; value: string; color_code: string; is_active: boolean };
type Values = { name: string; key: string; type: string; group: string; unit: string; is_active: boolean; rows: Row[] };

const BLANK: Values = { name: '', key: '', type: 'select', group: '', unit: '', is_active: true, rows: [] };

function fromAttribute(attribute: Attribute): Values {
  return {
    name: attribute.name, key: attribute.key, type: attribute.type, group: attribute.group ?? '', unit: attribute.unit ?? '', is_active: attribute.is_active ?? true,
    rows: (attribute.options ?? []).map((o) => ({ id: o.id, value: o.value, color_code: o.color_code ?? '', is_active: o.is_active })),
  };
}

export default function Attributes() {
  const access = useAccess();
  const canManage = access.can('attributes.manage');
  const list = useApi<{ data: Attribute[] }>('/attributes');
  const sets = useApi<{ data: AttributeSet[] }>('/attribute-sets');
  const [editing, setEditing] = useState<Attribute | 'new' | null>(null);
  const [editingSet, setEditingSet] = useState<AttributeSet | 'new' | null>(null);
  const [removing, setRemoving] = useState<Attribute | null>(null);
  // Phase B42: the attribute's name and values in other storefront languages.
  const [translating, setTranslating] = useState<Attribute | null>(null);
  const { busy, run } = useAction();
  const attributes = list.data?.data ?? [];

  const columns: Column<Attribute>[] = [
    {
      key: 'name',
      header: 'Attribute',
      render: (attribute) => (
        <div>
          <span className="font-medium">{attribute.name}</span>
          {attribute.is_active === false && <span className="ms-2"><Badge tone="neutral">Inactive</Badge></span>}
          <p className="font-mono text-xs text-slate-500">{attribute.key}{attribute.group ? ` · ${attribute.group}` : ''}</p>
        </div>
      ),
    },
    { key: 'type', header: 'Type', render: (attribute) => `${humanize(attribute.type)}${attribute.unit ? ` (${attribute.unit})` : ''}` },
    {
      key: 'values',
      header: 'Values',
      render: (attribute) =>
        (attribute.options ?? []).length === 0 ? '—' : (
          <span className="flex flex-wrap gap-1">
            {(attribute.options ?? []).filter((o) => o.is_active).map((o) => (
              <Badge key={o.id}>
                {o.color_code && <span aria-hidden className="me-1 inline-block h-2.5 w-2.5 rounded-full border border-slate-300" style={{ backgroundColor: o.color_code }} />}
                {o.value}
              </Badge>
            ))}
          </span>
        ),
    },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (attribute) =>
        canManage && (
          <span className="flex justify-end gap-1">
            <Button size="sm" variant="ghost" onClick={() => setEditing(attribute)}>Edit<span className="sr-only"> {attribute.name}</span></Button>
            <Button size="sm" variant="ghost" onClick={() => setTranslating(attribute)}>Translate<span className="sr-only"> {attribute.name}</span></Button>
            <Button size="sm" variant="ghost" onClick={() => setRemoving(attribute)}>Delete<span className="sr-only"> {attribute.name}</span></Button>
          </span>
        ),
    },
  ];

  return (
    <AdminPage title="Attributes" description="Properties of your products, such as size, colour or RAM. Categories choose which ones apply and which shoppers can filter by." actions={canManage && <Button variant="primary" onClick={() => setEditing('new')}>Add attribute</Button>}>
      <DataTable
        caption="Attributes"
        columns={columns}
        rows={list.data?.data ?? null}
        rowKey={(attribute) => attribute.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No attributes yet" action={canManage ? <Button variant="primary" onClick={() => setEditing('new')}>Add attribute</Button> : undefined} />}
      />

      <div className="mt-6">
        <Card
          title="Attribute sets"
          description="A named list of attributes — for example “Laptops”: processor, RAM, storage — to apply to a category in one step."
          actions={canManage && <Button size="sm" onClick={() => setEditingSet('new')}>Add set</Button>}
        >
          {(sets.data?.data ?? []).length === 0 ? (
            <p className="text-sm text-slate-600">No sets yet.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {(sets.data?.data ?? []).map((set) => (
                <li key={set.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                  <span>
                    <span className="font-medium">{set.name}</span>
                    <span className="ms-2 text-slate-600">{set.attributes.map((id) => attributes.find((a) => a.id === id)?.name).filter(Boolean).join(', ') || 'No attributes'}</span>
                  </span>
                  {canManage && (
                    <span className="flex gap-1">
                      <Button size="sm" variant="ghost" onClick={() => setEditingSet(set)}>Edit<span className="sr-only"> {set.name}</span></Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        busy={busy === `set-${set.id}`}
                        onClick={() => run(`set-${set.id}`, () => adminFetch(`/attribute-sets/${set.id}`, { method: 'DELETE' }), { success: 'Set deleted.' }).then((r) => r !== undefined && sets.reload())}
                      >
                        Delete<span className="sr-only"> {set.name}</span>
                      </Button>
                    </span>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {editing !== null && <AttributeDialog attribute={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); list.reload(); }} />}
      {translating !== null && <AttributeTranslationsDialog attribute={translating} canEdit={canManage} onClose={() => setTranslating(null)} />}
      {editingSet !== null && <SetDialog set={editingSet === 'new' ? null : editingSet} attributes={attributes} onClose={() => setEditingSet(null)} onSaved={() => { setEditingSet(null); sets.reload(); }} />}

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
          <strong>{removing?.name}</strong>, its values and every product&apos;s value for it are deleted for good. To keep them, make the attribute inactive instead. This cannot be undone.
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}

function AttributeDialog({ attribute, onClose, onSaved }: { attribute: Attribute | null; onClose: () => void; onSaved: () => void }) {
  const form = useForm<Values>(attribute ? fromAttribute(attribute) : BLANK);
  const [draft, setDraft] = useState('');
  const { values: v, set } = form;
  const choices = hasValues(v.type);

  function addValues() {
    const added = draft.split(/\n|,/).map((s) => s.trim()).filter((s) => s !== '' && !v.rows.some((r) => r.value.toLowerCase() === s.toLowerCase()));
    set('rows', [...v.rows, ...added.map((value) => ({ value, color_code: '', is_active: true }))]);
    setDraft('');
  }

  function move(index: number, by: number) {
    const next = [...v.rows];
    const [row] = next.splice(index, 1);
    next.splice(index + by, 0, row);
    set('rows', next);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const rows = choices ? v.rows.map((r) => ({ ...(r.id ? { id: r.id } : {}), value: r.value, color_code: r.color_code === '' ? null : r.color_code, is_active: r.is_active })) : [];
    const body = attribute === null
      ? { name: v.name, key: v.key, type: v.type, group: v.group || null, unit: v.unit || null, values: rows.map((r) => ({ value: r.value, color_code: r.color_code })) }
      : { name: v.name, type: v.type, group: v.group || null, unit: v.unit || null, is_active: v.is_active, values: rows };
    const saved = await form.submit(
      () => adminFetch<{ data: Attribute }>(attribute === null ? '/attributes' : `/attributes/${attribute.id}`, { method: attribute === null ? 'POST' : 'PUT', body }),
      attribute === null ? 'Attribute created.' : 'Attribute saved.',
    );
    if (saved !== undefined) onSaved();
  }

  return (
    <Dialog open wide title={attribute === null ? 'Add attribute' : `Edit ${attribute.name}`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} errors={form.errors} />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Name" value={v.name} onChange={(x) => set('name', x)} error={form.errors.name} required maxLength={255} data-autofocus />
          {attribute === null ? (
            <TextField label="Key" value={v.key} onChange={(x) => set('key', x)} error={form.errors.key} required maxLength={64} hint="Letters, numbers, dashes and underscores; used in filter addresses. For example: ram" />
          ) : (
            <TextField label="Key" value={v.key} onChange={() => undefined} disabled hint="Fixed: filter addresses use it." />
          )}
          <SelectField label="Type" value={v.type} onChange={(x) => set('type', x)} options={options(ATTRIBUTE_TYPES)} error={form.errors.type} hint={attribute ? 'Cannot change once products use it.' : 'Colour shows swatches; number can have a unit.'} />
          <TextField label="Group" optional value={v.group} onChange={(x) => set('group', x)} maxLength={80} hint="For example: Technical details" />
          {v.type === 'numeric' && <TextField label="Unit" optional value={v.unit} onChange={(x) => set('unit', x)} maxLength={20} hint="For example: inch, GB, kg" />}
          {attribute !== null && <div className="pt-6"><SwitchField label="Active" hint="Inactive attributes are not shown or offered as filters." checked={v.is_active} onChange={(x) => set('is_active', x)} /></div>}
        </div>

        {choices && (
          <fieldset className="space-y-2">
            <legend className="text-sm font-medium text-slate-700">Values, in the order shoppers see them</legend>
            {v.rows.length === 0 && <p className="text-sm text-slate-600">No values yet.</p>}
            {v.rows.map((row, index) => (
              <div key={row.id ?? `new-${index}`} className="flex flex-wrap items-end gap-2 rounded-md bg-slate-50 p-2">
                <div className="min-w-40 flex-1"><TextField label={`Value ${index + 1}`} value={row.value} onChange={(x) => set('rows', v.rows.map((r, i) => (i === index ? { ...r, value: x } : r)))} maxLength={255} /></div>
                {v.type === 'color' && (
                  <div className="w-36">
                    <TextField label="Colour code" optional value={row.color_code} placeholder="#1A2B3C" onChange={(x) => set('rows', v.rows.map((r, i) => (i === index ? { ...r, color_code: x } : r)))} maxLength={7} />
                  </div>
                )}
                {row.id !== undefined && <CheckboxField label="Offered" checked={row.is_active} onChange={(x) => set('rows', v.rows.map((r, i) => (i === index ? { ...r, is_active: x } : r)))} />}
                <Button size="sm" variant="ghost" disabled={index === 0} onClick={() => move(index, -1)}>Up<span className="sr-only"> {row.value}</span></Button>
                <Button size="sm" variant="ghost" disabled={index === v.rows.length - 1} onClick={() => move(index, 1)}>Down<span className="sr-only"> {row.value}</span></Button>
                <Button size="sm" variant="ghost" onClick={() => set('rows', v.rows.filter((_, i) => i !== index))}>Remove<span className="sr-only"> {row.value}</span></Button>
              </div>
            ))}
            <div className="flex items-end gap-2">
              <div className="flex-1"><TextField label="Add values" optional value={draft} onChange={setDraft} hint="Separate several with commas. A removed value that products use stays, as not offered." /></div>
              <Button onClick={addValues} disabled={draft.trim() === ''}>Add</Button>
            </div>
          </fieldset>
        )}

        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">{attribute === null ? 'Add attribute' : 'Save attribute'}</Button>
        </div>
      </form>
    </Dialog>
  );
}

function SetDialog({ set, attributes, onClose, onSaved }: { set: AttributeSet | null; attributes: Attribute[]; onClose: () => void; onSaved: () => void }) {
  const form = useForm<{ name: string; attributes: number[] }>({ name: set?.name ?? '', attributes: set?.attributes ?? [] });

  async function save(event: FormEvent) {
    event.preventDefault();
    const saved = await form.submit(
      () => adminFetch(set === null ? '/attribute-sets' : `/attribute-sets/${set.id}`, { method: set === null ? 'POST' : 'PUT', body: form.values }),
      set === null ? 'Set created.' : 'Set saved.',
    );
    if (saved !== undefined) onSaved();
  }

  return (
    <Dialog open title={set === null ? 'Add attribute set' : `Edit ${set.name}`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Name" value={form.values.name} onChange={(x) => form.set('name', x)} error={form.errors.name} required maxLength={120} data-autofocus />
        <fieldset>
          <legend className="text-sm font-medium text-slate-700">Attributes</legend>
          {attributes.length === 0 ? (
            <p className="mt-1 text-sm text-slate-600">Add attributes first.</p>
          ) : (
            <div className="mt-2 grid gap-2 sm:grid-cols-2">
              {attributes.map((a) => (
                <CheckboxField
                  key={a.id}
                  label={a.name}
                  checked={form.values.attributes.includes(a.id)}
                  onChange={(checked) => form.set('attributes', checked ? [...form.values.attributes, a.id] : form.values.attributes.filter((id) => id !== a.id))}
                />
              ))}
            </div>
          )}
        </fieldset>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save set</Button>
        </div>
      </form>
    </Dialog>
  );
}
