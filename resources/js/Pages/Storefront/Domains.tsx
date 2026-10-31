import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, TextField } from '@/Components/ui/Form';
import Badge, { type Tone } from '@/Components/ui/Badge';
import Icon from '@/Components/ui/Icon';
import { Card, EmptyPanel, ErrorPanel, PackageNotice, Skeleton } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { dateTimeOrDash } from '@/lib/datetime';
import { toast } from '@/Components/ui/toast';

/**
 * Module 19 "Domain Management"; Phase S2 (owner decision 2026-10-31): a
 * store is public only on its own domain.
 *
 * The way to live, one step at a time: ownership (TXT record), address
 * (A records to the shop), certificate (made by itself). Every status is
 * the server's; this page marks nothing done. The server also checks by
 * itself every few minutes; "Check now" only asks it to look at once.
 */
type DnsRecord = { type: string; name: string; value: string; purpose: 'ownership' | 'address' };
export type DomainRow = {
  id: string; hostname: string; domain_type: string; status: string; is_primary: boolean; ssl_status: string; verified_at: string | null;
  dns_status: 'pointing' | 'elsewhere' | 'missing' | null; dns_checked_at: string | null; ssl_checked_at: string | null; records: DnsRecord[];
};
type StepState = 'done' | 'now' | 'waiting';

/** Where a domain stands: each step from the server's own fields. */
export function stepsOf(d: Pick<DomainRow, 'status' | 'dns_status' | 'ssl_status'>): { ownership: StepState; address: StepState; certificate: StepState } {
  const owned = d.status === 'verified' || d.status === 'active';
  const pointing = d.dns_status === 'pointing';
  const secured = d.ssl_status === 'active';

  return {
    ownership: owned ? 'done' : 'now',
    address: pointing ? 'done' : owned ? 'now' : 'waiting',
    certificate: secured ? 'done' : owned && pointing ? 'now' : 'waiting',
  };
}

function Step({ number, state, title, children }: { number: number; state: StepState; title: string; children?: React.ReactNode }) {
  const mark = state === 'done' ? 'bg-emerald-600 text-white' : state === 'now' ? 'bg-brand-600 text-white' : 'bg-slate-200 text-slate-600';

  return (
    <li className="flex gap-3">
      <span aria-hidden="true" className={`mt-0.5 flex h-7 w-7 flex-none items-center justify-center rounded-full text-sm font-semibold ${mark}`}>
        {state === 'done' ? <Icon name="check" size={15} /> : number}
      </span>
      <div className="min-w-0 flex-1">
        <p className={`font-medium ${state === 'waiting' ? 'text-slate-500' : 'text-slate-900'}`}>
          {title} <span className="sr-only">({state === 'done' ? 'done' : state === 'now' ? 'to do now' : 'comes later'})</span>
        </p>
        {state === 'now' && <div className="mt-2 space-y-3 text-sm text-slate-700">{children}</div>}
      </div>
    </li>
  );
}

function Records({ records }: { records: DnsRecord[] }) {
  function copy(value: string) {
    void navigator.clipboard?.writeText(value).then(() => toast.success('Copied.'));
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200">
      <table className="w-full text-left text-sm">
        <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
          <tr><th className="px-3 py-2 font-medium">Type</th><th className="px-3 py-2 font-medium">Name / host</th><th className="px-3 py-2 font-medium">Value</th></tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {records.map((r) => (
            <tr key={`${r.type}-${r.name}`}>
              <td className="px-3 py-2 font-mono">{r.type}</td>
              <td className="px-3 py-2"><span className="break-all font-mono">{r.name}</span> <Button size="sm" variant="ghost" onClick={() => copy(r.name)}>Copy<span className="sr-only"> name {r.name}</span></Button></td>
              <td className="px-3 py-2"><span className="break-all font-mono">{r.value}</span> <Button size="sm" variant="ghost" onClick={() => copy(r.value)}>Copy<span className="sr-only"> value {r.value}</span></Button></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function DomainCard({ domain, canManage, shopIp, onChanged, onRemove }: { domain: DomainRow; canManage: boolean; shopIp: string | null; onChanged: () => void; onRemove: () => void }) {
  const { busy, run } = useAction();
  const steps = stepsOf(domain);
  const live = domain.status === 'active' && domain.ssl_status === 'active';
  const stopped = domain.status === 'suspended' || domain.status === 'disabled';
  const [label, tone]: [string, Tone] = stopped ? ['Stopped', 'red'] : live ? ['Live', 'green'] : ['Being connected', 'amber'];
  const ownership = domain.records.filter((r) => r.purpose === 'ownership');
  const address = domain.records.filter((r) => r.purpose === 'address');

  return (
    <Card
      title={domain.hostname}
      icon="globe"
      description={domain.is_primary ? 'Your store’s address' : 'Your own domain'}
      actions={<Badge tone={tone}>{label}</Badge>}
    >
      {stopped ? (
        <p className="text-sm text-slate-700">This domain was stopped by Umar Techy. Contact support.</p>
      ) : (
        <ol className="space-y-4">
          <Step number={1} state={steps.ownership} title="Prove the domain is yours">
            <p>At the provider where you manage this domain, add this record:</p>
            <Records records={ownership} />
          </Step>
          <Step number={2} state={steps.address} title="Point the domain to your store">
            {shopIp === null ? (
              <p className="text-amber-800">The shop’s address is not set up on the server yet. Umar Techy will tell you when this step is ready.</p>
            ) : (
              <>
                <p>Add these two records, and remove any other A record for the same names:</p>
                <Records records={address} />
                {domain.dns_status === 'elsewhere' && <p role="status" className="font-medium text-amber-800">The domain points to another address right now.</p>}
                {domain.dns_status === 'missing' && <p role="status" className="font-medium text-amber-800">No address found for the domain yet.</p>}
              </>
            )}
          </Step>
          <Step number={3} state={steps.certificate} title="Secure connection (HTTPS)">
            <p>Nothing for you to do: the certificate is made by itself, usually within minutes.</p>
          </Step>
        </ol>
      )}
      {!live && !stopped && <p className="mt-4 text-xs text-slate-500">DNS changes can take from minutes to a day. We check by ourselves every few minutes. Last checked: {dateTimeOrDash(domain.dns_checked_at)}.</p>}
      {canManage && (
        <div className="mt-5 flex flex-wrap justify-end gap-2">
          <Button size="sm" variant="ghost" onClick={onRemove}>Remove</Button>
          {!stopped && !live && (
            <Button size="sm" variant="primary" busy={busy === 'check'} busyLabel="Checking…" onClick={() => run('check', () => adminFetch(`/domains/${domain.id}/check`, { method: 'POST' })).then((r) => { if (r !== undefined) onChanged(); })}>Check now</Button>
          )}
          {live && !domain.is_primary && (
            <Button size="sm" busy={busy === 'primary'} onClick={() => run('primary', () => adminFetch(`/domains/${domain.id}/primary`, { method: 'POST' }), { success: 'This is now your store’s address.' }).then((r) => { if (r !== undefined) onChanged(); })}>Make it the store’s address</Button>
          )}
        </div>
      )}
    </Card>
  );
}

export default function Domains() {
  const access = useAccess();
  const canManage = access.can('domains.manage');
  const included = access.feature('domains.custom_domain') !== false;
  const list = useApi<{ data: DomainRow[]; meta: { shop_ipv4: string | null } }>('/domains');
  const [adding, setAdding] = useState(false);
  const [removing, setRemoving] = useState<DomainRow | null>(null);
  const form = useForm({ hostname: '' });
  const { busy, run } = useAction();
  const rows = list.data?.data;
  const addButton = canManage && <Button variant="primary" disabled={!included} title={!included ? 'Not included in your package' : undefined} onClick={() => { form.reset({ hostname: '' }); setAdding(true); }}>Add your domain</Button>;

  async function add(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch('/domains', { method: 'POST', body: form.values }), 'Domain added. Follow the steps to connect it.')) !== undefined) {
      setAdding(false);
      list.reload();
    }
  }

  return (
    <AdminPage title="Domains" description="Your store opens on your own domain. Connect it here, one step at a time." actions={addButton}>
      {!included && (
        <div className="mb-4">
          <PackageNotice title="Your own domain is not included in your package" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>Contact Umar Techy.</PackageNotice>
        </div>
      )}

      {list.error ? (
        <ErrorPanel message={list.error} onRetry={list.reload} />
      ) : rows === undefined ? (
        <Skeleton lines={6} />
      ) : rows.length === 0 ? (
        <EmptyPanel icon="globe" title="No domain yet" description="Your store goes public on your own domain, such as mystore.pk. Until then only your team can see it." action={addButton || undefined} />
      ) : (
        <div className="grid gap-6 xl:grid-cols-2">
          {rows.map((domain) => <DomainCard key={domain.id} domain={domain} canManage={canManage} shopIp={list.data?.meta.shop_ipv4 ?? null} onChanged={list.reload} onRemove={() => setRemoving(domain)} />)}
        </div>
      )}

      <Dialog open={adding} title="Add your domain" description="Enter a domain you own, such as mystore.pk. You do not need to write www or https://." onClose={() => setAdding(false)} busy={form.busy}>
        <form onSubmit={add} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <TextField label="Domain" value={form.values.hostname} onChange={(v) => form.set('hostname', v)} error={form.errors.hostname} required maxLength={253} placeholder="mystore.pk" autoCapitalize="none" data-autofocus />
          <div className="flex justify-end gap-2">
            <Button onClick={() => setAdding(false)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Adding…">Add domain</Button>
          </div>
        </form>
      </Dialog>

      <ConfirmDialog
        open={removing !== null}
        title="Remove this domain?"
        confirmLabel="Remove domain"
        variant="danger"
        busy={busy === 'remove'}
        onClose={() => setRemoving(null)}
        onConfirm={() => {
          if (removing === null) return;
          void run('remove', () => adminFetch(`/domains/${removing.id}`, { method: 'DELETE' }), { success: 'Domain removed.' }).then((result) => {
            setRemoving(null);
            if (result !== undefined) list.reload();
          });
        }}
      >
        <p>Your store stops answering on <strong>{removing?.hostname}</strong>, and links to that address stop working. To use it again you add and verify it again.</p>
      </ConfirmDialog>
    </AdminPage>
  );
}
