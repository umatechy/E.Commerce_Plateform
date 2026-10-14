import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import Dialog from '@/Components/ui/Dialog';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import { FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import { Card, EmptyPanel, QueryState, StatCard, Tabs } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { useUrlState } from '@/lib/useUrlState';
import { adminFetch } from '@/lib/adminApi';
import { formatDateTime } from '@/lib/datetime';
import {
  EVENT_LABEL, INCIDENT_TONE, SEVERITY_TONE, STATUS_LABEL, percent,
  type Control, type Incident, type Overview, type RoleInfo, type SecurityEventRow, type StaffRow,
} from '@/lib/security';

/**
 * Phase B52 — Super Admin → Security (Module 32 §58–64 monitoring,
 * alerting, incidents, §85 dashboard; Module 30 §3 roles, §58–61 security
 * operations, emergency controls and kill switches, §87–88 incidents).
 *
 * Everyone with platform access can look; changes need the "security"
 * capability (super admin, security admin) and ask for step-up. Buttons a
 * role cannot use are not shown; the server refuses them anyway.
 */
const TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'controls', label: 'Emergency controls' },
  { id: 'staff', label: 'Platform staff' },
  { id: 'events', label: 'Security events' },
  { id: 'incidents', label: 'Incidents' },
];

export default function Security() {
  const access = useAccess();
  const canManage = access.platformCan('security');
  const [state, setState] = useUrlState({ tab: 'overview', type: '', severity: '', q: '', page: '1' });
  const overview = useApi<{ data: Overview }>('/super-admin/security');
  const tab = TABS.some((t) => t.id === state.tab) ? state.tab : 'overview';

  return (
    <AdminPage title="Security" description="Sign-in problems, alerts, who has which platform role, incidents and the emergency switches.">
      <Tabs label="Security sections" tabs={TABS} active={tab} onChange={(next) => setState({ tab: next })} />
      <div role="tabpanel" className="mt-4">
        <QueryState state={overview} lines={6}>
          {({ data }) => (
            <>
              {tab === 'overview' && <OverviewTab data={data} />}
              {tab === 'controls' && <ControlsTab controls={data.controls} canManage={canManage} onChanged={overview.reload} />}
              {tab === 'staff' && <StaffTab roles={data.roles} capabilities={data.capabilities} canManage={canManage} />}
              {tab === 'events' && <EventsTab filters={state} onFilters={setState} />}
              {tab === 'incidents' && <IncidentsTab severities={data.severities} canManage={canManage} onChanged={overview.reload} />}
            </>
          )}
        </QueryState>
      </div>
    </AdminPage>
  );
}

function OverviewTab({ data }: { data: Overview }) {
  const open = Object.entries(data.open_incidents);
  const openTotal = open.reduce((n, [, c]) => n + c, 0);
  const emergencies = data.controls.filter((c) => c.emergency);

  return (
    <div className="space-y-6">
      {emergencies.length > 0 && (
        <p role="status" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900">
          <strong>Emergency control active:</strong> {emergencies.map((c) => c.label).join(', ')}. See Emergency controls.
        </p>
      )}
      <section aria-label="Last 24 hours" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Failed sign-ins, 24 h" value={data.failed_logins_24h} hint={`${data.failed_privileged_logins_24h} on platform staff accounts`} />
        <StatCard label="Sign-ins slowed down, 24 h" value={data.lockouts_24h} hint="Too many tries for one account" />
        <StatCard label="Alerts, 7 days" value={data.alerts_7d} hint="Emailed to platform staff" />
        <StatCard label="Open incidents" value={openTotal} hint={open.length ? open.map(([s, n]) => `${s.toUpperCase()}: ${n}`).join(' · ') : 'None'} />
      </section>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Two-step sign-in" description="Required for platform staff and store owners.">
          <Meter label="Platform staff" part={data.mfa.staff_with_mfa} whole={data.mfa.staff} />
          <div className="mt-3"><Meter label="Store owners" part={data.mfa.owners_with_mfa} whole={data.mfa.owners} /></div>
          <p className="mt-3 text-xs text-slate-600">{data.privileged_sessions} platform staff session(s) active now. Staff sessions end after {data.session_limits.idle_minutes} minutes without use and after {data.session_limits.absolute_hours} hours at the latest.</p>
        </Card>
        <Card title="Backups and restore tests">
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-2"><dt className="text-slate-600">Last verified backup</dt><dd>{data.backups.last_verified_at ? formatDateTime(data.backups.last_verified_at) : <Badge tone="red">None</Badge>}</dd></div>
            <div className="flex justify-between gap-2"><dt className="text-slate-600">Last restore rehearsal</dt><dd>{data.backups.last_rehearsal_at ? formatDateTime(data.backups.last_rehearsal_at) : <Badge tone="amber">Never</Badge>}</dd></div>
            <div className="flex justify-between gap-2"><dt className="text-slate-600">Last scheduled backup</dt><dd>{data.backups.last_scheduled_status ?? '—'}</dd></div>
          </dl>
          <p className="mt-3 text-xs text-slate-500">Dependency vulnerabilities are checked on every push by the CI security job.</p>
        </Card>
      </div>
      <Card title="Recent alerts" description="What security monitoring emailed to platform staff.">
        {data.recent_alerts.length === 0 ? <p className="text-sm text-slate-600">No alerts.</p> : (
          <ul className="divide-y divide-slate-100">
            {data.recent_alerts.map((a) => (
              <li key={a.id} className="py-3 text-sm">
                <p className="flex flex-wrap items-center gap-2 font-medium text-slate-900"><Badge tone={SEVERITY_TONE[a.severity] ?? 'neutral'}>{a.severity}</Badge>{a.summary}</p>
                <p className="mt-0.5 text-slate-700">{a.details}</p>
                <p className="mt-0.5 text-xs text-slate-500">{formatDateTime(a.at)} · What to do: {a.action}</p>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}

function Meter({ label, part, whole }: { label: string; part: number; whole: number }) {
  const p = percent(part, whole);

  return (
    <div>
      <div className="flex justify-between text-sm"><span>{label}</span><span className={p < 100 ? 'font-medium text-amber-800' : 'text-emerald-800'}>{part} of {whole} ({p}%)</span></div>
      <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-200" aria-hidden="true"><div className={`h-full rounded-full ${p < 100 ? 'bg-amber-500' : 'bg-emerald-600'}`} style={{ width: `${p}%` }} /></div>
    </div>
  );
}

function ControlsTab({ controls, canManage, onChanged }: { controls: Control[]; canManage: boolean; onChanged: () => void }) {
  const [editing, setEditing] = useState<Control | null>(null);

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">For an incident. Each switch takes effect at once for the whole platform, is recorded with your reason and emails an alert to platform staff. Narrower controls stay where they live: a store on its Store life card, a payment or SMS provider on its page, an account on Users.</p>
      <ul className="grid gap-4 lg:grid-cols-3">
        {controls.map((c) => (
          <li key={c.key} className={`flex flex-col rounded-lg border p-4 ${c.emergency ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-white'}`}>
            <div className="flex items-start justify-between gap-2">
              <h3 className="font-semibold text-slate-900">{c.label}</h3>
              {c.emergency ? <Badge tone="red">Emergency setting</Badge> : <Badge tone="green">Normal</Badge>}
            </div>
            <p className="mt-1 text-sm text-slate-700">Now: <strong>{c.value ? 'On' : 'Off'}</strong></p>
            <p className="mt-2 text-xs text-slate-600">{c.impact}</p>
            {c.changed_at && <p className="mt-2 text-xs text-slate-500">Changed {formatDateTime(c.changed_at)}{c.changed_by ? ` by ${c.changed_by}` : ''}{c.changed_reason ? `: ${c.changed_reason}` : ''}</p>}
            {canManage && (
              <div className="mt-auto pt-3">
                <Button size="sm" variant={c.emergency ? 'primary' : 'danger'} onClick={() => setEditing(c)}>
                  {c.emergency ? 'Back to normal' : `Turn ${c.emergency_value ? 'on' : 'off'}`}<span className="sr-only"> {c.label}</span>
                </Button>
              </div>
            )}
          </li>
        ))}
      </ul>
      {editing && <ControlDialog control={editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); onChanged(); }} />}
    </div>
  );
}

function ControlDialog({ control, onClose, onDone }: { control: Control; onClose: () => void; onDone: () => void }) {
  const form = useForm({ reason: '' });
  const target = !control.value;

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(`/super-admin/security/emergency/${control.key}`, { method: 'PUT', body: { value: target, reason: form.values.reason.trim() } }), `${control.label} turned ${target ? 'on' : 'off'}.`)) !== undefined) onDone();
  }

  return (
    <Dialog open title={`Turn ${control.label.toLowerCase()} ${target ? 'on' : 'off'}?`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <p className="text-sm text-slate-700">{target === control.emergency_value ? control.impact : 'Everything works normally again.'}</p>
        <TextAreaField label="Reason" rows={2} maxLength={300} required value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} hint="With the incident reference, if there is one. Kept in the audit trail." data-autofocus />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant={target === control.emergency_value ? 'danger' : 'primary'} busy={form.busy} busyLabel="Saving…">{`Turn ${target ? 'on' : 'off'}`}</Button>
        </div>
      </form>
    </Dialog>
  );
}

function StaffTab({ roles, capabilities, canManage }: { roles: RoleInfo[]; capabilities: Record<string, string>; canManage: boolean }) {
  const list = useApi<{ data: StaffRow[] }>('/super-admin/security/staff');
  const [editing, setEditing] = useState<StaffRow | null>(null);
  const [ending, setEnding] = useState<StaffRow | null>(null);
  const columns: Column<StaffRow>[] = [
    { key: 'who', header: 'Person', render: (s) => <span><span className="font-medium">{s.name}</span><span className="block break-all text-xs text-slate-500">{s.email}</span></span> },
    { key: 'role', header: 'Role', priority: true, render: (s) => <Badge tone={s.role === 'super_admin' ? 'red' : 'blue'}>{s.role_label}</Badge> },
    { key: 'mfa', header: 'Two-step', render: (s) => (s.mfa ? <Badge tone="green">On</Badge> : <Badge tone="red">Off</Badge>) },
    { key: 'seen', header: 'Last used', render: (s) => (s.last_seen_at ? formatDateTime(s.last_seen_at) : '—') },
    { key: 'sessions', header: 'Signed in', align: 'right', render: (s) => (s.is_active ? `${s.open_sessions} session(s)` : <Badge>Deactivated</Badge>) },
    ...(canManage ? [{ key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right' as const, priority: true, render: (s: StaffRow) => (
      <span className="flex flex-wrap justify-end gap-1">
        <Button size="sm" variant="ghost" onClick={() => setEditing(s)}>Role<span className="sr-only"> of {s.name}</span></Button>
        {s.open_sessions > 0 && <Button size="sm" variant="ghost" onClick={() => setEnding(s)}>Sign out<span className="sr-only"> {s.name}</span></Button>}
      </span>
    ) }] : []),
  ];

  return (
    <div className="space-y-6">
      <DataTable caption="Platform staff" columns={columns} rows={list.data?.data ?? null} rowKey={(s) => s.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No platform staff" />} />
      <p className="text-xs text-slate-500">New platform staff are added on the server only (<code>php artisan platform:staff</code>), never from this screen.</p>
      <Card title="What each role may do">
        <ul className="grid gap-3 md:grid-cols-2">
          {roles.map((r) => (
            <li key={r.key} className="rounded-md border border-slate-200 p-3 text-sm">
              <p className="font-medium text-slate-900">{r.label}</p>
              <p className="text-slate-600">{r.description}</p>
              <p className="mt-1 text-xs text-slate-500">{r.capabilities.filter((c) => c !== 'view').map((c) => capabilities[c] ?? c).join(' · ') || 'Read only'}</p>
            </li>
          ))}
        </ul>
      </Card>
      {editing && <RoleDialog staff={editing} roles={roles} onClose={() => setEditing(null)} onDone={() => { setEditing(null); list.reload(); }} />}
      {ending && <EndSessionsDialog staff={ending} onClose={() => setEnding(null)} onDone={() => { setEnding(null); list.reload(); }} />}
    </div>
  );
}

function RoleDialog({ staff, roles, onClose, onDone }: { staff: StaffRow; roles: RoleInfo[]; onClose: () => void; onDone: () => void }) {
  const form = useForm({ role: staff.role, reason: '' });
  const chosen = roles.find((r) => r.key === form.values.role);

  async function save(event: FormEvent) {
    event.preventDefault();
    const role = form.values.role === 'none' ? null : form.values.role;
    if ((await form.submit(() => adminFetch(`/super-admin/users/${staff.id}/platform-role`, { method: 'PUT', body: { role, reason: form.values.reason.trim() } }), 'Platform role changed.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={`Platform role of ${staff.name}`} description={staff.email} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <SelectField label="Role" value={form.values.role} onChange={(v) => form.set('role', v)} error={form.errors.role}
          options={[...roles.map((r) => ({ value: r.key, label: r.label })), { value: 'none', label: 'No platform access' }]} />
        <p className="text-sm text-slate-600">{form.values.role === 'none' ? 'The person keeps their account but loses the Super Admin. Their sessions end.' : chosen?.description}</p>
        <TextField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={300} required />
        <p className="text-xs text-slate-500">Recorded, and emailed as an alert to platform staff. Fewer rights also end the person’s sessions.</p>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={form.values.role === staff.role}>Change role</Button>
        </div>
      </form>
    </Dialog>
  );
}

function EndSessionsDialog({ staff, onClose, onDone }: { staff: StaffRow; onClose: () => void; onDone: () => void }) {
  const form = useForm({ reason: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(`/super-admin/users/${staff.id}/sessions/revoke`, { method: 'POST', body: { reason: form.values.reason.trim() } }), `${staff.name} was signed out everywhere.`)) !== undefined) onDone();
  }

  return (
    <Dialog open title={`Sign ${staff.name} out everywhere?`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <p className="text-sm text-slate-700">All {staff.open_sessions} session(s) end on their next click. The person can sign in again with password and code; to stop that, deactivate the account under Users.</p>
        <TextField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={300} required data-autofocus />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="danger" busy={form.busy} busyLabel="Signing out…">Sign out everywhere</Button>
        </div>
      </form>
    </Dialog>
  );
}

function EventsTab({ filters, onFilters }: { filters: { type: string; severity: string; q: string; page: string }; onFilters: (next: Partial<{ type: string; severity: string; q: string; page: string }>) => void }) {
  const list = useApi<{ data: SecurityEventRow[]; meta: { current_page: number; last_page: number; total: number } }>('/super-admin/security/events', { type: filters.type, severity: filters.severity, q: filters.q, page: filters.page });
  const columns: Column<SecurityEventRow>[] = [
    { key: 'at', header: 'When', render: (e) => formatDateTime(e.at) },
    { key: 'type', header: 'Event', priority: true, render: (e) => <span><span className="font-medium">{EVENT_LABEL[e.type] ?? e.type}</span>{e.summary && <span className="block text-xs text-slate-600">{e.summary}</span>}</span> },
    { key: 'severity', header: 'Severity', render: (e) => <Badge tone={SEVERITY_TONE[e.severity] ?? 'neutral'}>{e.severity}</Badge> },
    { key: 'who', header: 'Account', render: (e) => <span className="break-all">{e.user?.email ?? e.subject ?? '—'}</span> },
    { key: 'ip', header: 'Address', render: (e) => <span className="font-mono text-xs">{e.ip_address ?? '—'}</span> },
  ];

  return (
    <div className="space-y-3">
      <FilterBar>
        <div className="w-56"><SelectField label="Event" value={filters.type} onChange={(v) => onFilters({ type: v, page: '1' })} placeholder="All events" options={Object.entries(EVENT_LABEL).map(([value, label]) => ({ value, label }))} /></div>
        <div className="w-40"><SelectField label="Severity" value={filters.severity} onChange={(v) => onFilters({ severity: v, page: '1' })} placeholder="Any" options={['critical', 'high', 'medium', 'low', 'info'].map((s) => ({ value: s, label: s }))} /></div>
        <div className="w-72 max-w-full"><SearchField label="Search" value={filters.q} onChange={(q) => onFilters({ q, page: '1' })} placeholder="Email, IP address or store" /></div>
      </FilterBar>
      <DataTable caption="Security events" columns={columns} rows={list.data?.data ?? null} rowKey={(e) => e.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No events match" />} />
      {list.data && <Pagination meta={{ page: list.data.meta.current_page, lastPage: list.data.meta.last_page, total: list.data.meta.total, perPage: 50 }} disabled={list.loading} onPage={(p) => onFilters({ page: String(p) })} />}
    </div>
  );
}

function IncidentsTab({ severities, canManage, onChanged }: { severities: Overview['severities']; canManage: boolean; onChanged: () => void }) {
  const list = useApi<{ data: Incident[] }>('/super-admin/security/incidents');
  const [opening, setOpening] = useState(false);
  const [open, setOpen] = useState<string | null>(null);
  const columns: Column<Incident>[] = [
    { key: 'ref', header: 'Incident', render: (i) => <span><span className="font-mono text-xs text-slate-500">{i.reference}</span><span className="block font-medium">{i.title}</span></span> },
    { key: 'sev', header: 'Severity', priority: true, render: (i) => <Badge tone={INCIDENT_TONE[i.severity] ?? 'neutral'}>{i.severity.toUpperCase()}</Badge> },
    { key: 'status', header: 'Status', priority: true, render: (i) => STATUS_LABEL[i.status] ?? i.status },
    { key: 'owner', header: 'Owner', render: (i) => i.owner?.name ?? '—' },
    { key: 'detected', header: 'Detected', render: (i) => formatDateTime(i.detected_at) },
    { key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (i) => <Button size="sm" variant="ghost" onClick={() => setOpen(i.id)}>Open<span className="sr-only"> {i.reference}</span></Button> },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-slate-600">Each incident keeps a timeline that is only added to. Procedure: docs/security/incident-response-runbook.md.</p>
        {canManage && <Button variant="primary" onClick={() => setOpening(true)}>Open an incident</Button>}
      </div>
      <DataTable caption="Incidents" columns={columns} rows={list.data?.data ?? null} rowKey={(i) => i.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No incidents" description="Open one when something goes wrong, so the steps and decisions are on record." />} />
      <details className="rounded-md border border-slate-200 p-3 text-sm">
        <summary className="cursor-pointer font-medium">Severity levels</summary>
        <ul className="mt-2 space-y-1">{severities.map((s) => <li key={s.key}><Badge tone={INCIDENT_TONE[s.key] ?? 'neutral'}>{s.label}</Badge> <span className="text-slate-700">{s.description}</span></li>)}</ul>
      </details>
      {opening && <OpenIncidentDialog severities={severities} onClose={() => setOpening(false)} onDone={(id) => { setOpening(false); list.reload(); onChanged(); setOpen(id); }} />}
      {open && <IncidentDrawer id={open} canManage={canManage} onClose={() => setOpen(null)} onChanged={() => { list.reload(); onChanged(); }} />}
    </div>
  );
}

function OpenIncidentDialog({ severities, onClose, onDone }: { severities: Overview['severities']; onClose: () => void; onDone: (id: string) => void }) {
  const form = useForm({ title: '', severity: 'sev2', component: '', scope: 'platform', impact: '', note: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const v = form.values;
    const saved = await form.submit(() => adminFetch<{ data: Incident }>('/super-admin/security/incidents', { method: 'POST', body: { title: v.title, severity: v.severity, component: v.component, scope: v.scope, impact: v.impact || null, note: v.note || null } }), 'Incident opened.');
    if (saved !== undefined) onDone(saved.data.id);
  }

  return (
    <Dialog open wide title="Open an incident" onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="What is happening" value={form.values.title} onChange={(v) => form.set('title', v)} error={form.errors.title} maxLength={200} required data-autofocus placeholder="For example: Many failed sign-ins on staff accounts" />
        <div className="grid gap-4 sm:grid-cols-3">
          <SelectField label="Severity" value={form.values.severity} onChange={(v) => form.set('severity', v)} options={severities.map((s) => ({ value: s.key, label: `${s.label} — ${s.description.split(':')[0]}` }))} />
          <TextField label="Component" value={form.values.component} onChange={(v) => form.set('component', v)} error={form.errors.component} maxLength={60} required placeholder="Sign-in, payments, backups…" />
          <SelectField label="Affects" value={form.values.scope} onChange={(v) => form.set('scope', v)} options={[{ value: 'platform', label: 'The whole platform' }, { value: 'stores', label: 'Some stores' }, { value: 'store', label: 'One store' }, { value: 'provider', label: 'A provider' }]} />
        </div>
        <TextAreaField label="Impact" optional rows={2} maxLength={5000} value={form.values.impact} onChange={(v) => form.set('impact', v)} hint="Who or what is affected, as far as known." />
        <TextAreaField label="First note" optional rows={2} maxLength={5000} value={form.values.note} onChange={(v) => form.set('note', v)} hint="How it was detected, first actions." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Opening…">Open incident</Button>
        </div>
      </form>
    </Dialog>
  );
}

function IncidentDrawer({ id, canManage, onClose, onChanged }: { id: string; canManage: boolean; onClose: () => void; onChanged: () => void }) {
  const state = useApi<{ data: Incident }>(`/super-admin/security/incidents/${id}`);
  const form = useForm({ status: '', note: '', resolution: '', postmortem: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const v = form.values;
    const body = { status: v.status || null, note: v.note || null, resolution: v.resolution || null, postmortem_reference: v.postmortem || null };
    if ((await form.submit(() => adminFetch(`/super-admin/security/incidents/${id}`, { method: 'PATCH', body }), 'Incident updated.')) !== undefined) {
      form.reset({ status: '', note: '', resolution: '', postmortem: '' });
      state.reload();
      onChanged();
    }
  }

  return (
    <Dialog open side title={state.data?.data.reference ?? 'Incident'} description={state.data?.data.title} onClose={onClose}>
      <QueryState state={state} lines={6}>
        {({ data: i }) => (
          <div className="space-y-4 text-sm">
            <div className="flex flex-wrap gap-2">
              <Badge tone={INCIDENT_TONE[i.severity] ?? 'neutral'}>{i.severity.toUpperCase()}</Badge>
              <Badge tone={i.status === 'closed' ? 'neutral' : i.status === 'resolved' ? 'green' : 'amber'}>{STATUS_LABEL[i.status] ?? i.status}</Badge>
              <span className="text-slate-600">{i.component} · owner {i.owner?.name ?? '—'}</span>
            </div>
            {i.impact && <p className="text-slate-700"><span className="font-medium">Impact:</span> {i.impact}</p>}
            {i.resolution && <p className="text-slate-700"><span className="font-medium">Resolution:</span> {i.resolution}</p>}
            {i.postmortem_reference && <p className="text-slate-700"><span className="font-medium">Post-incident review:</span> {i.postmortem_reference}</p>}
            <section aria-label="Timeline">
              <h3 className="font-semibold text-slate-900">Timeline</h3>
              <ol className="mt-2 space-y-2 border-l-2 border-slate-200 pl-3">
                {(i.timeline ?? []).map((t, n) => (
                  <li key={n}>
                    <p className="text-xs text-slate-500">{formatDateTime(t.at)} · {t.author ?? 'system'}</p>
                    <p className="text-slate-800">
                      {t.kind === 'opened' ? 'Opened' : t.kind === 'status' ? `Status: ${STATUS_LABEL[t.from ?? ''] ?? t.from} → ${STATUS_LABEL[t.to ?? ''] ?? t.to}` : t.kind === 'severity' ? `Severity: ${t.from?.toUpperCase()} → ${t.to?.toUpperCase()}` : t.kind === 'owner' ? 'Owner changed' : 'Note'}
                    </p>
                    {t.note && <p className="whitespace-pre-line text-slate-700">{t.note}</p>}
                  </li>
                ))}
              </ol>
            </section>
            {canManage && i.status !== 'closed' && (
              <form onSubmit={save} className="space-y-3 border-t border-slate-100 pt-3" noValidate>
                <FormError message={form.formError} />
                <SelectField label="Move to" value={form.values.status} onChange={(v) => form.set('status', v)} placeholder="Keep the status" error={form.errors.status}
                  options={Object.entries(STATUS_LABEL).filter(([k]) => k !== i.status).map(([value, label]) => ({ value, label }))} />
                <TextAreaField label="Note" optional rows={3} maxLength={5000} value={form.values.note} onChange={(v) => form.set('note', v)} hint="What was found or done. Added to the timeline." />
                {(form.values.status === 'resolved' || form.values.status === 'closed' || i.status === 'resolved') && (
                  <>
                    <TextAreaField label="Resolution" optional rows={2} maxLength={5000} value={form.values.resolution} onChange={(v) => form.set('resolution', v)} hint="Needed before closing." />
                    <TextField label="Post-incident review (link or reference)" optional value={form.values.postmortem} onChange={(v) => form.set('postmortem', v)} maxLength={300} />
                  </>
                )}
                <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={!form.dirty}>Update incident</Button>
              </form>
            )}
          </div>
        )}
      </QueryState>
    </Dialog>
  );
}
