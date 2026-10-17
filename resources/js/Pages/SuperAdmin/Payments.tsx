import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, TextAreaField } from '@/Components/ui/Form';
import { SearchField, FilterBar } from '@/Components/ui/Filters';
import Badge, { StatusBadge, humanize } from '@/Components/ui/Badge';
import { Card, EmptyPanel, QueryState, Tabs } from '@/Components/ui/Page';
import AccountDialog from '@/Components/Payments/AccountDialog';
import { CardIcon } from '@/Components/Payments/icons';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { useUrlState } from '@/lib/useUrlState';
import { money } from '@/lib/money';
import { formatDateTime } from '@/lib/datetime';
import { RECONCILIATION_LABELS, type ProviderInfo, type StaffAttempt } from '@/lib/payments';

/**
 * Phase B48 — Module 30 §26–27, Module 12 §66, §86, §89, §93: payment
 * providers across the platform.
 *
 * - Which providers may take real money. A provider opens only after its
 *   integration has been checked in that provider's sandbox; closing one
 *   switches every store's live account off at once.
 * - Umar Techy's own merchant accounts, which stores pay their invoices into.
 * - Health of the last 24 hours, a lookup by reference, and every store's
 *   open findings (read-only: stores resolve their own).
 *
 * No credential is ever shown. Changes ask for the password again.
 */
type ProviderRow = ProviderInfo & {
  stores: { sandbox: number; production: number; taking_payments: number };
  last_24h: { succeeded: number; failed: number; expired: number; pending: number; unknown: number };
};
type Overview = { providers: ProviderRow[]; simulator_enabled: boolean; open_findings: number; card: { available: boolean } };

const CAPABILITIES: { key: keyof ProviderInfo['capabilities']; label: string }[] = [
  { key: 'wallet_payment', label: 'Wallet payments' },
  { key: 'status_inquiry', label: 'Status inquiry' },
  { key: 'refund', label: 'Refund by API' },
  { key: 'notifications', label: 'Notifications' },
  { key: 'signed_notifications', label: 'Signed notifications' },
  { key: 'card_payment', label: 'Cards' },
];

export default function Payments() {
  const [filters, setFilters] = useUrlState({ tab: 'providers', q: '', page: '1' });
  const state = useApi<{ data: Overview }>('/super-admin/payment-providers');

  return (
    <AdminPage title="Payment providers" description="JazzCash and Easypaisa on the platform: whether they take real money, Umar Techy’s own accounts for invoices, health, lookups and findings.">
      <Tabs
        label="Payment provider views"
        active={filters.tab}
        onChange={(tab) => setFilters({ tab, page: '1' })}
        tabs={[
          { id: 'providers', label: 'Providers' },
          { id: 'lookup', label: 'Look up a payment' },
          { id: 'findings', label: state.data && state.data.data.open_findings > 0 ? `Findings (${state.data.data.open_findings})` : 'Findings' },
        ]}
      />
      <div className="mt-4">
        {filters.tab === 'providers' && <Providers state={state} />}
        {filters.tab === 'lookup' && <Lookup q={filters.q} page={filters.page} onQuery={(q) => setFilters({ q, page: '1' })} onPage={(page) => setFilters({ page: String(page) })} />}
        {filters.tab === 'findings' && <Findings />}
      </div>
    </AdminPage>
  );
}

function Providers({ state }: { state: ReturnType<typeof useApi<{ data: Overview }>> }) {
  const [live, setLive] = useState<{ row: ProviderRow; allowed: boolean } | null>(null);
  const [editing, setEditing] = useState<ProviderRow | null>(null);
  const [removing, setRemoving] = useState<ProviderRow | null>(null);
  const { busy, run } = useAction();

  return (
    <QueryState state={state} lines={6}>
      {({ data }) => (
        <div className="space-y-6">
          {data.simulator_enabled && (
            <p role="note" className="rounded-md border border-brand-200 bg-brand-50 p-3 text-sm text-brand-900">
              This system has the Test wallet: a provider that moves no money, for trying the payment flow. It never exists in production.
            </p>
          )}
          <div className="grid gap-4 lg:grid-cols-2">
            {data.providers.map((row) => {
              const real = row.provider !== 'simulator';
              const account = row.account;

              return (
                <section key={row.provider} aria-labelledby={`p-${row.provider}`} className="rounded-lg border border-slate-200 bg-white p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <h2 id={`p-${row.provider}`} className="text-base font-semibold text-slate-900">{row.label}</h2>
                      {row.capabilities.note && <p className="mt-0.5 max-w-md text-xs text-slate-500">{row.capabilities.note}</p>}
                    </div>
                    {real && (row.live_allowed ? <Badge tone="green">Open for real payments</Badge> : <Badge tone="amber">Sandbox only</Badge>)}
                  </div>

                  <ul aria-label={`${row.label} capabilities`} className="mt-3 flex flex-wrap gap-1.5">
                    {CAPABILITIES.map((c) => (
                      <li key={c.key} className={`rounded-full px-2 py-0.5 text-xs ${row.capabilities[c.key] ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-500 line-through'}`}>
                        {c.label}<span className="sr-only">{row.capabilities[c.key] ? ': yes' : ': no'}</span>
                      </li>
                    ))}
                    <li className={`rounded-full px-2 py-0.5 text-xs ${row.capabilities.verified ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'}`}>
                      {row.capabilities.verified ? 'Checked in sandbox' : 'Not yet checked in sandbox'}
                    </li>
                  </ul>

                  <dl className="mt-4 grid grid-cols-3 gap-2 text-center text-sm">
                    <div className="rounded-md bg-slate-50 p-2"><dt className="text-xs text-slate-500">Stores, sandbox</dt><dd className="text-lg font-semibold">{row.stores.sandbox}</dd></div>
                    <div className="rounded-md bg-slate-50 p-2"><dt className="text-xs text-slate-500">Stores, live</dt><dd className="text-lg font-semibold">{row.stores.production}</dd></div>
                    <div className="rounded-md bg-slate-50 p-2"><dt className="text-xs text-slate-500">At checkout</dt><dd className="text-lg font-semibold">{row.stores.taking_payments}</dd></div>
                  </dl>
                  <p className="mt-3 text-xs text-slate-600">
                    Last 24 hours: <strong>{row.last_24h.succeeded}</strong> paid · <strong>{row.last_24h.failed}</strong> not paid · <strong>{row.last_24h.expired}</strong> ran out of time · <strong>{row.last_24h.pending + row.last_24h.unknown}</strong> waiting
                    {row.last_24h.unknown > 0 && <span className="text-amber-800"> ({row.last_24h.unknown} without an answer)</span>}
                  </p>

                  {real && (
                    <div className="mt-3">
                      <Button size="sm" variant={row.live_allowed ? 'danger' : 'primary'} onClick={() => setLive({ row, allowed: !row.live_allowed })}>
                        {row.live_allowed ? 'Close for real payments' : 'Open for real payments'}
                      </Button>
                    </div>
                  )}

                  <div className="mt-4 border-t border-slate-100 pt-3">
                    <h3 className="text-sm font-semibold text-slate-900">Umar Techy’s account for invoices</h3>
                    {account ? (
                      <p className="mt-1 text-sm text-slate-700">
                        {account.environment === 'production' ? 'Production' : 'Sandbox'} · {account.is_enabled ? <Badge tone="green">Stores can pay invoices with it</Badge> : <Badge>Off</Badge>}
                        {account.last_success_at && <span className="block text-xs text-slate-500">Last payment {formatDateTime(account.last_success_at)}</span>}
                      </p>
                    ) : (
                      <p className="mt-1 text-sm text-slate-500">Not connected. Stores pay invoices by transfer and “I have paid”.</p>
                    )}
                    <div className="mt-2 flex flex-wrap gap-2">
                      <Button size="sm" onClick={() => setEditing(row)}>{account ? 'Edit details' : 'Connect'}</Button>
                      {account && (row.provider === 'simulator' || (account.environment === 'production' && row.live_allowed)) && (
                        <Button size="sm" busy={busy === row.provider} busyLabel="Saving…" onClick={() => run(row.provider, () => adminFetch(`/super-admin/payment-providers/${row.provider}/account/enabled`, { method: 'PUT', body: { enabled: !account.is_enabled } }), { success: 'Saved.' }).then((done) => done !== undefined && state.reload())}>
                          {account.is_enabled ? 'Turn off for invoices' : 'Use for invoices'}
                        </Button>
                      )}
                      {account && <Button size="sm" variant="ghost" onClick={() => setRemoving(row)}>Disconnect</Button>}
                    </div>
                  </div>
                </section>
              );
            })}
            <section className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-slate-600">
              <h2 className="flex items-center gap-2 text-base font-semibold text-slate-900"><CardIcon className="h-5 w-5" /> Card payments</h2>
              <p className="mt-1 text-sm">No card provider is chosen yet (owner decision 2). Once one is chosen and its sandbox is available, it is added here through the same adapter contract.</p>
            </section>
          </div>

          {live && <LiveDialog row={live.row} allowed={live.allowed} onClose={() => setLive(null)} onDone={() => { setLive(null); state.reload(); }} />}
          {editing && <AccountDialog info={editing} endpoint={`/super-admin/payment-providers/${editing.provider}/account`} currency={null} owner="platform" onClose={() => setEditing(null)} onSaved={() => { setEditing(null); state.reload(); }} />}
          <ConfirmDialog
            open={removing !== null}
            title={`Disconnect Umar Techy’s ${removing?.label ?? ''} account?`}
            confirmLabel="Disconnect"
            busy={busy === 'remove'}
            onClose={() => setRemoving(null)}
            onConfirm={() => removing && run('remove', () => adminFetch(`/super-admin/payment-providers/${removing.provider}/account`, { method: 'DELETE' }), { success: 'Disconnected.' }).then((done) => {
              if (done !== undefined) {
                setRemoving(null);
                state.reload();
              }
            })}
          >
            <p>Stores can no longer pay invoices with {removing?.label}. Past payments stay recorded.</p>
          </ConfirmDialog>
        </div>
      )}
    </QueryState>
  );
}

function LiveDialog({ row, allowed, onClose, onDone }: { row: ProviderRow; allowed: boolean; onClose: () => void; onDone: () => void }) {
  const form = useForm({ reason: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(`/super-admin/payment-providers/${row.provider}/live`, { method: 'PUT', body: { allowed, reason: form.values.reason } }), allowed ? `${row.label} is open for real payments.` : `${row.label} is closed for real payments.`)) !== undefined) onDone();
  }

  return (
    <Dialog open title={allowed ? `Open ${row.label} for real payments?` : `Close ${row.label} for real payments?`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        {allowed ? (
          <div className="space-y-2 text-sm text-slate-700">
            <p>Stores will be able to connect their production {row.label} accounts and take real money.</p>
            <p className="rounded-md bg-amber-50 p-2 text-amber-900">Only do this after a full check in the {row.label} sandbox with real sandbox credentials: a payment, a decline, a timeout and a status inquiry. {row.capabilities.verified ? '' : 'The integration is marked as not yet checked.'}</p>
          </div>
        ) : (
          <p className="text-sm text-slate-700">Every store’s live {row.label} account is switched off at checkout at once ({row.stores.taking_payments} now). Payments already made are not affected. Stores turn it back on themselves after you reopen it.</p>
        )}
        <TextAreaField label="Reason" rows={2} value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={300} required data-autofocus hint="Kept in the audit log." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant={allowed ? 'primary' : 'danger'} busy={form.busy} busyLabel="Saving…">{allowed ? 'Open for real payments' : 'Close for real payments'}</Button>
        </div>
      </form>
    </Dialog>
  );
}

type LookupRow = StaffAttempt & { store: { id: number; name: string | null } };

function Lookup({ q, page, onQuery, onPage }: { q: string; page: string; onQuery: (q: string) => void; onPage: (page: number) => void }) {
  const list = usePagedApi<LookupRow>('/super-admin/payment-attempts', { q, page });
  const columns: Column<LookupRow>[] = [
    { key: 'when', header: 'When', render: (a) => formatDateTime(a.created_at) },
    { key: 'store', header: 'Store', priority: true, render: (a) => a.store.name ?? `#${a.store.id}` },
    { key: 'what', header: 'For', render: (a) => humanize(a.purpose) },
    { key: 'provider', header: 'Provider', render: (a) => `${humanize(a.provider)}${a.environment === 'sandbox' ? ' (sandbox)' : ''}` },
    { key: 'reference', header: 'Reference', render: (a) => <span className="font-mono text-xs">{a.reference}{a.provider_transaction_reference ? ` / ${a.provider_transaction_reference}` : ''}</span> },
    { key: 'status', header: 'Status', priority: true, render: (a) => <span className="space-y-0.5"><StatusBadge status={a.status} />{a.failure_label && <span className="block text-xs text-slate-500">{a.failure_label}</span>}</span> },
    { key: 'amount', header: 'Amount', align: 'right', priority: true, render: (a) => money(a.amount_minor, a.currency) },
  ];

  return (
    <div className="space-y-3">
      <FilterBar>
        <div className="w-80 max-w-full">
          <SearchField label="Reference" value={q} onChange={onQuery} placeholder="UT… or the provider’s reference" />
        </div>
      </FilterBar>
      <DataTable caption="Online payment attempts" columns={columns} rows={list.rows} rowKey={(a) => a.id} loading={list.loading} error={list.error} onRetry={list.reload}
        empty={<EmptyPanel title={q ? 'Nothing with this reference' : 'No online payments yet'} />} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={onPage} />
    </div>
  );
}

type FindingRow = { id: string; kind: string; created_at: string; store: { id: number; name: string | null }; attempt: StaffAttempt | null };

function Findings() {
  const [page, setPage] = useState('1');
  const list = usePagedApi<FindingRow>('/super-admin/payment-findings', { page });

  return (
    <Card title="Open findings in every store" description="Stores resolve their own findings on their Payments page. A finding open for long may need a word with the store.">
      <DataTable
        caption="Open reconciliation findings"
        columns={[
          { key: 'when', header: 'Found', render: (f) => formatDateTime(f.created_at) },
          { key: 'store', header: 'Store', priority: true, render: (f) => f.store.name ?? `#${f.store.id}` },
          { key: 'kind', header: 'What', priority: true, render: (f) => RECONCILIATION_LABELS[f.kind]?.title ?? humanize(f.kind) },
          { key: 'reference', header: 'Reference', render: (f) => <span className="font-mono text-xs">{f.attempt?.reference ?? '—'}</span> },
          { key: 'amount', header: 'Amount', align: 'right', render: (f) => (f.attempt ? money(f.attempt.amount_minor, f.attempt.currency) : '—') },
        ]}
        rows={list.rows}
        rowKey={(f) => f.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="Nothing open" description="Every store’s online payments match their providers." />}
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(next) => setPage(String(next))} />
    </Card>
  );
}
