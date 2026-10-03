import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink } from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { CheckboxField, FormError, TextField } from '@/Components/ui/Form';
import Badge from '@/Components/ui/Badge';
import { Card, EmptyPanel, QueryState } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { FEATURE_LABELS, USAGE_LABELS, labelFor } from '@/lib/labels';

/**
 * Module 04 "Package Administration" (/api/v1/super-admin/packages):
 * Basic, Business and Premium are entitlement tiers of one application.
 *
 * The API creates a package and changes its name and whether it is
 * offered. Phase B32 (Module 04 §63): platform staff also change what a
 * package includes — switch a feature on or off, set a limit — with a
 * reason. Only features and limits the platform already knows can be
 * set; nothing is invented here. Every change is in the platform audit
 * log with its before and after (the entitlement history), applies to
 * every store on the package at once, and asks for the password again.
 */
type Entitlement = { key: string; type: string; enabled: boolean | null; limit: number | null; unlimited: boolean; period: string | null };
type Package = { code: string; name: string; is_active: boolean; entitlements?: Entitlement[] };

function PackageDialog({ target, onClose, onDone }: { target: Package | null; onClose: () => void; onDone: () => void }) {
  const form = useForm({ code: target?.code ?? '', name: target?.name ?? '', is_active: target?.is_active ?? true });

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = target === null ? form.values : { name: form.values.name, is_active: form.values.is_active };
    // The update route takes the package's numeric key or code; the list gives the code.
    if ((await form.submit(() => adminFetch(target === null ? '/super-admin/packages' : `/super-admin/packages/${target.code}`, { method: target === null ? 'POST' : 'PUT', body }), 'Package saved.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={target === null ? 'Add package' : `Edit ${target.name}`} description="You will be asked for your password." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        {target === null && <TextField label="Code" value={form.values.code} onChange={(v) => form.set('code', v)} error={form.errors.code} required maxLength={32} hint="Letters, numbers, dashes. Cannot be changed later." data-autofocus />}
        <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} />
        <CheckboxField label="Offered to stores" checked={form.values.is_active} onChange={(v) => form.set('is_active', v)} hint="An inactive package stays with the stores that have it, but is not offered." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save package</Button>
        </div>
      </form>
    </Dialog>
  );
}

type Draft = Record<string, { enabled: boolean; unlimited: boolean; limit: string }>;

function draftOf(entitlements: Entitlement[]): Draft {
  const draft: Draft = {};
  for (const e of entitlements) draft[e.key] = { enabled: e.enabled === true, unlimited: e.unlimited, limit: e.limit === null ? '' : String(e.limit) };

  return draft;
}

/**
 * Every key any package has, so a feature one package lacks can be given
 * to it. Keys come from the server's packages; none is typed in here.
 */
function knownKeys(all: Package[]): Entitlement[] {
  const seen = new Map<string, Entitlement>();
  for (const p of all) for (const e of p.entitlements ?? []) if (!seen.has(e.key)) seen.set(e.key, e);

  return [...seen.values()];
}

function EntitlementsDialog({ target, all, onClose, onDone }: { target: Package; all: Package[]; onClose: () => void; onDone: () => void }) {
  const keys = knownKeys(all);
  const own = new Map((target.entitlements ?? []).map((e) => [e.key, e]));
  const start = draftOf(keys.map((k) => own.get(k.key) ?? { ...k, enabled: false, unlimited: false, limit: null }));
  const [draft, setDraft] = useState<Draft>(start);
  const form = useForm({ reason: '' });
  const set = (key: string, change: Partial<Draft[string]>) => setDraft((current) => ({ ...current, [key]: { ...current[key], ...change } }));

  // Only what was changed is sent (and a key the package does not have yet, once touched).
  const changed = keys.filter((k) => {
    const a = start[k.key];
    const b = draft[k.key];

    return k.type === 'feature' ? a.enabled !== b.enabled : a.unlimited !== b.unlimited || (!b.unlimited && a.limit !== b.limit);
  });

  async function save(event: FormEvent) {
    event.preventDefault();
    if (changed.length === 0) {
      form.setFormError('Nothing is changed.');

      return;
    }
    const problems = changed.filter((k) => k.type !== 'feature' && !draft[k.key].unlimited && !/^\d+$/.test(draft[k.key].limit.trim()));
    if (problems.length > 0) {
      form.setFormError(`Give a whole number for: ${problems.map((k) => labelFor(USAGE_LABELS, k.key)).join(', ')} — or make it unlimited.`);

      return;
    }
    const entitlements = changed.map((k) =>
      k.type === 'feature'
        ? { key: k.key, enabled: draft[k.key].enabled }
        : { key: k.key, unlimited: draft[k.key].unlimited, limit: draft[k.key].unlimited ? null : Number(draft[k.key].limit.trim()) },
    );
    if ((await form.submit(() => adminFetch(`/super-admin/packages/${target.code}/entitlements`, { method: 'PUT', body: { entitlements, reason: form.values.reason } }), 'Package contents saved.')) !== undefined) onDone();
  }

  const features = keys.filter((k) => k.type === 'feature');
  const limits = keys.filter((k) => k.type !== 'feature');

  return (
    <Dialog open wide title={`What ${target.name} includes`} description="Applies at once to every store on this package. Usage already above a lowered limit is kept; the limit applies to new usage. You will be asked for your password." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} errors={form.errors} />
        <fieldset>
          <legend className="text-xs font-semibold uppercase tracking-wide text-slate-500">Limits</legend>
          <div className="mt-2 space-y-3">
            {limits.map((k) => (
              <div key={k.key} className="grid items-end gap-2 sm:grid-cols-[1fr_9rem_auto]">
                <p className="text-sm text-slate-900">{labelFor(USAGE_LABELS, k.key)}{k.period ? <span className="text-slate-500"> (per {k.period})</span> : null}{!own.has(k.key) && <span className="text-slate-500"> — not set on this package</span>}</p>
                <TextField label="Limit" inputMode="numeric" value={draft[k.key].limit} disabled={draft[k.key].unlimited} onChange={(limit) => set(k.key, { limit })} />
                <CheckboxField label="Unlimited" checked={draft[k.key].unlimited} onChange={(unlimited) => set(k.key, { unlimited })} />
              </div>
            ))}
          </div>
        </fieldset>
        <fieldset>
          <legend className="text-xs font-semibold uppercase tracking-wide text-slate-500">Features</legend>
          <div className="mt-2 grid gap-2 sm:grid-cols-2">
            {features.map((k) => (
              <CheckboxField key={k.key} label={labelFor(FEATURE_LABELS, k.key)} hint={own.has(k.key) ? undefined : 'Not set on this package yet.'} checked={draft[k.key].enabled} onChange={(enabled) => set(k.key, { enabled })} />
            ))}
          </div>
        </fieldset>
        <TextField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} required maxLength={500} hint="Kept in the audit log with the change." />
        <div className="flex flex-wrap items-center justify-end gap-2">
          <span className="mr-auto text-sm text-slate-600">{changed.length === 0 ? 'No changes yet.' : `${changed.length} change${changed.length === 1 ? '' : 's'}.`}</span>
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={changed.length === 0 || form.values.reason.trim() === ''}>Save changes</Button>
        </div>
      </form>
    </Dialog>
  );
}

export default function Packages() {
  const state = useApi<{ data: (Package & { id?: number })[] }>('/super-admin/packages');
  const [editing, setEditing] = useState<Package | 'new' | null>(null);
  const [contents, setContents] = useState<Package | null>(null);

  return (
    <AdminPage
      title="Packages"
      description="The tiers stores subscribe to, and what each includes."
      actions={
        <>
          <ButtonLink href="/super-admin/audit-log?action=super_admin.package.">Change history</ButtonLink>
          <Button variant="primary" onClick={() => setEditing('new')}>Add package</Button>
        </>
      }
    >
      <QueryState state={state} lines={6}>
        {({ data }) =>
          data.length === 0 ? (
            <EmptyPanel title="No packages" />
          ) : (
            <div className="grid gap-4 lg:grid-cols-3">
              {data.map((item) => {
                const features = (item.entitlements ?? []).filter((entitlement) => entitlement.type === 'feature');
                const limits = (item.entitlements ?? []).filter((entitlement) => entitlement.type !== 'feature');

                return (
                  <Card key={item.code} title={item.name} description={`Code: ${item.code}`} actions={
                      <div className="flex gap-1">
                        <Button size="sm" onClick={() => setEditing(item)}>Edit<span className="sr-only"> {item.name}</span></Button>
                        <Button size="sm" onClick={() => setContents(item)}>Contents<span className="sr-only"> of {item.name}</span></Button>
                      </div>
                    }>
                    <p className="mb-3"><Badge tone={item.is_active ? 'green' : 'neutral'}>{item.is_active ? 'Offered' : 'Not offered'}</Badge></p>
                    <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Limits</h3>
                    {limits.length === 0 ? (
                      <p className="mb-3 text-sm text-slate-600">No limits.</p>
                    ) : (
                      <ul className="mb-3 text-sm">
                        {limits.map((limit) => (
                          <li key={limit.key} className="flex justify-between gap-2 border-b border-slate-100 py-1">
                            <span>{labelFor(USAGE_LABELS, limit.key)}</span>
                            <span className="font-medium">{limit.unlimited ? 'Unlimited' : limit.limit}</span>
                          </li>
                        ))}
                      </ul>
                    )}
                    <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Features</h3>
                    <ul className="text-sm">
                      {features.map((feature) => (
                        <li key={feature.key} className="flex justify-between gap-2 border-b border-slate-100 py-1">
                          <span>{labelFor(FEATURE_LABELS, feature.key)}</span>
                          <span className={feature.enabled ? 'font-medium text-green-800' : 'text-slate-500'}>{feature.enabled ? 'Yes' : 'No'}</span>
                        </li>
                      ))}
                    </ul>
                  </Card>
                );
              })}
            </div>
          )
        }
      </QueryState>
      {contents !== null && state.data && <EntitlementsDialog target={contents} all={state.data.data} onClose={() => setContents(null)} onDone={() => { setContents(null); state.reload(); }} />}
      {editing !== null && <PackageDialog target={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); state.reload(); }} />}
    </AdminPage>
  );
}
