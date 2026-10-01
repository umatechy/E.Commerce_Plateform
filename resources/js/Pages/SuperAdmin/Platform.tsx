import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import Badge, { StatusBadge } from '@/Components/ui/Badge';
import { EmptyPanel, Tabs } from '@/Components/ui/Page';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { dateTimeOrDash } from '@/lib/datetime';

/**
 * Module 30 §36–37 and §63: the theme library, every store's domains,
 * and the developer applications across the platform
 * (/api/v1/super-admin/themes, /domains, /developer/applications).
 * Platform staff only.
 *
 * A theme here is an entry in the library (key, name, version, status);
 * its design lives in the application's code and each store's
 * configuration. To act on one store's domain, open that store.
 */
type Theme = { id: number; key: string; name: string; version: string; status: string };
type Domain = { id: string; hostname: string; domain_type: string; status: string; is_primary: boolean; ssl_status: string; verified_at: string | null };
type Application = { id: number; public_id: string; store_id: number; name: string; status: string; api_keys?: unknown[] };

function Themes() {
  const list = useApi<{ data: Theme[] }>('/super-admin/themes');
  const [editing, setEditing] = useState<Theme | 'new' | null>(null);
  const form = useForm({ key: '', name: '', version: '1.0.0', status: 'active' });

  function open(target: Theme | 'new') {
    form.reset(target === 'new' ? { key: '', name: '', version: '1.0.0', status: 'active' } : { key: target.key, name: target.name, version: target.version, status: target.status });
    setEditing(target);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const target = editing;
    const v = form.values;
    const saved = await form.submit(
      () =>
        target === 'new'
          ? adminFetch('/super-admin/themes', { method: 'POST', body: { key: v.key, name: v.name, version: v.version } })
          : adminFetch(`/super-admin/themes/${(target as Theme).id}`, { method: 'PUT', body: { name: v.name, version: v.version, status: v.status } }),
      'Theme saved.',
    );
    if (saved !== undefined) {
      setEditing(null);
      list.reload();
    }
  }

  const columns: Column<Theme>[] = [
    {
      key: 'name',
      header: 'Theme',
      render: (theme) => (
        <div>
          <span className="font-medium">{theme.name}</span>
          <p className="font-mono text-xs text-slate-500">{theme.key}</p>
        </div>
      ),
    },
    { key: 'version', header: 'Version', render: (theme) => theme.version },
    { key: 'status', header: 'Status', priority: true, render: (theme) => <StatusBadge status={theme.status} /> },
    { key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (theme) => <Button size="sm" variant="ghost" onClick={() => open(theme)}>Edit<span className="sr-only"> {theme.name}</span></Button> },
  ];

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button variant="primary" onClick={() => open('new')}>Add theme</Button>
      </div>
      <DataTable caption="Theme library" columns={columns} rows={list.data?.data ?? null} rowKey={(theme) => theme.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No themes" />} />
      <Dialog open={editing !== null} title={editing === 'new' ? 'Add theme' : 'Edit theme'} onClose={() => setEditing(null)} busy={form.busy}>
        <form onSubmit={save} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          {editing === 'new' && <TextField label="Key" value={form.values.key} onChange={(v) => form.set('key', v)} error={form.errors.key} required maxLength={64} hint="Cannot be changed later." data-autofocus />}
          <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} />
          <TextField label="Version" value={form.values.version} onChange={(v) => form.set('version', v)} error={form.errors.version} required maxLength={16} />
          {editing !== 'new' && <SelectField label="Status" value={form.values.status} onChange={(v) => form.set('status', v)} error={form.errors.status} options={[{ value: 'active', label: 'Active' }, { value: 'deprecated', label: 'Deprecated' }]} />}
          <div className="flex justify-end gap-2">
            <Button onClick={() => setEditing(null)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save theme</Button>
          </div>
        </form>
      </Dialog>
    </>
  );
}

function Domains() {
  const [page, setPage] = useState(1);
  const list = usePagedApi<Domain>('/super-admin/domains', { page });
  const columns: Column<Domain>[] = [
    { key: 'host', header: 'Address', render: (domain) => <span className="break-all font-medium">{domain.hostname} {domain.is_primary && <Badge tone="blue">Main</Badge>}</span> },
    { key: 'kind', header: 'Kind', render: (domain) => (domain.domain_type === 'platform_subdomain' ? 'Platform address' : 'Own domain') },
    { key: 'status', header: 'Status', priority: true, render: (domain) => <StatusBadge status={domain.status} /> },
    { key: 'ssl', header: 'SSL', render: (domain) => <StatusBadge status={domain.ssl_status} /> },
    { key: 'verified', header: 'Verified', render: (domain) => dateTimeOrDash(domain.verified_at) },
  ];

  return (
    <>
      <p className="mb-3 text-sm text-slate-600">Every domain on the platform. To suspend or reactivate one, open its store from the Stores page.</p>
      <DataTable caption="All domains" columns={columns} rows={list.rows} rowKey={(domain) => domain.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No domains" />} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />
    </>
  );
}

function Applications() {
  const [page, setPage] = useState(1);
  const list = usePagedApi<Application>('/super-admin/developer/applications', { page });
  const [suspending, setSuspending] = useState<Application | null>(null);
  const { busy, run } = useAction();

  const columns: Column<Application>[] = [
    { key: 'name', header: 'Application', render: (application) => <span className="font-medium">{application.name}</span> },
    {
      key: 'store',
      header: 'Store',
      render: (application) => (
        <Link href={`/super-admin/stores/${application.store_id}`} className={`rounded text-indigo-700 hover:underline ${FOCUS_RING}`}>
          Store #{application.store_id}
        </Link>
      ),
    },
    { key: 'keys', header: 'Keys', align: 'right', render: (application) => application.api_keys?.length ?? 0 },
    { key: 'status', header: 'Status', priority: true, render: (application) => <StatusBadge status={application.status} /> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (application) => application.status === 'active' && <Button size="sm" variant="ghost" onClick={() => setSuspending(application)}>Suspend<span className="sr-only"> {application.name}</span></Button>,
    },
  ];

  return (
    <>
      <DataTable caption="Developer applications" columns={columns} rows={list.rows} rowKey={(application) => application.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No developer applications" />} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />
      <ConfirmDialog
        open={suspending !== null}
        title="Suspend this application?"
        confirmLabel="Suspend application"
        busy={busy === 'suspend'}
        onClose={() => setSuspending(null)}
        onConfirm={() =>
          suspending &&
          run('suspend', () => adminFetch(`/super-admin/developer/applications/${suspending.id}/suspend`, { method: 'POST' }), { success: 'Application suspended.' }).then((result) => {
            if (result !== undefined) {
              setSuspending(null);
              list.reload();
            }
          })
        }
      >
        <p>
          <strong>{suspending?.name}</strong> and its API keys stop working until the store reactivates it. You will be asked for your password, and the action is recorded.
        </p>
      </ConfirmDialog>
    </>
  );
}

export default function Platform() {
  const [state, setState] = useUrlState({ tab: 'themes' });
  const tab = ['themes', 'domains', 'applications'].includes(state.tab) ? state.tab : 'themes';

  return (
    <AdminPage title="Themes, domains and developer applications" description="Platform-wide lists. Umar Techy staff only.">
      <Tabs label="Sections" tabs={[{ id: 'themes', label: 'Theme library' }, { id: 'domains', label: 'Domains' }, { id: 'applications', label: 'Developer applications' }]} active={tab} onChange={(next) => setState({ tab: next })} />
      <div role="tabpanel">{tab === 'themes' ? <Themes /> : tab === 'domains' ? <Domains /> : <Applications />}</div>
    </AdminPage>
  );
}
