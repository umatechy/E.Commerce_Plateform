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
import { formatDate } from '@/lib/datetime';
import { STAGE_LABEL, STAGE_TONE } from '@/lib/stores';

/** Phase B44: a new owner invitation (the old link stops working). */
function OwnerInvitationDialog({ storeId, current, onClose, onDone }: { storeId: string; current: string; onClose: () => void; onDone: () => void }) {
  const form = useForm({ owner_email: current });

  async function save(event: FormEvent) {
    event.preventDefault();
    const sent = await form.submit(() => adminFetch(`/super-admin/stores/${storeId}/owner-invitation`, { method: 'POST', body: form.values }), 'Invitation sent.');
    if (sent !== undefined) onDone();
  }

  return (
    <Dialog open title="Send the owner invitation again" description="A new email goes out; the earlier link stops working." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Owner’s email" type="email" value={form.values.owner_email} onChange={(v) => form.set('owner_email', v)} error={form.errors.owner_email} required data-autofocus />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Sending…">Send invitation</Button>
        </div>
      </form>
    </Dialog>
  );
}

/**
 * Module 30: one store, seen by platform staff
 * (/api/v1/super-admin/stores/{store}...). Every request to these routes
 * is a cross-tenant access: the server requires platform staff with
 * two-step sign-in and records each one in the audit trail. This page
 * acts on the store named in its address only.
 */
type StoreDetail = {
  store: { id: string; name: string; slug: string; status: string };
  subscription_status: string | null;
  package_code: string | null;
  primary_domain: string | null;
  low_stock_products: number;
  // Phase B44 (Module 03 §7, §24, §38).
  stage?: string;
  business_category?: string | null;
  created_via?: string;
  activated_at?: string | null;
  owner_invitation?: { email: string; expires_at: string } | null;
  setup?: { progress: { done: number; total: number; percent: number }; blocking: string[]; launched: boolean };
  starter_templates?: { key: string; version: number; applied_at: string }[];
};
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
  const [inviting, setInviting] = useState(false);
  const categories = useApi<{ data: { value: string; label: string }[] }>('/super-admin/business-categories');
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
                  { label: 'Stage', value: <Badge tone={STAGE_TONE[data.stage ?? ''] ?? 'neutral'}>{STAGE_LABEL[data.stage ?? ''] ?? data.store.status}</Badge> },
                  { label: 'Sells', value: (categories.data?.data ?? []).find((c) => c.value === data.business_category)?.label ?? data.business_category ?? '—' },
                  { label: 'Created by', value: data.created_via === 'platform' ? 'Umar Techy team' : 'Customer' },
                  { label: 'Live since', value: data.activated_at ? formatDate(data.activated_at, 'UTC') : 'Not launched' },
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

        <QueryState state={detail} lines={2}>
          {({ data }) => (
            <Card
              title="Owner and setup"
              actions={data.stage === 'awaiting_owner' && <Button size="sm" onClick={() => setInviting(true)}>{data.owner_invitation ? 'Send again' : 'Invite the owner'}</Button>}
            >
              {data.stage === 'awaiting_owner' ? (
                data.owner_invitation ? (
                  <p className="text-sm">Invitation sent to <strong className="break-all">{data.owner_invitation.email}</strong>; the link works until {formatDate(data.owner_invitation.expires_at, 'UTC')}.</p>
                ) : (
                  <p className="text-sm text-amber-800">No owner and no open invitation. Invite the owner so someone can sign in to this store.</p>
                )
              ) : (
                <p className="text-sm text-slate-700">The store has its owner.</p>
              )}
              {data.setup && (
                <div className="mt-3">
                  <p className="text-sm">
                    Setup: <strong>{data.setup.progress.percent}%</strong> ({data.setup.progress.done} of {data.setup.progress.total} steps)
                    {!data.setup.launched && data.setup.blocking.length > 0 && <span className="text-amber-800"> — still needed to launch: {data.setup.blocking.join(', ').replaceAll('_', ' ')}</span>}
                  </p>
                </div>
              )}
              {/* Phase B45: which starter template the store began from. */}
              <p className="mt-2 text-sm text-slate-700">
                Starter template:{' '}
                {data.starter_templates && data.starter_templates.length > 0
                  ? data.starter_templates.map((t) => `${(categories.data?.data ?? []).find((c) => c.value === t.key)?.label ?? t.key} (${formatDate(t.applied_at, 'UTC')})`).join(', ')
                  : 'none — started empty'}
              </p>
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

      {inviting && (
        <OwnerInvitationDialog
          storeId={storeId}
          current={store?.owner_invitation?.email ?? ''}
          onClose={() => setInviting(false)}
          onDone={() => {
            setInviting(false);
            detail.reload();
          }}
        />
      )}
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
