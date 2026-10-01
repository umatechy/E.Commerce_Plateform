import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { CheckboxField, FormError, TextAreaField, TextField } from '@/Components/ui/Form';
import { StatusBadge } from '@/Components/ui/Badge';
import { Card, EmptyPanel } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { dateTimeOrDash, formatDate } from '@/lib/datetime';

/**
 * Module 31 "API & Developer Platform", the store's side
 * (/api/v1/developer/applications, .../keys, .../webhooks).
 *
 * A key's secret and a webhook's signing secret are returned by the
 * server once, when they are created. This page shows them once, keeps
 * them nowhere, and cannot show them again; the list only has a key's
 * prefix. The scopes offered are the ones the Developer API has.
 */
type Application = { id: string; name: string; status: string; created_at: string };
type ApiKey = { id: string; key_prefix: string; scopes: string[]; status: string; last_used_at: string | null; expires_at: string | null; created_at: string };
type Webhook = { id: number; url: string; subscribed_events: string[]; status: string; created_at: string };

/** ApiScope (Phase B18): the Developer API is read-only. */
const SCOPES: [string, string][] = [
  ['products:read', 'Read products'],
  ['categories:read', 'Read categories'],
  ['orders:read', 'Read orders'],
  ['customers:read', 'Read customers'],
  ['inventory:read', 'Read stock levels'],
];

function SecretNotice({ title, secret, onDone }: { title: string; secret: string; onDone: () => void }) {
  return (
    <Dialog open title={title} description="Copy it now and store it somewhere safe. It is shown only this once and cannot be shown again." onClose={onDone} footer={<Button variant="primary" onClick={onDone}>I have copied it</Button>}>
      <p className="select-all break-all rounded-md border border-amber-300 bg-amber-50 p-3 font-mono text-sm text-slate-900">{secret}</p>
    </Dialog>
  );
}

function ApplicationDrawer({ application, canManage, onClose }: { application: Application; canManage: boolean; onClose: () => void }) {
  const base = `/developer/applications/${application.id}`;
  const keys = useApi<{ data: ApiKey[] }>(`${base}/keys`);
  const webhooks = useApi<{ data: Webhook[] }>(`${base}/webhooks`);
  const keyForm = useForm<{ scopes: string[]; expires_on: string }>({ scopes: [], expires_on: '' });
  const hookForm = useForm({ url: '', events: '' });
  const [secret, setSecret] = useState<{ title: string; value: string } | null>(null);
  const [confirm, setConfirm] = useState<{ kind: 'revoke' | 'rotate'; key: ApiKey } | { kind: 'disable'; hook: Webhook } | null>(null);
  const { busy, run } = useAction();
  const active = application.status === 'active';

  async function issue(event: FormEvent) {
    event.preventDefault();
    if (keyForm.values.scopes.length === 0) {
      keyForm.setFormError('Choose at least one thing the key may read.');

      return;
    }
    const body = { scopes: keyForm.values.scopes, expires_at: keyForm.values.expires_on === '' ? null : `${keyForm.values.expires_on}T23:59:59Z` };
    const issued = await keyForm.submit(() => adminFetch<{ data: ApiKey; secret: string }>(`${base}/keys`, { method: 'POST', body }));
    if (issued) {
      keyForm.reset({ scopes: [], expires_on: '' });
      setSecret({ title: 'Your new API key', value: issued.secret });
      keys.reload();
    }
  }

  async function subscribe(event: FormEvent) {
    event.preventDefault();
    const events = hookForm.values.events.split('\n').map((line) => line.trim()).filter((line) => line !== '');
    const created = await hookForm.submit(() => adminFetch<{ data: Webhook; signing_secret: string }>(`${base}/webhooks`, { method: 'POST', body: { url: hookForm.values.url, events } }));
    if (created) {
      hookForm.reset({ url: '', events: '' });
      setSecret({ title: 'Webhook signing secret', value: created.signing_secret });
      webhooks.reload();
    }
  }

  const keyColumns: Column<ApiKey>[] = [
    { key: 'prefix', header: 'Key', render: (key) => <span className="font-mono text-xs">{key.key_prefix}…</span> },
    { key: 'scopes', header: 'May read', render: (key) => key.scopes.map((scope) => scope.split(':')[0]).join(', ') },
    { key: 'status', header: 'Status', priority: true, render: (key) => <StatusBadge status={key.status} /> },
    { key: 'used', header: 'Last used', render: (key) => dateTimeOrDash(key.last_used_at) },
    { key: 'expires', header: 'Expires', render: (key) => (key.expires_at ? formatDate(key.expires_at) : 'Never') },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (key) =>
        canManage && key.status === 'active' && (
          <span className="flex flex-wrap justify-end gap-1">
            <Button size="sm" variant="ghost" onClick={() => setConfirm({ kind: 'rotate', key })}>Replace</Button>
            <Button size="sm" variant="ghost" onClick={() => setConfirm({ kind: 'revoke', key })}>Revoke</Button>
          </span>
        ),
    },
  ];
  const hookColumns: Column<Webhook>[] = [
    { key: 'url', header: 'Address', render: (hook) => <span className="break-all font-mono text-xs">{hook.url}</span> },
    { key: 'events', header: 'Events', render: (hook) => hook.subscribed_events.join(', ') },
    { key: 'status', header: 'Status', priority: true, render: (hook) => <StatusBadge status={hook.status} /> },
    { key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (hook) => canManage && hook.status === 'active' && <Button size="sm" variant="ghost" onClick={() => setConfirm({ kind: 'disable', hook })}>Switch off</Button> },
  ];

  return (
    <Dialog open side title={application.name} description="API keys and webhooks of this application." onClose={onClose}>
      <div className="space-y-6">
        <section>
          <h3 className="mb-2 text-sm font-semibold text-slate-900">API keys</h3>
          <DataTable caption="API keys" columns={keyColumns} rows={keys.data?.data ?? null} rowKey={(key) => key.id} loading={keys.loading} error={keys.error} onRetry={keys.reload} empty={<p className="text-sm text-slate-600">No keys yet.</p>} />
          {canManage && active && (
            <form onSubmit={issue} className="mt-3 space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4" noValidate>
              <h4 className="text-sm font-semibold text-slate-900">Create a key</h4>
              <FormError message={keyForm.formError} />
              <fieldset>
                <legend className="text-sm font-medium text-slate-700">The key may</legend>
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                  {SCOPES.map(([scope, label]) => (
                    <CheckboxField key={scope} label={label} checked={keyForm.values.scopes.includes(scope)} onChange={(checked) => keyForm.set('scopes', checked ? [...keyForm.values.scopes, scope] : keyForm.values.scopes.filter((value) => value !== scope))} />
                  ))}
                </div>
              </fieldset>
              <TextField label="Expires on" optional type="date" value={keyForm.values.expires_on} onChange={(v) => keyForm.set('expires_on', v)} error={keyForm.errors.expires_at} hint="The end of that day, UTC. Empty: the key does not expire." />
              <div className="flex justify-end">
                <Button type="submit" variant="primary" busy={keyForm.busy} busyLabel="Creating…">Create key</Button>
              </div>
            </form>
          )}
        </section>

        <section>
          <h3 className="mb-2 text-sm font-semibold text-slate-900">Webhooks</h3>
          <DataTable caption="Webhooks" columns={hookColumns} rows={webhooks.data?.data ?? null} rowKey={(hook) => hook.id} loading={webhooks.loading} error={webhooks.error} onRetry={webhooks.reload} empty={<p className="text-sm text-slate-600">No webhooks yet.</p>} />
          {canManage && active && (
            <form onSubmit={subscribe} className="mt-3 space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4" noValidate>
              <h4 className="text-sm font-semibold text-slate-900">Add a webhook</h4>
              <FormError message={hookForm.formError} />
              <TextField label="Address" type="url" value={hookForm.values.url} onChange={(v) => hookForm.set('url', v)} error={hookForm.errors.url} required hint="Must start with https:// and be reachable from the internet." />
              <TextAreaField label="Events" rows={3} value={hookForm.values.events} onChange={(v) => hookForm.set('events', v)} error={hookForm.errors.events ?? hookForm.errors['events.0']} required hint="One event name per line, as listed in the Developer API documentation." />
              <div className="flex justify-end">
                <Button type="submit" variant="primary" busy={hookForm.busy} busyLabel="Adding…">Add webhook</Button>
              </div>
            </form>
          )}
        </section>
        {!active && <p className="text-sm text-slate-600">This application is {application.status}. It cannot get new keys or webhooks.</p>}
      </div>

      {secret && <SecretNotice title={secret.title} secret={secret.value} onDone={() => setSecret(null)} />}
      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.kind === 'rotate' ? 'Replace this key?' : confirm?.kind === 'revoke' ? 'Revoke this key?' : 'Switch off this webhook?'}
        confirmLabel={confirm?.kind === 'rotate' ? 'Replace key' : confirm?.kind === 'revoke' ? 'Revoke key' : 'Switch off'}
        busy={busy !== null}
        onClose={() => setConfirm(null)}
        onConfirm={() => {
          if (confirm === null) return;
          if (confirm.kind === 'disable') {
            void run('disable', () => adminFetch(`${base}/webhooks/${confirm.hook.id}/disable`, { method: 'POST' }), { success: 'Webhook switched off.' }).then((result) => {
              if (result !== undefined) {
                setConfirm(null);
                webhooks.reload();
              }
            });

            return;
          }
          const rotating = confirm.kind === 'rotate';
          void run('key', () => adminFetch<{ secret?: string }>(`${base}/keys/${confirm.key.id}${rotating ? '/rotate' : ''}`, { method: rotating ? 'POST' : 'DELETE' }), { success: rotating ? undefined : 'Key revoked.' }).then((result) => {
            if (result !== undefined) {
              setConfirm(null);
              keys.reload();
              if (rotating && result.secret) setSecret({ title: 'Your replacement API key', value: result.secret });
            }
          });
        }}
      >
        {confirm?.kind === 'rotate' && <p>A new key with the same permissions is created and the old one stops working. Anything using the old key must be given the new one.</p>}
        {confirm?.kind === 'revoke' && <p>The key <span className="font-mono">{confirm.key.key_prefix}…</span> stops working at once. This cannot be undone.</p>}
        {confirm?.kind === 'disable' && <p>No more events are sent to <span className="break-all font-mono">{confirm.hook.url}</span>. To send them again, add the webhook again.</p>}
      </ConfirmDialog>
    </Dialog>
  );
}

export default function Developer() {
  const access = useAccess();
  const canManage = access.can('developer_platform.manage');
  const list = useApi<{ data: Application[] }>('/developer/applications');
  const [adding, setAdding] = useState(false);
  const [open, setOpen] = useState<Application | null>(null);
  const [confirm, setConfirm] = useState<{ application: Application; action: 'suspend' | 'revoke' } | null>(null);
  const form = useForm({ name: '' });
  const { busy, run } = useAction();

  async function create(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch('/developer/applications', { method: 'POST', body: form.values }), 'Application created.')) !== undefined) {
      setAdding(false);
      list.reload();
    }
  }

  const columns: Column<Application>[] = [
    { key: 'name', header: 'Application', render: (application) => <span className="font-medium">{application.name}</span> },
    { key: 'status', header: 'Status', priority: true, render: (application) => <StatusBadge status={application.status} /> },
    { key: 'created', header: 'Created', render: (application) => formatDate(application.created_at) },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (application) => (
        <span className="flex flex-wrap justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => setOpen(application)}>Keys and webhooks</Button>
          {canManage && application.status === 'active' && <Button size="sm" variant="ghost" onClick={() => setConfirm({ application, action: 'suspend' })}>Suspend</Button>}
          {canManage && application.status === 'suspended' && (
            <Button size="sm" variant="ghost" busy={busy === `reactivate:${application.id}`} onClick={() => run(`reactivate:${application.id}`, () => adminFetch(`/developer/applications/${application.id}/reactivate`, { method: 'POST' }), { success: 'Application reactivated.' }).then((result) => result !== undefined && list.reload())}>
              Reactivate
            </Button>
          )}
          {canManage && application.status !== 'revoked' && <Button size="sm" variant="ghost" onClick={() => setConfirm({ application, action: 'revoke' })}>Revoke</Button>}
        </span>
      ),
    },
  ];

  return (
    <AdminPage title="Developer" description="Let your own software read your store's data through the Developer API." actions={canManage && <Button variant="primary" onClick={() => { form.reset({ name: '' }); setAdding(true); }}>Add application</Button>}>
      <DataTable
        caption="Developer applications"
        columns={columns}
        rows={list.data?.data ?? null}
        rowKey={(application) => application.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No applications yet" description="An application groups the API keys and webhooks of one integration." action={canManage ? <Button variant="primary" onClick={() => setAdding(true)}>Add application</Button> : undefined} />}
      />

      <div className="mt-6">
        <Card title="About the Developer API">
          <p className="text-sm text-slate-700">It is read-only: a key can read products, categories, orders, customers and stock, according to what you allow it. A key acts for this store only.</p>
        </Card>
      </div>

      <Dialog open={adding} title="Add application" onClose={() => setAdding(false)} busy={form.busy}>
        <form onSubmit={create} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} hint="For example the name of the system that will connect." data-autofocus />
          <div className="flex justify-end gap-2">
            <Button onClick={() => setAdding(false)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Creating…">Create application</Button>
          </div>
        </form>
      </Dialog>

      {open && <ApplicationDrawer application={open} canManage={canManage} onClose={() => setOpen(null)} />}

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.action === 'revoke' ? 'Revoke this application?' : 'Suspend this application?'}
        confirmLabel={confirm?.action === 'revoke' ? 'Revoke application' : 'Suspend'}
        busy={busy !== null}
        onClose={() => setConfirm(null)}
        onConfirm={() =>
          confirm &&
          run(
            'application',
            () => adminFetch(`/developer/applications/${confirm.application.id}${confirm.action === 'suspend' ? '/suspend' : ''}`, { method: confirm.action === 'suspend' ? 'POST' : 'DELETE' }),
            { success: confirm.action === 'suspend' ? 'Application suspended.' : 'Application revoked.' },
          ).then((result) => {
            if (result !== undefined) {
              setConfirm(null);
              list.reload();
            }
          })
        }
      >
        {confirm?.action === 'revoke' ? (
          <p><strong>{confirm.application.name}</strong> and all its keys stop working for good. This cannot be undone.</p>
        ) : (
          <p><strong>{confirm?.application.name}</strong> and its keys stop working until you reactivate it.</p>
        )}
      </ConfirmDialog>
    </AdminPage>
  );
}
