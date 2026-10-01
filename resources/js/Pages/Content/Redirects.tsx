import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import Badge from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { usePagedApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';

/**
 * Module 16 redirects (/api/v1/redirects). The server accepts only
 * addresses inside the store (no other website), and refuses loops and
 * long chains; its message is shown as it is.
 */
type Redirect = { id: number; source_path: string; destination_path: string; status_code: number; is_active: boolean; reason: string | null };

const CODES = [
  { value: '301', label: '301 — moved for good' },
  { value: '302', label: '302 — moved for now' },
  { value: '307', label: '307 — temporary, same method' },
  { value: '308', label: '308 — permanent, same method' },
];

const BLANK = { source_path: '', destination_path: '', status_code: '301' };

export default function Redirects() {
  const access = useAccess();
  const canManage = access.can('seo.manage');
  const [page, setPage] = useState(1);
  const list = usePagedApi<Redirect>('/redirects', { page });
  const [adding, setAdding] = useState(false);
  const [removing, setRemoving] = useState<Redirect | null>(null);
  const form = useForm(BLANK);
  const { busy, run } = useAction();

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = { source_path: form.values.source_path, destination_path: form.values.destination_path, status_code: Number(form.values.status_code) };
    if ((await form.submit(() => adminFetch('/redirects', { method: 'POST', body }), 'Redirect saved.')) !== undefined) {
      setAdding(false);
      list.reload();
    }
  }

  const columns: Column<Redirect>[] = [
    { key: 'from', header: 'From', render: (redirect) => <span className="break-all font-mono text-xs">{redirect.source_path}</span> },
    { key: 'to', header: 'To', priority: true, render: (redirect) => <span className="break-all font-mono text-xs">{redirect.destination_path}</span> },
    { key: 'code', header: 'Code', render: (redirect) => redirect.status_code },
    { key: 'state', header: 'Status', priority: true, render: (redirect) => <Badge tone={redirect.is_active ? 'green' : 'neutral'}>{redirect.is_active ? 'Active' : 'Switched off'}</Badge> },
    { key: 'origin', header: 'Origin', render: (redirect) => (redirect.reason?.startsWith('slug_changed') ? 'Added when an address changed' : 'Added by hand') },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (redirect) => canManage && redirect.is_active && <Button size="sm" variant="ghost" onClick={() => setRemoving(redirect)}>Switch off<span className="sr-only"> redirect from {redirect.source_path}</span></Button>,
    },
  ];

  return (
    <AdminPage
      title="Redirects"
      description="Send visitors of an old address to a new one, so links keep working."
      actions={canManage && <Button variant="primary" onClick={() => { form.reset(BLANK); setAdding(true); }}>Add redirect</Button>}
    >
      <DataTable
        caption="Redirects"
        columns={columns}
        rows={list.rows}
        rowKey={(redirect) => redirect.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No redirects" description="Redirects are added for you when a page's address changes. You can also add your own." />}
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />

      <Dialog open={adding} title="Add redirect" description="Both addresses are paths inside your store, such as /pages/old-name. Another website cannot be the target." onClose={() => setAdding(false)} busy={form.busy}>
        <form onSubmit={save} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <TextField label="From" value={form.values.source_path} onChange={(v) => form.set('source_path', v)} error={form.errors.source_path} required maxLength={2048} placeholder="/pages/old-name" data-autofocus />
          <TextField label="To" value={form.values.destination_path} onChange={(v) => form.set('destination_path', v)} error={form.errors.destination_path} required maxLength={2048} placeholder="/pages/new-name" />
          <SelectField label="Kind" value={form.values.status_code} onChange={(v) => form.set('status_code', v)} error={form.errors.status_code} options={CODES} />
          <div className="flex justify-end gap-2">
            <Button onClick={() => setAdding(false)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save redirect</Button>
          </div>
        </form>
      </Dialog>

      <ConfirmDialog
        open={removing !== null}
        title="Switch off this redirect?"
        confirmLabel="Switch off"
        busy={busy === 'off'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('off', () => adminFetch(`/redirects/${removing.id}`, { method: 'DELETE' }), { success: 'Redirect switched off.' }).then((result) => {
            if (result !== undefined) {
              setRemoving(null);
              list.reload();
            }
          })
        }
      >
        <p>
          Visitors of <strong className="font-mono">{removing?.source_path}</strong> are no longer sent on. Add the redirect again to bring it back.
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}
