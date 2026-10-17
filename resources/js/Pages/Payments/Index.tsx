import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Button, { ButtonLink, FOCUS_RING } from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField } from '@/Components/ui/Form';
import { FilterBar } from '@/Components/ui/Filters';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { EmptyPanel, QueryState, Tabs } from '@/Components/ui/Page';
import PaymentDrawer from '@/Components/Orders/PaymentDialogs';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { useUrlState } from '@/lib/useUrlState';
import { money } from '@/lib/money';
import { dateTimeOrDash, formatDateTime } from '@/lib/datetime';
import { options } from '@/lib/labels';
import { PAYMENT_METHOD_LABELS, PAYMENT_STATUSES, type Payment } from '@/lib/orders';
import { RECONCILIATION_LABELS, type StaffAttempt } from '@/lib/payments';

/**
 * Module 12 §68 "Store Admin Payment View": the store's payments
 * (GET /api/v1/payments), and — Phase B48, Module 12 §53–55 — what
 * reconciliation found that a person must look at. Resolving records a
 * note; money is moved by the payment's own actions (refund, record).
 */
const FILTER_DEFAULTS = { tab: 'all', status: '', page: '1', findings: 'open' };

type Finding = {
  id: string;
  kind: string;
  status: 'open' | 'resolved';
  details: { expected_minor?: number; expected_currency?: string; confirmed_minor?: number; confirmed_currency?: string; reason?: string; reference?: string; checks?: number };
  payment: { id: string; order: { id: string; order_number: string } | null } | null;
  attempt: StaffAttempt | null;
  resolution_note: string | null;
  resolved_at: string | null;
  created_at: string;
};

export default function Index() {
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const [open, setOpen] = useState<string | null>(null);
  const findings = useApi<{ data: Finding[]; meta: { open_count: number } }>('/payment-reconciliation', { status: filters.findings });
  const openCount = findings.data?.meta.open_count ?? 0;

  return (
    <AdminPage
      title="Payments"
      description="Payments for your orders. Open one to see its transactions and online attempts, record a payment received, or refund."
      actions={<ButtonLink href="/settings/payments" size="sm">Payments setup</ButtonLink>}
    >
      <Tabs
        label="Payment views"
        active={filters.tab}
        onChange={(tab) => setFilters({ tab, page: '1' })}
        tabs={[{ id: 'all', label: 'All payments' }, { id: 'attention', label: openCount > 0 ? `Needs attention (${openCount})` : 'Needs attention' }]}
      />
      <div className="mt-4">
        {filters.tab === 'attention'
          ? <Findings state={findings} status={filters.findings} onStatus={(findingsStatus) => setFilters({ findings: findingsStatus })} onOpenPayment={setOpen} />
          : <AllPayments filters={filters} setFilters={setFilters} onOpen={setOpen} />}
      </div>

      {open && <PaymentDrawer paymentId={open} onClose={() => setOpen(null)} onChanged={findings.reload} />}
    </AdminPage>
  );
}

function AllPayments({ filters, setFilters, onOpen }: { filters: typeof FILTER_DEFAULTS; setFilters: (next: Partial<typeof FILTER_DEFAULTS>) => void; onOpen: (id: string) => void }) {
  const list = usePagedApi<Payment>('/payments', { status: filters.status, page: filters.page });

  const columns: Column<Payment>[] = [
    {
      key: 'order',
      header: 'Order',
      render: (payment) =>
        payment.order ? (
          <Link href={`/orders/${payment.order.id}`} className={`rounded font-mono text-brand-700 hover:underline ${FOCUS_RING}`}>
            {payment.order.order_number}
          </Link>
        ) : '—',
    },
    { key: 'created', header: 'Created', render: (payment) => dateTimeOrDash(payment.created_at) },
    { key: 'method', header: 'Method', render: (payment) => PAYMENT_METHOD_LABELS[payment.method] ?? humanize(payment.method) },
    { key: 'status', header: 'Status', priority: true, render: (payment) => <StatusBadge status={payment.status} /> },
    { key: 'amount', header: 'Amount', align: 'right', priority: true, render: (payment) => <span className="font-medium">{money(payment.amount_minor, payment.currency)}</span> },
    { key: 'open', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (payment) => <Button size="sm" variant="ghost" onClick={() => onOpen(payment.id)}>Open</Button> },
  ];

  return (
    <>
      <FilterBar>
        <div className="w-52">
          <SelectField label="Status" value={filters.status} onChange={(status) => setFilters({ status, page: '1' })} options={options(PAYMENT_STATUSES)} placeholder="Any status" />
        </div>
        {filters.status !== '' && <Button onClick={() => setFilters({ status: '', page: '1' })}>Clear filter</Button>}
      </FilterBar>

      <DataTable
        caption="Payments"
        columns={columns}
        rows={list.rows}
        rowKey={(payment) => payment.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={filters.status !== '' ? <EmptyPanel title="No payments with this status" action={<Button onClick={() => setFilters({ status: '', page: '1' })}>Clear filter</Button>} /> : <EmptyPanel title="No payments yet" description="A payment appears when a customer checks out." />}
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />
    </>
  );
}

function Findings({ state, status, onStatus, onOpenPayment }: { state: ReturnType<typeof useApi<{ data: Finding[]; meta: { open_count: number } }>>; status: string; onStatus: (status: string) => void; onOpenPayment: (id: string) => void }) {
  const access = useAccess();
  const [resolving, setResolving] = useState<Finding | null>(null);

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">
        Online payments are checked with the provider every minute. When something does not add up — a different amount, money that arrived after the order was given up, no answer for a day — it is listed here and nothing is marked paid by itself.
      </p>
      <div className="w-52">
        <SelectField label="Show" value={status} onChange={onStatus} options={[{ value: 'open', label: 'To look at' }, { value: 'resolved', label: 'Resolved' }]} />
      </div>
      <QueryState state={state} lines={4}>
        {({ data }) =>
          data.length === 0 ? (
            <EmptyPanel title={status === 'open' ? 'Nothing needs attention' : 'Nothing resolved yet'} description={status === 'open' ? 'Every online payment matches what the provider says.' : undefined} />
          ) : (
            <ul className="space-y-3">
              {data.map((finding) => {
                const copy = RECONCILIATION_LABELS[finding.kind] ?? { title: humanize(finding.kind), help: '' };
                const attempt = finding.attempt;

                return (
                  <li key={finding.id} className="rounded-lg border border-slate-200 bg-white p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <h3 className="font-semibold text-slate-900">{copy.title}</h3>
                        <p className="mt-1 max-w-2xl text-sm text-slate-600">{copy.help}</p>
                      </div>
                      <StatusBadge status={finding.status === 'open' ? 'needs_attention' : 'resolved'} label={finding.status === 'open' ? 'To look at' : 'Resolved'} />
                    </div>
                    <dl className="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-4">
                      <div><dt className="text-xs text-slate-500">Order</dt><dd>{finding.payment?.order ? <Link href={`/orders/${finding.payment.order.id}`} className="font-mono text-brand-700 hover:underline">{finding.payment.order.order_number}</Link> : '—'}</dd></div>
                      <div><dt className="text-xs text-slate-500">Reference</dt><dd className="font-mono">{attempt?.reference ?? finding.details.reference ?? '—'}</dd></div>
                      {finding.details.expected_minor !== undefined && (
                        <div><dt className="text-xs text-slate-500">Asked / confirmed</dt><dd>{money(finding.details.expected_minor, finding.details.expected_currency ?? '')} / {money(finding.details.confirmed_minor ?? 0, finding.details.confirmed_currency ?? '')}</dd></div>
                      )}
                      <div><dt className="text-xs text-slate-500">Found</dt><dd>{formatDateTime(finding.created_at)}</dd></div>
                    </dl>
                    {finding.resolution_note && <p className="mt-2 rounded-md bg-slate-50 p-2 text-sm text-slate-700">Resolved {finding.resolved_at ? formatDateTime(finding.resolved_at) : ''}: {finding.resolution_note}</p>}
                    <div className="mt-3 flex flex-wrap gap-2">
                      {finding.payment && <Button size="sm" onClick={() => onOpenPayment(finding.payment!.id)}>Open payment</Button>}
                      {finding.status === 'open' && access.can('payments.reconcile') && <Button size="sm" variant="primary" onClick={() => setResolving(finding)}>Resolve</Button>}
                    </div>
                  </li>
                );
              })}
            </ul>
          )
        }
      </QueryState>
      {resolving && <ResolveDialog finding={resolving} onClose={() => setResolving(null)} onDone={() => { setResolving(null); state.reload(); }} />}
    </div>
  );
}

function ResolveDialog({ finding, onClose, onDone }: { finding: Finding; onClose: () => void; onDone: () => void }) {
  const form = useForm({ note: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(`/payment-reconciliation/${finding.id}/resolve`, { method: 'POST', body: form.values }), 'Marked as resolved.')) !== undefined) onDone();
  }

  return (
    <Dialog open title="Resolve this payment" description={RECONCILIATION_LABELS[finding.kind]?.title} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <p className="text-sm text-slate-700">Say what you did, for example “Refunded in the JazzCash portal, ref 12345” or “Customer confirmed; recorded the payment by hand”. Resolving moves no money.</p>
        <TextAreaField label="What was done" rows={3} value={form.values.note} onChange={(v) => form.set('note', v)} error={form.errors.note} maxLength={500} required data-autofocus />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Mark as resolved</Button>
        </div>
      </form>
    </Dialog>
  );
}
