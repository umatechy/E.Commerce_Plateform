import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink } from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import Badge, { StatusBadge } from '@/Components/ui/Badge';
import { Card, Details, QueryState } from '@/Components/ui/Page';
import HealthCheckList, { type HealthCheck, type HealthStatus } from '@/Components/HealthCheckList';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';

/**
 * Module 30: one store, seen by platform staff
 * (/api/v1/super-admin/stores/{store}...). Every request to these routes
 * is a cross-tenant access: the server requires platform staff with
 * two-step sign-in and records each one in the audit trail. This page
 * acts on the store named in its address only.
 */
type StoreDetail = { store: { id: string; name: string; slug: string; status: string }; subscription_status: string | null; package_code: string | null; primary_domain: string | null; low_stock_products: number };
type Health = { status: HealthStatus; checks: HealthCheck[] };
type Domain = { id: string; hostname: string; domain_type: string; status: string; is_primary: boolean; ssl_status: string };
type Package = { code: string; name: string; is_active: boolean };

function SubscriptionDialog({ storeId, action, packages, current, onClose, onDone }: { storeId: string; action: 'change-package' | 'suspend' | 'reactivate'; packages: Package[]; current: string | null; onClose: () => void; onDone: () => void }) {
  const form = useForm({ package_code: current ?? '', reason: '' });
  const titles = { 'change-package': 'Change package', suspend: 'Suspend subscription', reactivate: 'Reactivate subscription' };

  async function save(event: FormEvent) {
    event.preventDefault();
    if (form.values.reason.trim() === '') {
      form.setFormError('Give the reason. It is recorded in the audit trail.');

      return;
    }
    const body = action === 'change-package' ? { package_code: form.values.package_code, reason: form.values.reason } : { reason: form.values.reason };
    const saved = await form.submit(() => adminFetch<{ data?: { over_limit?: unknown } }>(`/super-admin/stores/${storeId}/subscription/${action}`, { method: 'POST', body }), `${titles[action]}: done.`);
    if (saved !== undefined) onDone();
  }

  return (
    <Dialog
      open
      title={titles[action]}
      description={action === 'suspend' ? 'The store loses access to its admin features and its storefront closes until it is reactivated.' : action === 'change-package' ? 'The store gets the features and limits of the new package at once. Usage above the new limits is reported, not deleted.' : 'The store gets access again.'}
      onClose={onClose}
      busy={form.busy}
    >
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        {action === 'change-package' && (
          <SelectField label="Package" value={form.values.package_code} onChange={(v) => form.set('package_code', v)} error={form.errors.package_code} placeholder="Choose a package" options={packages.map((item) => ({ value: item.code, label: item.is_active ? item.name : `${item.name} (inactive)` }))} required />
        )}
        <TextField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={500} required data-autofocus />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant={action === 'suspend' ? 'danger' : 'primary'} busy={form.busy} busyLabel="Saving…">{titles[action]}</Button>
        </div>
      </form>
    </Dialog>
  );
}

export default function Store({ storeId }: { storeId: string }) {
  const base = `/super-admin/stores/${storeId}`;
  const detail = useApi<{ data: StoreDetail }>(base);
  const health = useApi<{ data: Health }>(`${base}/health`);
  const domains = useApi<{ data: Domain[] }>(`${base}/domains`);
  const packages = useApi<{ data: Package[] }>('/super-admin/packages');
  const [dialog, setDialog] = useState<'change-package' | 'suspend' | 'reactivate' | null>(null);
  const { busy, run } = useAction();
  const store = detail.data?.data;

  const domainColumns: Column<Domain>[] = [
    { key: 'host', header: 'Address', render: (domain) => <span className="break-all font-medium">{domain.hostname} {domain.is_primary && <Badge tone="blue">Main</Badge>}</span> },
    { key: 'status', header: 'Status', priority: true, render: (domain) => <StatusBadge status={domain.status} /> },
    { key: 'ssl', header: 'SSL', render: (domain) => <StatusBadge status={domain.ssl_status} /> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (domain) => {
        const action = domain.status === 'suspended' ? 'reactivate' : ['active', 'verified'].includes(domain.status) ? 'suspend' : null;

        return (
          action && (
            <Button
              size="sm"
              variant="ghost"
              busy={busy === `${action}:${domain.id}`}
              onClick={() => run(`${action}:${domain.id}`, () => adminFetch(`${base}/domains/${domain.id}/${action}`, { method: 'POST' }), { success: action === 'suspend' ? 'Domain suspended.' : 'Domain reactivated.' }).then((result) => result !== undefined && domains.reload())}
            >
              {action === 'suspend' ? 'Suspend' : 'Reactivate'}
            </Button>
          )
        );
      },
    },
  ];

  return (
    <AdminPage title={store?.store.name ?? 'Store'} trail={[{ label: store?.store.name ?? 'Store' }]} description="Seen as platform staff. Each visit here is recorded in the audit trail." actions={<ButtonLink href="/super-admin/stores">All stores</ButtonLink>}>
      <div className="space-y-4">
        <QueryState state={detail} lines={4}>
          {({ data }) => (
            <Card
              title="Store and subscription"
              actions={
                <>
                  <Button size="sm" onClick={() => setDialog('change-package')}>Change package</Button>
                  {data.subscription_status === 'suspended' ? <Button size="sm" variant="primary" onClick={() => setDialog('reactivate')}>Reactivate</Button> : <Button size="sm" variant="danger" onClick={() => setDialog('suspend')}>Suspend</Button>}
                </>
              }
            >
              <Details
                items={[
                  { label: 'Store status', value: <StatusBadge status={data.store.status} /> },
                  { label: 'Address', value: <span className="font-mono">{data.store.slug}</span> },
                  { label: 'Subscription', value: data.subscription_status ? <StatusBadge status={data.subscription_status} /> : 'None' },
                  { label: 'Package', value: data.package_code ?? '—' },
                  { label: 'Main domain', value: data.primary_domain ?? '—' },
                  { label: 'Stock records low on stock', value: data.low_stock_products },
                ]}
              />
            </Card>
          )}
        </QueryState>

        <Card title="Health">
          <QueryState state={health} lines={3}>{({ data }) => <HealthCheckList checks={data.checks} />}</QueryState>
        </Card>

        <Card title="Domains">
          <DataTable caption="Domains of this store" columns={domainColumns} rows={domains.data?.data ?? null} rowKey={(domain) => domain.id} loading={domains.loading} error={domains.error} onRetry={domains.reload} empty={<p className="text-sm text-slate-600">No domains.</p>} />
        </Card>
      </div>

      {dialog && (
        <SubscriptionDialog
          storeId={storeId}
          action={dialog}
          packages={packages.data?.data ?? []}
          current={store?.package_code ?? null}
          onClose={() => setDialog(null)}
          onDone={() => {
            setDialog(null);
            detail.reload();
            health.reload();
          }}
        />
      )}
    </AdminPage>
  );
}
