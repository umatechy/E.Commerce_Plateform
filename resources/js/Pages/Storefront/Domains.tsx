import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, TextField } from '@/Components/ui/Form';
import Badge, { StatusBadge, humanize } from '@/Components/ui/Badge';
import { Details, EmptyPanel, PackageNotice } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { dateTimeOrDash } from '@/lib/datetime';

/**
 * Module 19 "Domain Management" (/api/v1/domains).
 *
 * Every status on this page is the server's. A domain is shown as
 * verified only when the server has found the DNS record itself; this
 * page never marks anything verified. "Check now" asks the server to
 * look; if the record is not visible yet the server says so (DNS changes
 * can take time). SSL status is shown as the server reports it; the
 * platform does not issue certificates from this page.
 */
type Domain = { id: string; hostname: string; domain_type: string; status: string; is_primary: boolean; ssl_status: string; verified_at: string | null };
type Instructions = { record_type: string; record_name: string; record_value: string };

const NEXT_STEP: Record<string, string> = {
  pending: 'Get the DNS record and add it at your domain provider.',
  verification_required: 'Add the DNS record at your domain provider, then check.',
  verified: 'Verified. It can be made your main address.',
  active: 'In use.',
  suspended: 'Suspended by the platform. Contact support.',
  disabled: 'Disabled.',
  removed: 'Removed.',
};

export default function Domains() {
  const access = useAccess();
  const canManage = access.can('domains.manage');
  const included = access.feature('domains.custom_domain') !== false;
  const list = useApi<{ data: Domain[] }>('/domains');
  const [adding, setAdding] = useState(false);
  const [instructions, setInstructions] = useState<{ domain: Domain; record: Instructions } | null>(null);
  const [confirm, setConfirm] = useState<{ domain: Domain; action: 'remove' | 'restart' } | null>(null);
  const form = useForm({ hostname: '' });
  const { busy, run } = useAction();

  async function add(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch('/domains', { method: 'POST', body: form.values }), 'Domain added. Next: verify that it is yours.')) !== undefined) {
      setAdding(false);
      list.reload();
    }
  }

  function startVerification(domain: Domain) {
    return run(`dns:${domain.id}`, () => adminFetch<{ data: Domain; instructions: Instructions }>(`/domains/${domain.id}/verification`, { method: 'POST' })).then((result) => {
      if (result) {
        setInstructions({ domain: result.data, record: result.instructions });
        list.reload();
      }

      return result;
    });
  }

  function simple(domain: Domain, action: 'verify' | 'primary', success: string) {
    return run(`${action}:${domain.id}`, () => adminFetch(`/domains/${domain.id}/${action}`, { method: 'POST' }), { success }).then((result) => {
      if (result !== undefined) list.reload();
    });
  }

  const columns: Column<Domain>[] = [
    {
      key: 'hostname',
      header: 'Address',
      render: (domain) => (
        <div>
          <span className="break-all font-medium">{domain.hostname}</span> {domain.is_primary && <Badge tone="blue">Main address</Badge>}
          <p className="text-xs text-slate-500">{domain.domain_type === 'platform_subdomain' ? 'Your platform address (always available)' : 'Your own domain'}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (domain) => <StatusBadge status={domain.status} /> },
    { key: 'ssl', header: 'SSL', render: (domain) => <StatusBadge status={domain.ssl_status === 'none' ? 'not_set_up' : domain.ssl_status} label={domain.ssl_status === 'none' ? 'Not set up' : undefined} /> },
    { key: 'verified', header: 'Verified', render: (domain) => dateTimeOrDash(domain.verified_at) },
    { key: 'next', header: 'Next step', render: (domain) => <span className="text-slate-700">{domain.domain_type === 'platform_subdomain' ? '—' : (NEXT_STEP[domain.status] ?? humanize(domain.status))}</span> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (domain) => {
        if (!canManage || domain.domain_type === 'platform_subdomain') {
          return canManage && !domain.is_primary && domain.status === 'active' ? <Button size="sm" variant="ghost" busy={busy === `primary:${domain.id}`} onClick={() => simple(domain, 'primary', 'Main address changed.')}>Make main address</Button> : null;
        }

        return (
          <span className="flex flex-wrap justify-end gap-1">
            {domain.status === 'pending' && <Button size="sm" variant="ghost" busy={busy === `dns:${domain.id}`} onClick={() => startVerification(domain)}>Get DNS record</Button>}
            {domain.status === 'verification_required' && (
              <>
                <Button size="sm" variant="ghost" busy={busy === `verify:${domain.id}`} busyLabel="Checking…" onClick={() => simple(domain, 'verify', 'Domain verified.')}>Check now</Button>
                <Button size="sm" variant="ghost" onClick={() => setConfirm({ domain, action: 'restart' })}>New DNS record</Button>
              </>
            )}
            {['verified', 'active'].includes(domain.status) && !domain.is_primary && <Button size="sm" variant="ghost" busy={busy === `primary:${domain.id}`} onClick={() => simple(domain, 'primary', 'Main address changed.')}>Make main address</Button>}
            {domain.status !== 'removed' && <Button size="sm" variant="ghost" onClick={() => setConfirm({ domain, action: 'remove' })}>Remove</Button>}
          </span>
        );
      },
    },
  ];

  return (
    <AdminPage
      title="Domains"
      description="The addresses your store answers on."
      actions={canManage && <Button variant="primary" disabled={!included} title={!included ? 'Not included in your package' : undefined} onClick={() => { form.reset({ hostname: '' }); setAdding(true); }}>Add your domain</Button>}
    >
      {!included && (
        <div className="mb-4">
          <PackageNotice title="Your own domain is not included in your package" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
            Your store stays reachable on its platform address.
          </PackageNotice>
        </div>
      )}

      <DataTable caption="Domains" columns={columns} rows={list.data?.data ?? null} rowKey={(domain) => domain.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No domains" />} />

      <Dialog open={adding} title="Add your domain" description="Enter a domain you own, such as shop.example.com. After adding it you prove it is yours with a DNS record." onClose={() => setAdding(false)} busy={form.busy}>
        <form onSubmit={add} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <TextField label="Domain" value={form.values.hostname} onChange={(v) => form.set('hostname', v)} error={form.errors.hostname} required maxLength={253} placeholder="shop.example.com" autoCapitalize="none" data-autofocus />
          <div className="flex justify-end gap-2">
            <Button onClick={() => setAdding(false)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Adding…">Add domain</Button>
          </div>
        </form>
      </Dialog>

      <Dialog
        open={instructions !== null}
        wide
        title="Add this DNS record"
        description={instructions ? `At the provider where you manage ${instructions.domain.hostname}, add the record below. Then come back and press "Check now".` : undefined}
        onClose={() => setInstructions(null)}
        footer={<Button variant="primary" onClick={() => setInstructions(null)}>Done</Button>}
      >
        {instructions && (
          <div className="space-y-3">
            <Details
              items={[
                { label: 'Type', value: <span className="font-mono">{instructions.record.record_type}</span> },
                { label: 'Name / host', value: <span className="break-all font-mono">{instructions.record.record_name}</span> },
                { label: 'Value', value: <span className="break-all font-mono">{instructions.record.record_value}</span> },
              ]}
            />
            <p className="text-sm text-slate-700">Copy these now: this record is shown once. DNS changes can take from minutes to a day to be visible. The record is valid for 7 days.</p>
          </div>
        )}
      </Dialog>

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.action === 'remove' ? 'Remove this domain?' : 'Issue a new DNS record?'}
        confirmLabel={confirm?.action === 'remove' ? 'Remove domain' : 'Issue new record'}
        variant={confirm?.action === 'remove' ? 'danger' : 'primary'}
        busy={busy !== null}
        onClose={() => setConfirm(null)}
        onConfirm={() => {
          if (confirm === null) return;
          if (confirm.action === 'restart') {
            void startVerification(confirm.domain).then((result) => result && setConfirm(null));

            return;
          }
          void run('remove', () => adminFetch(`/domains/${confirm.domain.id}`, { method: 'DELETE' }), { success: 'Domain removed.' }).then((result) => {
            if (result !== undefined) {
              setConfirm(null);
              list.reload();
            }
          });
        }}
      >
        {confirm?.action === 'remove' ? (
          <p>
            Your store stops answering on <strong>{confirm.domain.hostname}</strong>. Links to that address stop working. To use it again you add and verify it again.
          </p>
        ) : (
          <p>
            The record you were given before for <strong>{confirm?.domain.hostname}</strong> stops being valid. You will have to replace it at your domain provider with the new one.
          </p>
        )}
      </ConfirmDialog>
    </AdminPage>
  );
}
