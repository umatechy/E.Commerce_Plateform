import { useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import { Card, EmptyPanel, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import Badge, { StatusBadge } from '@/Components/ui/Badge';
import Button from '@/Components/ui/Button';
import { CheckboxField, FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { adminErrorMessage, adminFetch, AdminApiError } from '@/lib/adminApi';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { formatDateTime } from '@/lib/datetime';

/**
 * Phase B56 — Super Admin → Marketplace (Module 27 §8–9, §16–19, §45,
 * §58–59, §71, §85–86). Register an app, write its version (what it may
 * read, which events, its settings form, its data use), send it for
 * review, publish it after a second person has checked it, and stop it
 * everywhere with the kill switch. Client secrets are shown once.
 */
type Meta = {
  categories: Record<string, string>;
  scopes: { scope: string; label: string; risk: string }[];
  events: string[];
  ownership: Record<string, string>;
  trust: Record<string, string>;
  field_types: string[];
  review_checks: Record<string, string>;
};
type Row = { id: string; slug: string; name: string; publisher: string; category: string; status: string; trust: string; version: string | null; installations: number; waiting_for_review: boolean };
type Version = { version: string; status: string; scopes: string[]; events: string[]; config_schema: unknown[]; data_use: string; release_notes: string | null; min_platform_version: string; submitted_by_me: boolean; review: { note?: string | null; at?: string } | null; published_at: string | null };
type Detail = {
  id: string; slug: string; name: string; tagline: string; description: string; category: string; publisher_name: string; publisher_website: string | null; support_email: string | null;
  privacy_url: string; terms_url: string | null; ownership: string; trust: string; status: string; client_id: string; redirect_uris: string[]; webhook_url: string | null;
  required_features: string[]; suspended_reason: string | null; versions: Version[];
  installations: { id: string; store: string | null; status: string; installed_at: string | null; health: string }[];
};

export default function Marketplace() {
  const access = useAccess();
  const canManage = access.platformCan('marketplace');
  const list = useApi<{ data: Row[]; meta: Meta }>('/super-admin/marketplace/apps');
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [secret, setSecret] = useState<{ clientId: string; secret: string } | null>(null);

  const columns: Column<Row>[] = [
    { key: 'name', header: 'App', render: (r) => <span><span className="font-medium">{r.name}</span><span className="block text-xs text-slate-500">{r.slug} · {r.publisher}</span></span> },
    { key: 'status', header: 'Status', priority: true, render: (r) => <span className="flex flex-wrap gap-1"><StatusBadge status={r.status} />{r.waiting_for_review && <Badge tone="blue">Review</Badge>}</span> },
    { key: 'version', header: 'Version', render: (r) => r.version ?? '—' },
    { key: 'installs', header: 'Active in stores', render: (r) => r.installations },
  ];

  return (
    <AdminPage
      title="Marketplace"
      description="Apps stores can install. An app reads only what a store grants and what its version declares; publishing needs a second person’s review."
      actions={canManage && <Button variant="primary" onClick={() => setCreating(true)}>Add app</Button>}
    >
      <DataTable
        caption="Marketplace apps"
        columns={columns}
        rows={list.data?.data ?? null}
        rowKey={(r) => r.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        onRowClick={(r) => setOpen(r.id)}
        empty={<EmptyPanel title="No apps yet" description="Add the first app a publisher has asked to list." />}
      />
      {creating && list.data && (
        <ListingDialog
          meta={list.data.meta}
          onClose={() => setCreating(false)}
          onSaved={(r) => {
            setCreating(false);
            list.reload();
            if (r) setSecret(r);
          }}
        />
      )}
      {secret && <SecretDialog clientId={secret.clientId} secret={secret.secret} onClose={() => setSecret(null)} />}
      {open && list.data && <AppDrawer id={open} meta={list.data.meta} canManage={canManage} onClose={() => setOpen(null)} onChanged={list.reload} />}
    </AdminPage>
  );
}

function SecretDialog({ clientId, secret, onClose }: { clientId: string; secret: string; onClose: () => void }) {
  return (
    <Dialog open title="Client credentials" description="Give these to the publisher over a safe channel. The secret is not shown again." onClose={onClose} footer={<Button variant="primary" onClick={onClose}>I have copied it</Button>}>
      <dl className="space-y-2 text-sm">
        <div><dt className="text-slate-600">Client id</dt><dd className="break-all font-mono">{clientId}</dd></div>
        <div><dt className="text-slate-600">Client secret</dt><dd className="break-all font-mono">{secret}</dd></div>
      </dl>
    </Dialog>
  );
}

type Listing = Omit<Detail, 'id' | 'status' | 'client_id' | 'suspended_reason' | 'versions' | 'installations'>;

function ListingDialog({ meta, app, onClose, onSaved }: { meta: Meta; app?: Detail; onClose: () => void; onSaved: (created: { clientId: string; secret: string } | null) => void }) {
  const [v, setV] = useState<Record<string, string>>({
    slug: app?.slug ?? '', name: app?.name ?? '', tagline: app?.tagline ?? '', description: app?.description ?? '', category: app?.category ?? 'accounting',
    publisher_name: app?.publisher_name ?? '', publisher_website: app?.publisher_website ?? '', support_email: app?.support_email ?? '', privacy_url: app?.privacy_url ?? '',
    terms_url: app?.terms_url ?? '', ownership: app?.ownership ?? 'third_party', trust: app?.trust ?? 'unverified',
    redirect_uris: (app?.redirect_uris ?? []).join('\n'), webhook_url: app?.webhook_url ?? '', required_features: (app?.required_features ?? []).join(', '),
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: string) => (x: string) => setV((o) => ({ ...o, [k]: x }));

  function save() {
    setBusy(true);
    setErrors({});
    setError(null);
    const body: Partial<Listing> & { slug?: string } = {
      ...(app ? {} : { slug: v.slug }),
      name: v.name, tagline: v.tagline, description: v.description, category: v.category, publisher_name: v.publisher_name,
      publisher_website: v.publisher_website || null, support_email: v.support_email || null, privacy_url: v.privacy_url, terms_url: v.terms_url || null,
      ownership: v.ownership, trust: v.trust, webhook_url: v.webhook_url || null,
      redirect_uris: v.redirect_uris.split('\n').map((s) => s.trim()).filter(Boolean),
      required_features: v.required_features.split(',').map((s) => s.trim()).filter(Boolean),
    };
    (app ? adminFetch(`/super-admin/marketplace/apps/${app.id}`, { method: 'PUT', body }) : adminFetch<{ data: { client_id: string; client_secret: string } }>('/super-admin/marketplace/apps', { method: 'POST', body }))
      .then((r) => onSaved(app ? null : { clientId: (r as { data: { client_id: string } }).data.client_id, secret: (r as { data: { client_secret: string } }).data.client_secret }))
      .catch((e) => {
        setError(adminErrorMessage(e));
        if (e instanceof AdminApiError) setErrors(Object.fromEntries(Object.entries((e.body?.errors ?? {}) as Record<string, string[]>).map(([k, m]) => [k.split('.')[0], m[0]])));
      })
      .finally(() => setBusy(false));
  }

  return (
    <Dialog open wide title={app ? `Edit ${app.name}` : 'Add an app'} onClose={onClose} busy={busy} footer={<><Button variant="ghost" onClick={onClose}>Cancel</Button><Button variant="primary" busy={busy} onClick={save}>Save</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <FormError message={error} />
        {!app && <TextField label="Slug" value={v.slug} onChange={set('slug')} hint="Its address in the marketplace: small letters, digits and hyphens." error={errors.slug} />}
        <TextField label="Name" value={v.name} onChange={set('name')} maxLength={80} error={errors.name} />
        <TextField label="One line about it" value={v.tagline} onChange={set('tagline')} maxLength={160} error={errors.tagline} className="sm:col-span-2" />
        <TextAreaField label="Description" value={v.description} onChange={set('description')} rows={4} error={errors.description} className="sm:col-span-2" />
        <SelectField label="Category" value={v.category} onChange={set('category')} options={Object.entries(meta.categories).map(([value, label]) => ({ value, label }))} />
        <TextField label="Publisher" value={v.publisher_name} onChange={set('publisher_name')} error={errors.publisher_name} />
        <TextField label="Publisher website" optional value={v.publisher_website} onChange={set('publisher_website')} error={errors.publisher_website} />
        <TextField label="Support email" optional value={v.support_email} onChange={set('support_email')} error={errors.support_email} />
        <TextField label="Privacy policy" value={v.privacy_url} onChange={set('privacy_url')} hint="https://…" error={errors.privacy_url} />
        <TextField label="Terms" optional value={v.terms_url} onChange={set('terms_url')} error={errors.terms_url} />
        <SelectField label="Who makes it" value={v.ownership} onChange={set('ownership')} options={Object.entries(meta.ownership).map(([value, label]) => ({ value, label }))} />
        <SelectField label="Trust shown to stores" value={v.trust} onChange={set('trust')} options={Object.entries(meta.trust).map(([value, label]) => ({ value, label }))} hint="Never replaces the permission checks." />
        <TextAreaField label="Redirect addresses" optional value={v.redirect_uris} onChange={set('redirect_uris')} rows={2} hint="One per line, https. The first is where stores connect." error={errors.redirect_uris} className="sm:col-span-2" />
        <TextField label="Webhook address" optional value={v.webhook_url} onChange={set('webhook_url')} hint="https; needed to receive events." error={errors.webhook_url} />
        <TextField label="Package features it needs" optional value={v.required_features} onChange={set('required_features')} hint="Comma-separated keys, e.g. integrations.advanced" error={errors.required_features} />
      </div>
    </Dialog>
  );
}

function AppDrawer({ id, meta, canManage, onClose, onChanged }: { id: string; meta: Meta; canManage: boolean; onClose: () => void; onChanged: () => void }) {
  const state = useApi<{ data: Detail }>(`/super-admin/marketplace/apps/${id}`);
  const { busy, run } = useAction();
  const [editing, setEditing] = useState(false);
  const [versioning, setVersioning] = useState(false);
  const [reviewing, setReviewing] = useState(false);
  const [suspending, setSuspending] = useState(false);
  const [reason, setReason] = useState('');
  const [secret, setSecret] = useState<string | null>(null);
  const d = state.data?.data;
  const pending = d?.versions.find((v) => v.status === 'in_review') ?? null;
  const draft = d?.versions.find((v) => v.status === 'draft') ?? null;
  const ownSubmission = pending?.submitted_by_me === true;

  function act(path: string, body: Record<string, unknown> = {}, success = 'Done.') {
    return run(path, () => adminFetch(`/super-admin/marketplace/apps/${id}/${path}`, { method: 'POST', body }), { success }).then((r) => {
      if (r !== undefined) {
        state.reload();
        onChanged();
      }

      return r;
    });
  }

  return (
    <Dialog open side wide title={d?.name ?? 'App'} description={d ? `${d.slug} · client id ${d.client_id}` : undefined} onClose={onClose}>
      {state.error ? <ErrorPanel message={state.error} onRetry={state.reload} /> : !d ? <Skeleton lines={8} /> : (
        <div className="space-y-5 text-sm">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={d.status} />
            <Badge>{meta.trust[d.trust] ?? d.trust}</Badge>
            <span className="text-slate-600">{meta.categories[d.category]} · {d.publisher_name}</span>
          </div>
          {d.status === 'suspended' && <p role="alert" className="rounded-md border border-red-200 bg-red-50 p-3 text-red-800">Suspended in every store: {d.suspended_reason}</p>}

          {canManage && (
            <div className="flex flex-wrap gap-2">
              <Button size="sm" onClick={() => setEditing(true)}>Edit listing</Button>
              <Button size="sm" onClick={() => setVersioning(true)}>{draft ? `Edit draft ${draft.version}` : 'New version'}</Button>
              {draft && <Button size="sm" busy={busy === 'submit'} onClick={() => act('submit', {}, 'Sent for review.')}>Send {draft.version} for review</Button>}
              {pending && <Button size="sm" variant="primary" onClick={() => setReviewing(true)}>Review {pending.version}</Button>}
              {d.status === 'suspended' ? (
                <Button size="sm" busy={busy === 'resume'} onClick={() => act('resume', {}, 'Resumed.')}>Resume</Button>
              ) : (
                <Button size="sm" variant="danger" onClick={() => setSuspending(true)}>Suspend everywhere</Button>
              )}
              {d.status === 'published' && <Button size="sm" variant="ghost" busy={busy === 'deprecate'} onClick={() => act('deprecate', {}, 'Retired from the catalog.')}>Retire from catalog</Button>}
              <Button size="sm" variant="ghost" busy={busy === 'secret'} onClick={() => act('secret', {}, 'New secret made.').then((r) => r && setSecret((r as { data: { client_secret: string } }).data.client_secret))}>New client secret</Button>
            </div>
          )}

          <Card title="Versions">
            {d.versions.length === 0 ? <p className="text-slate-600">No version yet.</p> : (
              <ul className="space-y-3">
                {d.versions.map((v) => (
                  <li key={v.version} className="border-b border-slate-100 pb-2">
                    <p className="font-medium">{v.version} <StatusBadge status={v.status} /></p>
                    <p className="text-slate-600">Reads: {v.scopes.join(', ') || 'nothing'} · Events: {v.events.join(', ') || 'none'} · Settings fields: {v.config_schema.length}</p>
                    {v.published_at && <p className="text-xs text-slate-500">Published {formatDateTime(v.published_at)}{v.review?.note ? ` — ${v.review.note}` : ''}</p>}
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card title={`Stores (${d.installations.length})`}>
            {d.installations.length === 0 ? <p className="text-slate-600">No store has installed it.</p> : (
              <ul className="space-y-1">
                {d.installations.map((i) => (
                  <li key={i.id} className="flex justify-between gap-2"><span>{i.store}</span><span><StatusBadge status={i.status} /> <Badge>{i.health}</Badge></span></li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}

      {editing && d && <ListingDialog meta={meta} app={d} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); state.reload(); onChanged(); }} />}
      {versioning && d && <VersionDialog meta={meta} appId={id} start={draft ?? d.versions[0] ?? null} onClose={() => setVersioning(false)} onSaved={() => { setVersioning(false); state.reload(); onChanged(); }} />}
      {reviewing && pending && <ReviewDialog meta={meta} version={pending} own={ownSubmission} busy={busy === 'publish'} onClose={() => setReviewing(false)} onPublish={(checks, note) => act('publish', { checks, note }, 'Published.').then((r) => r && setReviewing(false))} />}
      {secret && d && <SecretDialog clientId={d.client_id} secret={secret} onClose={() => setSecret(null)} />}
      <ConfirmDialog open={suspending} title="Suspend this app in every store?" confirmLabel="Suspend everywhere" busy={busy === 'suspend'} onClose={() => setSuspending(false)} onConfirm={() => act('suspend', { reason }, 'Suspended.').then((r) => r && setSuspending(false))}>
        <p className="text-sm text-slate-700">Its keys and webhooks stop at once in every store. Stores see the reason. Resuming brings back exactly the installations that were running.</p>
        <TextField label="Reason stores see" value={reason} onChange={setReason} maxLength={300} />
      </ConfirmDialog>
    </Dialog>
  );
}

function VersionDialog({ meta, appId, start, onClose, onSaved }: { meta: Meta; appId: string; start: Version | null; onClose: () => void; onSaved: () => void }) {
  const [version, setVersion] = useState(start?.status === 'draft' ? start.version : '');
  const [scopes, setScopes] = useState<string[]>(start?.scopes ?? []);
  const [events, setEvents] = useState<string[]>(start?.events ?? []);
  const [schema, setSchema] = useState(JSON.stringify(start?.config_schema ?? [], null, 2));
  const [dataUse, setDataUse] = useState(start?.data_use ?? '');
  const [notes, setNotes] = useState(start?.status === 'draft' ? (start.release_notes ?? '') : '');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const toggle = (list: string[], set: (v: string[]) => void, value: string) => set(list.includes(value) ? list.filter((x) => x !== value) : [...list, value]);

  function save() {
    let configSchema: unknown;
    try {
      configSchema = JSON.parse(schema || '[]');
    } catch {
      setError('The settings form is not valid JSON.');

      return;
    }
    setBusy(true);
    setError(null);
    adminFetch(`/super-admin/marketplace/apps/${appId}/version`, { method: 'PUT', body: { version, scopes, events, config_schema: configSchema, data_use: dataUse, release_notes: notes || null } })
      .then(onSaved)
      .catch((e) => setError(adminErrorMessage(e)))
      .finally(() => setBusy(false));
  }

  return (
    <Dialog open wide title="Version" description="What this version may read and receive. Stores see it before they install or update." onClose={onClose} busy={busy} footer={<><Button variant="ghost" onClick={onClose}>Cancel</Button><Button variant="primary" busy={busy} onClick={save}>Save draft</Button></>}>
      <div className="space-y-4 text-sm">
        <FormError message={error} />
        <TextField label="Version" value={version} onChange={setVersion} placeholder="1.0.0" hint="MAJOR.MINOR.PATCH, higher than the published one." />
        <fieldset>
          <legend className="mb-1 font-medium">May read</legend>
          {meta.scopes.map((s) => <CheckboxField key={s.scope} label={`${s.label} (${s.scope})${s.risk === 'high' ? ' — personal data' : ''}`} checked={scopes.includes(s.scope)} onChange={() => toggle(scopes, setScopes, s.scope)} />)}
        </fieldset>
        <fieldset>
          <legend className="mb-1 font-medium">Events it receives</legend>
          <div className="grid gap-1 sm:grid-cols-2">{meta.events.map((e) => <CheckboxField key={e} label={e} checked={events.includes(e)} onChange={() => toggle(events, setEvents, e)} />)}</div>
        </fieldset>
        <TextAreaField label="Settings form (JSON)" optional value={schema} onChange={setSchema} rows={8} hint={`A list of fields: {"key", "label", "type" (${meta.field_types.join(', ')}), "required", "options", "help", "max"}.`} />
        <TextAreaField label="How it uses the data" value={dataUse} onChange={setDataUse} rows={4} hint="What it reads, why, where it goes, how long it is kept. Stores read this before installing." />
        <TextAreaField label="Release notes" optional value={notes} onChange={setNotes} rows={3} />
      </div>
    </Dialog>
  );
}

function ReviewDialog({ meta, version, own, busy, onClose, onPublish }: { meta: Meta; version: Version; own: boolean; busy: boolean; onClose: () => void; onPublish: (checks: Record<string, boolean>, note: string | null) => void }) {
  const [checks, setChecks] = useState<Record<string, boolean>>({});
  const [note, setNote] = useState('');
  const all = Object.keys(meta.review_checks).every((k) => checks[k]);

  return (
    <Dialog open wide title={`Review version ${version.version}`} description="Confirm each point yourself. Someone other than the submitter publishes." onClose={onClose} busy={busy}
      footer={<><Button variant="ghost" onClick={onClose}>Cancel</Button><Button variant="primary" disabled={!all || own} title={own ? 'Someone else must review what you submitted' : undefined} busy={busy} onClick={() => onPublish(checks, note || null)}>Approve and publish</Button></>}>
      <div className="space-y-3 text-sm">
        <p><strong>Reads:</strong> {version.scopes.join(', ') || 'nothing'} · <strong>Events:</strong> {version.events.join(', ') || 'none'}</p>
        <p className="whitespace-pre-line rounded-md bg-slate-50 p-3">{version.data_use}</p>
        {Object.entries(meta.review_checks).map(([key, label]) => <CheckboxField key={key} label={label} checked={Boolean(checks[key])} onChange={(v) => setChecks((o) => ({ ...o, [key]: v }))} />)}
        <TextAreaField label="Note" optional value={note} onChange={setNote} rows={2} />
        {own && <p className="text-amber-800">You submitted this version, so someone else reviews it.</p>}
      </div>
    </Dialog>
  );
}
