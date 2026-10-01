import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
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
 * offered. What a package includes (its features and limits) is shown as
 * the server holds it; the API has no way to edit those, so this page
 * has none. Changes ask for the password again.
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

export default function Packages() {
  const state = useApi<{ data: (Package & { id?: number })[] }>('/super-admin/packages');
  const [editing, setEditing] = useState<Package | 'new' | null>(null);

  return (
    <AdminPage title="Packages" description="The tiers stores subscribe to, and what each includes." actions={<Button variant="primary" onClick={() => setEditing('new')}>Add package</Button>}>
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
                  <Card key={item.code} title={item.name} description={`Code: ${item.code}`} actions={<Button size="sm" onClick={() => setEditing(item)}>Edit</Button>}>
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
      {editing !== null && <PackageDialog target={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); state.reload(); }} />}
    </AdminPage>
  );
}
