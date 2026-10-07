import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { EmptyPanel, QueryState } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { LISTING_SORTS } from '@/lib/catalog';

/**
 * Phase B45 follow-up (Module 07 §101, Module 03 §52): the starter
 * templates of the platform. Built-in templates are part of the code (their
 * content changes with a reviewed release); staff switch them off or keep
 * them for staff only. Templates saved from a store (Stores → a store →
 * "Save as a starter template") can also be renamed, offered to store
 * owners or deleted. Every change asks for the password again.
 */
export type TemplateRow = {
  key: string;
  name: string;
  summary: string;
  version: number;
  source: 'built_in' | 'store';
  business_category: string | null;
  is_active: boolean;
  offered_to_stores: boolean;
  source_store: { id: number; name: string | null } | null;
  categories: number;
  attributes: number;
  brands: number;
  urdu: number;
  stores_using: number;
};

type Detail = {
  categories: { name: string; description: string | null; children: string[]; filters: string[] }[];
  attributes: { key: string; name: string; type: string; values: string[] }[];
  brands: string[];
  themes: string[];
  default_sort: string | null;
};

type Option = { value: string; label: string };

export default function StarterTemplates() {
  const list = useApi<{ data: TemplateRow[] }>('/super-admin/starter-templates');
  const categories = useApi<{ data: Option[] }>('/super-admin/business-categories');
  const [viewing, setViewing] = useState<TemplateRow | null>(null);
  const [editing, setEditing] = useState<TemplateRow | null>(null);
  const [removing, setRemoving] = useState<TemplateRow | null>(null);
  const { busy, run } = useAction();
  const sells = (key: string | null) => (categories.data?.data ?? []).find((c) => c.value === key)?.label ?? key ?? '—';

  const columns: Column<TemplateRow>[] = [
    {
      key: 'name', header: 'Template', priority: true,
      render: (t) => (
        <span>
          <span className="font-medium">{t.name}</span>
          <span className="block text-xs text-slate-600">{t.summary}</span>
        </span>
      ),
    },
    {
      key: 'source', header: 'Source',
      render: (t) => (t.source === 'built_in' ? 'Built-in' : t.source_store ? <Link href={`/super-admin/stores/${t.source_store.id}`} className="underline">From {t.source_store.name ?? `store ${t.source_store.id}`}</Link> : 'From a store (deleted)'),
    },
    { key: 'sells', header: 'Sells', render: (t) => sells(t.business_category) },
    { key: 'contents', header: 'Contents', render: (t) => `${t.categories} categories · ${t.attributes} attributes${t.brands ? ` · ${t.brands} brand${t.brands === 1 ? '' : 's'}` : ''}${t.urdu ? ' · Urdu' : ''}` },
    {
      key: 'status', header: 'Status', priority: true,
      render: (t) => (
        <span className="flex flex-wrap gap-1">
          {t.is_active ? <Badge tone="green">On</Badge> : <Badge tone="red">Off</Badge>}
          {t.is_active && (t.offered_to_stores ? <Badge tone="blue">Offered to stores</Badge> : <Badge tone="amber">Staff only</Badge>)}
        </span>
      ),
    },
    { key: 'used', header: 'Stores using it', align: 'right', render: (t) => t.stores_using },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (t) => (
        <span className="flex flex-wrap justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => setViewing(t)}>View<span className="sr-only"> {t.name}</span></Button>
          <Button size="sm" variant="ghost" onClick={() => setEditing(t)}>Edit<span className="sr-only"> {t.name}</span></Button>
          {t.source === 'store' && <Button size="sm" variant="ghost" onClick={() => setRemoving(t)}>Delete<span className="sr-only"> {t.name}</span></Button>}
        </span>
      ),
    },
  ];

  return (
    <AdminPage
      title="Starter templates"
      description="Ready-made categories, attributes and filters new stores can start from. Built-in templates come with the platform; you can also save a store’s structure as a template from its page."
    >
      <DataTable caption="Starter templates" columns={columns} rows={list.data?.data ?? null} rowKey={(t) => t.key} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No starter templates" />} />

      {viewing && <TemplateDetailDialog template={viewing} onClose={() => setViewing(null)} />}
      {editing && (
        <EditTemplateDialog
          template={editing}
          categories={categories.data?.data ?? []}
          onClose={() => setEditing(null)}
          onDone={() => {
            setEditing(null);
            list.reload();
          }}
        />
      )}
      <ConfirmDialog
        open={removing !== null}
        title="Delete this template?"
        confirmLabel="Delete template"
        busy={busy === 'delete'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/super-admin/starter-templates/${removing.key}`, { method: 'DELETE' }), { success: 'Template deleted.' }).then((done) => {
            if (done !== undefined) {
              setRemoving(null);
              list.reload();
            }
          })
        }
      >
        <p>
          <strong>{removing?.name}</strong> can no longer be chosen. Stores that started from it keep everything it added. You will be asked for your password.
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}

function TemplateDetailDialog({ template, onClose }: { template: TemplateRow; onClose: () => void }) {
  const detail = useApi<{ data: Detail }>(`/super-admin/starter-templates/${template.key}`);

  return (
    <Dialog open wide title={template.name} description={template.summary} onClose={onClose}>
      <QueryState state={detail} lines={5}>
        {({ data }) => (
          <div className="space-y-4 text-sm">
            <section aria-labelledby="detail-categories">
              <h3 id="detail-categories" className="font-semibold text-slate-900">Categories</h3>
              <ul className="mt-2 grid gap-2 sm:grid-cols-2">
                {data.categories.map((c) => (
                  <li key={c.name} className="rounded-md border border-slate-200 p-2">
                    <p className="font-medium text-slate-900">{c.name}</p>
                    {c.children.length > 0 && <p className="text-slate-600">{c.children.join(' · ')}</p>}
                    {c.filters.length > 0 && <p className="text-xs text-slate-600">Filters: {c.filters.join(', ')}</p>}
                  </li>
                ))}
              </ul>
            </section>
            <section aria-labelledby="detail-attributes">
              <h3 id="detail-attributes" className="font-semibold text-slate-900">Attributes</h3>
              <ul className="mt-2 space-y-1">
                {data.attributes.map((a) => (
                  <li key={a.key}>
                    <span className="font-medium text-slate-900">{a.name}</span> {a.values.length > 0 && <span className="text-slate-600">{a.values.join(', ')}</span>}
                  </li>
                ))}
              </ul>
            </section>
            {data.brands.length > 0 && <p><span className="font-semibold text-slate-900">Brands (only when the store asks):</span> {data.brands.join(', ')}</p>}
            <p>
              <span className="font-semibold text-slate-900">Theme:</span> {data.themes.join(' → ')} (the first the store’s package includes) ·{' '}
              <span className="font-semibold text-slate-900">Default order:</span> {data.default_sort ? (LISTING_SORTS[data.default_sort] ?? data.default_sort) : '—'}
            </p>
          </div>
        )}
      </QueryState>
      <div className="mt-4 flex justify-end">
        <Button onClick={onClose}>Close</Button>
      </div>
    </Dialog>
  );
}

function EditTemplateDialog({ template, categories, onClose, onDone }: { template: TemplateRow; categories: Option[]; onClose: () => void; onDone: () => void }) {
  const fromStore = template.source === 'store';
  const form = useForm({ name: template.name, summary: template.summary, business_category: template.business_category ?? '', is_active: template.is_active, offered_to_stores: template.offered_to_stores });
  const v = form.values;

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = fromStore
      ? { name: v.name, summary: v.summary === '' ? null : v.summary, business_category: v.business_category === '' ? null : v.business_category, is_active: v.is_active, offered_to_stores: v.offered_to_stores }
      : { is_active: v.is_active, offered_to_stores: v.offered_to_stores };
    if ((await form.submit(() => adminFetch(`/super-admin/starter-templates/${template.key}`, { method: 'PUT', body }), 'Template saved.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={`Edit ${template.name}`} description="You will be asked for your password." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        {fromStore ? (
          <>
            <TextField label="Name" value={v.name} onChange={(x) => form.set('name', x)} error={form.errors.name} required maxLength={120} data-autofocus />
            <TextAreaField label="Summary" optional rows={2} value={v.summary} onChange={(x) => form.set('summary', x)} error={form.errors.summary} />
            <SelectField label="For stores that sell" optional value={v.business_category} onChange={(x) => form.set('business_category', x)} placeholder="Any" options={categories} error={form.errors.business_category} />
          </>
        ) : (
          <p className="text-sm text-slate-700">A built-in template’s content comes with the platform. Here you decide whether it is used at all and whether store owners see it.</p>
        )}
        <CheckboxField label="On" checked={v.is_active} onChange={(x) => form.set('is_active', x)} hint="Off: nobody can start from it. Stores that used it keep what it added." />
        <CheckboxField
          label="Offered to store owners"
          checked={v.offered_to_stores}
          disabled={!v.is_active}
          onChange={(x) => form.set('offered_to_stores', x)}
          hint={fromStore ? 'It may carry the source store’s category and brand names: offer it only when that is fine for every store.' : 'Otherwise only the Umar Techy team uses it when creating stores.'}
        />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save template</Button>
        </div>
      </form>
    </Dialog>
  );
}
