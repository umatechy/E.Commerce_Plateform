import { useEffect, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import { Card, EmptyPanel, ErrorPanel, Skeleton, Tabs } from '@/Components/ui/Page';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import Badge, { StatusBadge, type Tone } from '@/Components/ui/Badge';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import { CheckboxField, FormError, SelectField, TextField } from '@/Components/ui/Form';
import { adminErrorMessage, adminFetch, AdminApiError } from '@/lib/adminApi';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { toast } from '@/Components/ui/toast';

/**
 * Phase B56 (gap G19; Module 27 §10–12, §45, §56, §65–66, §89) — Store →
 * Apps. Browse the marketplace, see exactly what an app may read and
 * receive and what it does with the data, install it (the Owner, after
 * confirming it is them), connect it, set it up, update it, pause it or
 * remove it. Removing ends its access at once.
 */
type Permission = { scope: string; label: string; risk: 'low' | 'high' | string };
type Card_ = { slug: string; name: string; tagline: string; category: string; category_label: string; publisher: string; trust: string; trust_label: string; pricing: string; status: string; high_risk: boolean; installed?: string | null };
type Field = { key: string; label: string; type: string; required?: boolean; options?: string[]; help?: string; max?: number };
type Detail = Omit<Card_, 'publisher'> & {
  description: string;
  publisher: { name: string; website: string | null; support_email: string | null };
  privacy_url: string;
  terms_url: string | null;
  version: string | null;
  release_notes: string | null;
  permissions: Permission[];
  events: string[];
  data_use: string | null;
  problems: { code: string; message: string }[];
  can_manage: boolean;
  installation: null | {
    id: string;
    status: string;
    version: string;
    permissions: Permission[];
    events: string[];
    installed_at: string | null;
    connected_at: string | null;
    settings_form: Field[];
    settings: Record<string, unknown>;
    usage: { api_calls: number; api_errors: number; webhooks_sent: number; webhooks_failed: number; last_used_at: string | null; health: string };
    update: null | { version: string; release_notes: string | null; new_permissions: Permission[]; new_events: string[] };
    suspended_reason: string | null;
  };
};

const STATUS_LABEL: Record<string, string> = {
  awaiting_connection: 'Waiting to connect',
  active: 'Active',
  disabled: 'Paused',
  suspended: 'Suspended by Umar Techy',
};

const HEALTH: Record<string, [string, Tone]> = {
  healthy: ['Working', 'green'],
  degraded: ['Some errors', 'amber'],
  critical: ['Failing', 'red'],
  unknown: ['Not used yet', 'neutral'],
  disabled: ['Not running', 'neutral'],
};

export default function Apps() {
  const [tab, setTab] = useState('browse');
  const [q, setQ] = useState('');
  const [category, setCategory] = useState('');
  const list = useApi<{ data: Card_[]; meta: { categories: Record<string, string>; can_manage: boolean } }>('/apps', { q: q || undefined, category: category || undefined });
  const [open, setOpen] = useState<string | null>(() => (typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('app')));
  const rows = list.data?.data ?? [];
  const shown = tab === 'installed' ? rows.filter((a) => a.installed) : rows;

  return (
    <AdminPage title="Apps" description="Connect your store to other services. You see exactly what each app may read before you install it, and you can remove it at any time.">
      <Tabs
        label="Apps sections"
        active={tab}
        onChange={setTab}
        tabs={[
          { id: 'browse', label: 'Marketplace' },
          { id: 'installed', label: `Installed${rows.filter((a) => a.installed).length ? ` (${rows.filter((a) => a.installed).length})` : ''}` },
        ]}
      />
      {tab === 'browse' && (
        <FilterBar>
          <SearchField label="Search apps" value={q} onChange={setQ} placeholder="Name, purpose or publisher" />
          <div className="w-56">
            <SelectField label="Category" value={category} onChange={setCategory} options={[{ value: '', label: 'All categories' }, ...Object.entries(list.data?.meta.categories ?? {}).map(([value, label]) => ({ value, label }))]} />
          </div>
        </FilterBar>
      )}
      {list.error ? (
        <ErrorPanel message={list.error} onRetry={list.reload} />
      ) : list.data === null ? (
        <Skeleton lines={6} />
      ) : shown.length === 0 ? (
        <EmptyPanel
          title={tab === 'installed' ? 'No apps installed' : 'No apps here yet'}
          description={tab === 'installed' ? 'Apps you install appear here.' : 'Umar Techy adds reviewed apps to the marketplace over time. Your own software can use the Developer API under Settings → Developer.'}
        />
      ) : (
        <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {shown.map((app) => (
            <li key={app.slug}>
              <button type="button" onClick={() => setOpen(app.slug)} className={`flex h-full w-full gap-3 rounded-lg border border-slate-200 bg-white p-4 text-left hover:border-slate-400 ${FOCUS_RING}`}>
                <AppMark name={app.name} />
                <span className="min-w-0 flex-1">
                  <span className="flex flex-wrap items-center gap-1">
                    <span className="font-semibold text-slate-900">{app.name}</span>
                    {app.installed && <Badge tone="blue">{STATUS_LABEL[app.installed] ?? 'Installed'}</Badge>}
                  </span>
                  <span className="mt-1 block text-sm text-slate-600">{app.tagline}</span>
                  <span className="mt-2 block text-xs text-slate-500">
                    {app.publisher} · {app.trust_label} · {app.pricing === 'free' ? 'Free' : app.pricing} · {app.category_label}
                  </span>
                  {app.high_risk && <span className="mt-1 block text-xs text-amber-800">Reads personal data</span>}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {open && <AppDrawer slug={open} onClose={() => setOpen(null)} onChanged={list.reload} />}
    </AdminPage>
  );
}

function AppMark({ name, big = false }: { name: string; big?: boolean }) {
  return (
    <span aria-hidden="true" className={`flex flex-none items-center justify-center rounded-lg bg-indigo-600 font-bold text-white ${big ? 'h-14 w-14 text-2xl' : 'h-11 w-11 text-lg'}`}>
      {name.slice(0, 1).toUpperCase()}
    </span>
  );
}

function PermissionList({ items, events }: { items: Permission[]; events: string[] }) {
  if (items.length === 0 && events.length === 0) return <p className="text-sm text-slate-600">It reads nothing from your store.</p>;

  return (
    <ul className="space-y-2 text-sm">
      {items.map((p) => (
        <li key={p.scope} className="flex items-start gap-2">
          {p.risk === 'high' ? <Badge tone="amber">Personal data</Badge> : <Badge>Store data</Badge>}
          <span>{p.label}</span>
        </li>
      ))}
      {events.length > 0 && (
        <li className="flex items-start gap-2">
          <Badge tone="blue">Notifications</Badge>
          <span>Be told when these happen: {events.map((e) => e.replace('.', ' ').replace('_', ' ')).join(', ')}</span>
        </li>
      )}
      <li className="text-xs text-slate-500">It can only read. It cannot change, delete, refund or pay anything in your store.</li>
    </ul>
  );
}

function AppDrawer({ slug, onClose, onChanged }: { slug: string; onClose: () => void; onChanged: () => void }) {
  const state = useApi<{ data: Detail }>(`/apps/${slug}`);
  const { busy, run } = useAction();
  const [consent, setConsent] = useState<null | 'install' | 'update'>(null);
  const [agreed, setAgreed] = useState(false);
  const [removing, setRemoving] = useState(false);
  const d = state.data?.data;
  const inst = d?.installation ?? null;

  function done() {
    state.reload();
    onChanged();
  }

  function go(url: string | null) {
    if (url) window.location.assign(url);
    else done();
  }

  function install() {
    run('install', () => adminFetch<{ data: { redirect_url: string | null } }>(`/apps/${slug}/install`, { method: 'POST', body: { accept_permissions: true } }), {
      success: 'Installed. Finish connecting on the app’s site.',
    }).then((r) => {
      if (!r) return;
      setConsent(null);
      go(r.data.redirect_url);
    });
  }

  function connect() {
    run('connect', () => adminFetch<{ data: { redirect_url: string } }>(`/apps/${slug}/connect`, { method: 'POST' })).then((r) => r && go(r.data.redirect_url));
  }

  function simple(step: 'disable' | 'enable', success: string) {
    run(step, () => adminFetch(`/apps/${slug}/${step}`, { method: 'POST' }), { success }).then((r) => r && done());
  }

  function update() {
    run('update', () => adminFetch(`/apps/${slug}/update`, { method: 'POST', body: { accept_permissions: true } }), { success: 'Updated.' }).then((r) => {
      if (r) {
        setConsent(null);
        done();
      }
    });
  }

  function remove() {
    run('remove', () => adminFetch(`/apps/${slug}`, { method: 'DELETE' }), { success: 'Removed. Its access ended at once.' }).then((r) => {
      if (r) {
        setRemoving(false);
        done();
      }
    });
  }

  const publisher = d?.publisher ?? null;

  return (
    <Dialog open side wide title={d?.name ?? 'App'} description={d ? `${publisher?.name ?? ''} · ${d.trust_label}` : undefined} onClose={onClose}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : !d ? (
        <Skeleton lines={8} />
      ) : (
        <div className="space-y-5 text-sm">
          <div className="flex items-start gap-3">
            <AppMark name={d.name} big />
            <div>
              <p className="text-slate-700">{d.tagline}</p>
              <p className="mt-1 text-xs text-slate-500">
                {d.category_label} · {d.pricing === 'free' ? 'Free' : d.pricing} · version {inst?.version ?? d.version}
              </p>
            </div>
          </div>

          {inst?.status === 'suspended' && (
            <p role="alert" className="rounded-md border border-red-200 bg-red-50 p-3 text-red-800">
              Umar Techy suspended this app for every store{inst.suspended_reason ? `: ${inst.suspended_reason}` : ''}. It cannot read your store or receive events until it is resumed.
            </p>
          )}
          {inst?.status === 'awaiting_connection' && (
            <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-900">
              <p>Installed, but the app has not connected yet. Continue on the app’s site to finish.</p>
              {d.can_manage && (
                <Button size="sm" className="mt-2" busy={busy === 'connect'} onClick={connect}>
                  Continue connecting
                </Button>
              )}
            </div>
          )}

          {inst ? (
            <InstalledPanel slug={slug} detail={d} onChanged={done} busy={busy} onSimple={simple} onConnect={connect} onUpdate={() => setConsent('update')} onRemove={() => setRemoving(true)} />
          ) : (
            <>
              {d.problems.length > 0 && (
                <div role="note" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-900">
                  {d.problems.map((p) => (
                    <p key={p.code}>{p.message}</p>
                  ))}
                </div>
              )}
              {d.can_manage ? (
                <Button variant="primary" disabled={d.problems.length > 0} onClick={() => { setAgreed(false); setConsent('install'); }}>
                  Install
                </Button>
              ) : (
                <p className="text-slate-600">Only the store owner can install apps.</p>
              )}
            </>
          )}

          <Card title="About">
            <p className="whitespace-pre-line text-slate-700">{d.description}</p>
          </Card>
          <Card title="What it can see and do" description="Checked by the platform on every request.">
            <PermissionList items={inst?.permissions ?? d.permissions} events={inst?.events ?? d.events} />
          </Card>
          {d.data_use && (
            <Card title="How it uses your data" description="In the publisher’s words; reviewed by Umar Techy.">
              <p className="whitespace-pre-line text-slate-700">{d.data_use}</p>
            </Card>
          )}
          <Card title="Publisher">
            <ul className="space-y-1">
              <li>{publisher?.name}</li>
              {publisher?.website && <li><a className="text-indigo-700 underline" href={publisher.website} target="_blank" rel="noopener noreferrer">Website</a></li>}
              {publisher?.support_email && <li>Support: {publisher.support_email}</li>}
              <li><a className="text-indigo-700 underline" href={d.privacy_url} target="_blank" rel="noopener noreferrer">Privacy policy</a>{d.terms_url && <> · <a className="text-indigo-700 underline" href={d.terms_url} target="_blank" rel="noopener noreferrer">Terms</a></>}</li>
            </ul>
          </Card>
        </div>
      )}

      {d && consent && (
        <Dialog
          open
          title={consent === 'install' ? `Install ${d.name}?` : `Update ${d.name} to ${inst?.update?.version}?`}
          description={consent === 'install' ? 'Read what it may see first. You will be asked to confirm it is you.' : 'This version asks for more. Read it first.'}
          onClose={() => setConsent(null)}
          busy={busy !== null}
          footer={
            <>
              <Button variant="ghost" onClick={() => setConsent(null)}>Cancel</Button>
              <Button variant="primary" disabled={!agreed} busy={busy === 'install' || busy === 'update'} onClick={consent === 'install' ? install : update}>
                {consent === 'install' ? 'Install and connect' : 'Update'}
              </Button>
            </>
          }
        >
          <div className="space-y-4 text-sm">
            <PermissionList items={consent === 'install' ? d.permissions : inst?.update?.new_permissions ?? []} events={consent === 'install' ? d.events : inst?.update?.new_events ?? []} />
            <CheckboxField label={`I allow ${d.name} to see this for my store`} checked={agreed} onChange={setAgreed} hint="You can remove the app at any time; its access then ends at once." />
            {consent === 'install' && (d.permissions.length > 0 || d.events.length > 0) && <p className="text-slate-600">Next you go to {publisher?.name}’s site to finish connecting.</p>}
          </div>
        </Dialog>
      )}

      <ConfirmDialog open={removing} title={`Remove ${d?.name ?? 'this app'}?`} confirmLabel="Remove app" busy={busy === 'remove'} onClose={() => setRemoving(false)} onConfirm={remove}>
        <p className="text-sm text-slate-700">Its access to your store ends at once, and your settings for it are deleted. Data it already copied to its own service is covered by its privacy policy; ask the publisher to delete it if you need to.</p>
      </ConfirmDialog>
    </Dialog>
  );
}

function InstalledPanel({ slug, detail, onChanged, busy, onSimple, onConnect, onUpdate, onRemove }: {
  slug: string; detail: Detail; onChanged: () => void; busy: string | null;
  onSimple: (step: 'disable' | 'enable', success: string) => void; onConnect: () => void; onUpdate: () => void; onRemove: () => void;
}) {
  const inst = detail.installation!;
  const [health, tone] = HEALTH[inst.usage.health] ?? ['Unknown', 'neutral'];

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-2">
        <StatusBadge status={inst.status} label={STATUS_LABEL[inst.status] ?? inst.status} />
        <Badge tone={tone}>{health}</Badge>
        {inst.installed_at && <span className="text-xs text-slate-500">Installed {formatDate(inst.installed_at)}</span>}
      </div>

      {inst.update && (
        <div className="rounded-md border border-indigo-200 bg-indigo-50 p-3">
          <p className="font-medium text-indigo-900">Version {inst.update.version} is available.</p>
          {inst.update.release_notes && <p className="mt-1 whitespace-pre-line text-indigo-900">{inst.update.release_notes}</p>}
          {detail.can_manage && (
            <Button size="sm" className="mt-2" busy={busy === 'update'} onClick={onUpdate}>
              {inst.update.new_permissions.length > 0 || inst.update.new_events.length > 0 ? 'Review and update' : 'Update'}
            </Button>
          )}
        </div>
      )}

      <Card title="Last 30 days">
        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Stat label="Reads of your store" value={inst.usage.api_calls} />
          <Stat label="Failed reads" value={inst.usage.api_errors} />
          <Stat label="Notifications sent" value={inst.usage.webhooks_sent} />
          <Stat label="Notifications failed" value={inst.usage.webhooks_failed} />
        </dl>
        <p className="mt-2 text-xs text-slate-500">{inst.usage.last_used_at ? `Last used ${formatDateTime(inst.usage.last_used_at)}.` : 'Not used yet.'}</p>
      </Card>

      {inst.settings_form.length > 0 && <SettingsForm slug={slug} fields={inst.settings_form} values={inst.settings} canManage={detail.can_manage && inst.status !== 'suspended'} onSaved={onChanged} />}

      {detail.can_manage && inst.status !== 'suspended' && (
        <div className="flex flex-wrap gap-2">
          {inst.status === 'active' && <Button size="sm" busy={busy === 'disable'} onClick={() => onSimple('disable', 'Paused.')}>Pause</Button>}
          {inst.status === 'disabled' && <Button size="sm" busy={busy === 'enable'} onClick={() => onSimple('enable', 'Turned back on.')}>Turn back on</Button>}
          {inst.status === 'active' && <Button size="sm" variant="ghost" busy={busy === 'connect'} onClick={onConnect}>Connect again</Button>}
          <Button size="sm" variant="danger" onClick={onRemove}>Remove app</Button>
        </div>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-md bg-slate-50 p-2">
      <dt className="text-xs text-slate-600">{label}</dt>
      <dd className="text-lg font-semibold">{value}</dd>
    </div>
  );
}

/** Module 27 §45: the app's settings form, drawn from its definition. Secrets are never shown again. */
function SettingsForm({ slug, fields, values, canManage, onSaved }: { slug: string; fields: Field[]; values: Record<string, unknown>; canManage: boolean; onSaved: () => void }) {
  const initial = () =>
    Object.fromEntries(fields.map((f) => [f.key, f.type === 'secret' ? '' : f.type === 'boolean' ? Boolean(values[f.key]) : values[f.key] === null || values[f.key] === undefined ? '' : String(values[f.key])]));
  const [form, setForm] = useState<Record<string, string | boolean>>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  // The server's values after a save (secrets come back only as "saved").
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => setForm(initial()), [values]);

  function save() {
    setErrors({});
    setError(null);
    setSaving(true);
    const settings = Object.fromEntries(fields.map((f) => [f.key, f.type === 'number' && form[f.key] !== '' ? Number(form[f.key]) : form[f.key]]));
    adminFetch(`/apps/${slug}/settings`, { method: 'PUT', body: { settings } })
      .then(() => {
        toast.success('Settings saved.');
        onSaved();
      })
      .catch((e) => {
        setErrors(fieldErrorsFrom(e));
        setError(adminErrorMessage(e));
      })
      .finally(() => setSaving(false));
  }

  return (
    <Card title="Settings" description="What the app needs from you. Only the app can read them; secrets are kept encrypted and never shown again.">
      <form
        className="space-y-3"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          save();
        }}
      >
        <FormError message={error} />
        {fields.map((f) =>
          f.type === 'boolean' ? (
            <CheckboxField key={f.key} label={f.label} checked={Boolean(form[f.key])} disabled={!canManage} onChange={(v) => setForm((o) => ({ ...o, [f.key]: v }))} hint={f.help} />
          ) : f.type === 'select' ? (
            <SelectField
              key={f.key}
              label={f.label}
              optional={!f.required}
              value={String(form[f.key] ?? '')}
              disabled={!canManage}
              onChange={(v) => setForm((o) => ({ ...o, [f.key]: v }))}
              options={(f.options ?? []).map((o) => ({ value: o, label: o }))}
              placeholder="Choose"
              hint={f.help}
              error={errors[f.key]}
            />
          ) : (
            <TextField
              key={f.key}
              label={f.label}
              optional={!f.required}
              type={f.type === 'secret' ? 'password' : f.type === 'number' ? 'number' : f.type === 'url' ? 'url' : 'text'}
              autoComplete={f.type === 'secret' ? 'new-password' : undefined}
              value={String(form[f.key] ?? '')}
              disabled={!canManage}
              onChange={(v) => setForm((o) => ({ ...o, [f.key]: v }))}
              placeholder={f.type === 'secret' && (values[f.key] as { saved?: boolean } | undefined)?.saved ? 'Saved — leave empty to keep' : undefined}
              hint={f.help}
              error={errors[f.key]}
            />
          ),
        )}
        {canManage && (
          <div className="flex justify-end">
            <Button type="submit" variant="primary" busy={saving}>
              Save settings
            </Button>
          </div>
        )}
      </form>
    </Card>
  );
}

function fieldErrorsFrom(e: unknown): Record<string, string> {
  if (!(e instanceof AdminApiError)) return {};
  const fields = (e.body?.errors ?? {}) as Record<string, string[]>;

  return Object.fromEntries(Object.entries(fields).map(([k, m]) => [k.replace(/^settings\./, ''), m[0]]));
}
