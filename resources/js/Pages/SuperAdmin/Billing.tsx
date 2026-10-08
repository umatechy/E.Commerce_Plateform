import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import { FilterBar } from '@/Components/ui/Filters';
import Badge, { StatusBadge, humanize } from '@/Components/ui/Badge';
import { Card, Details, EmptyPanel, QueryState, StatCard, Tabs } from '@/Components/ui/Page';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch, idempotencyKey } from '@/lib/adminApi';
import { fromMinor, money, toMinor } from '@/lib/money';
import { formatUtcDate } from '@/lib/datetime';
import { options } from '@/lib/labels';
import CurrencyField from '@/Components/CurrencyField';
import { DEFAULT_CURRENCY } from '@/lib/money';
import { CreditNotes, PaymentNotices } from '@/Components/SuperAdmin/BillingDesk';

/**
 * Module 29 platform billing (/api/v1/super-admin/billing/...): what
 * stores owe the platform. Platform staff only.
 *
 * Everything here is in UTC (Module 33 §50.5): billing periods, due
 * dates and payment dates are platform dates and are shown and entered
 * as UTC dates. Prices are whatever the platform has entered; this page
 * suggests none. Recording a payment, voiding an invoice, moving a due
 * date and changing a price all ask for the password again.
 */
type Summary = {
  currencies: { currency: string; mrr_minor: number; outstanding_minor: number; overdue_minor: number; open_invoices: number; collected_this_month_minor: number; refunded_this_month_minor?: number }[];
  subscriptions_by_status: Record<string, number>;
};
type Price = { id: string; package?: { code: string; name: string }; billing_interval: string; currency: string; amount_minor: number; is_active: boolean };
type Invoice = {
  id: string; number: string; status: string; is_overdue: boolean; currency: string; total_minor: number; amount_paid_minor: number; amount_due_minor: number;
  period_start: string; period_end: string; issued_at: string; due_at: string; store?: { id: string; name: string } | null; package?: { code: string; name: string };
  lines?: { description: string; amount_minor: number }[];
  payments?: { id: string; amount_minor: number; currency: string; method: string; reference: string | null; received_at: string }[];
};
type Package = { code: string; name: string };

const INVOICE_STATUSES = ['open', 'paid', 'void', 'uncollectible'] as const;
const PAYMENT_METHODS = ['bank_transfer', 'cash', 'card', 'mobile_wallet', 'other'] as const;

function Overview() {
  const state = useApi<{ data: Summary }>('/super-admin/billing/summary');

  return (
    <QueryState state={state} lines={4}>
      {({ data }) => (
        <div className="space-y-4">
          {data.currencies.length === 0 && <EmptyPanel title="No billing figures yet" description="They appear once prices are set and invoices are issued." />}
          {data.currencies.map((row) => (
            <Card key={row.currency} title={row.currency}>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Monthly recurring" value={money(row.mrr_minor, row.currency)} hint="Yearly prices counted as one twelfth." />
                <StatCard label="Outstanding" value={money(row.outstanding_minor, row.currency)} hint={`${row.open_invoices} open invoice${row.open_invoices === 1 ? '' : 's'}`} />
                <StatCard label="Overdue" value={money(row.overdue_minor, row.currency)} />
                <StatCard label="Collected this month" value={money(row.collected_this_month_minor, row.currency)} hint={(row.refunded_this_month_minor ?? 0) > 0 ? `${money(row.refunded_this_month_minor ?? 0, row.currency)} paid back` : undefined} />
              </div>
            </Card>
          ))}
          <Card title="Subscriptions by status">
            <ul className="flex flex-wrap gap-3 text-sm">
              {Object.entries(data.subscriptions_by_status).map(([status, total]) => (
                <li key={status} className="flex items-center gap-2">
                  <StatusBadge status={status} /> {total}
                </li>
              ))}
            </ul>
          </Card>
        </div>
      )}
    </QueryState>
  );
}

function Prices() {
  const prices = useApi<{ data: Price[] }>('/super-admin/billing/prices');
  const packages = useApi<{ data: Package[] }>('/super-admin/packages');
  const [adding, setAdding] = useState(false);
  const form = useForm({ package_code: '', billing_interval: 'monthly', currency: DEFAULT_CURRENCY, amount: '' });
  const [local, setLocal] = useState<string | null>(null);
  const { busy, run } = useAction();

  async function save(event: FormEvent) {
    event.preventDefault();
    const code = form.values.currency.toUpperCase();
    const minor = toMinor(form.values.amount, code);
    if (minor === null) {
      setLocal(`Enter an amount such as 29.00 (${code}).`);

      return;
    }
    setLocal(null);
    const body = { package_code: form.values.package_code, billing_interval: form.values.billing_interval, currency: code, amount_minor: minor };
    if ((await form.submit(() => adminFetch('/super-admin/billing/prices', { method: 'POST', body }), 'Price saved.')) !== undefined) {
      setAdding(false);
      prices.reload();
    }
  }

  const columns: Column<Price>[] = [
    { key: 'package', header: 'Package', render: (price) => <span className="font-medium">{price.package?.name ?? '—'}</span> },
    { key: 'interval', header: 'Billed', render: (price) => humanize(price.billing_interval) },
    { key: 'amount', header: 'Price', align: 'right', priority: true, render: (price) => money(price.amount_minor, price.currency) },
    { key: 'state', header: 'Status', priority: true, render: (price) => <Badge tone={price.is_active ? 'green' : 'neutral'}>{price.is_active ? 'In use' : 'Switched off'}</Badge> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (price) => (
        <Button size="sm" variant="ghost" busy={busy === price.id} onClick={() => run(price.id, () => adminFetch(`/super-admin/billing/prices/${price.id}`, { method: 'PATCH', body: { is_active: !price.is_active } }), { success: 'Price updated.' }).then((result) => result !== undefined && prices.reload())}>
          {price.is_active ? 'Switch off' : 'Switch on'}
        </Button>
      ),
    },
  ];

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button variant="primary" onClick={() => { form.reset({ package_code: '', billing_interval: 'monthly', currency: DEFAULT_CURRENCY, amount: '' }); setLocal(null); setAdding(true); }}>Set a price</Button>
      </div>
      <DataTable caption="Package prices" columns={columns} rows={prices.data?.data ?? null} rowKey={(price) => price.id} loading={prices.loading} error={prices.error} onRetry={prices.reload} empty={<EmptyPanel title="No prices set" description="Without a price, no invoice can be issued for a package." />} />

      <Dialog open={adding} title="Set a price" description="For a package, billing interval and currency. An existing price for the same three is replaced. Invoices already issued are not changed." onClose={() => setAdding(false)} busy={form.busy}>
        <form onSubmit={save} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <SelectField label="Package" value={form.values.package_code} onChange={(v) => form.set('package_code', v)} error={form.errors.package_code} placeholder="Choose a package" options={(packages.data?.data ?? []).map((item) => ({ value: item.code, label: item.name }))} required />
          <div className="grid gap-4 sm:grid-cols-3">
            <SelectField label="Billed" value={form.values.billing_interval} onChange={(v) => form.set('billing_interval', v)} error={form.errors.billing_interval} options={[{ value: 'monthly', label: 'Monthly' }, { value: 'yearly', label: 'Yearly' }]} />
            <CurrencyField value={form.values.currency} onChange={(v) => form.set('currency', v)} error={form.errors.currency} />
            <TextField label="Amount" inputMode="decimal" value={form.values.amount} onChange={(v) => form.set('amount', v)} error={local ?? form.errors.amount_minor} required />
          </div>
          <div className="flex justify-end gap-2">
            <Button onClick={() => setAdding(false)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save price</Button>
          </div>
        </form>
      </Dialog>
    </>
  );
}

function InvoiceDialog({ invoiceId, onClose, onChanged }: { invoiceId: string; onClose: () => void; onChanged: () => void }) {
  const state = useApi<{ data: Invoice }>(`/super-admin/billing/invoices/${invoiceId}`);
  const [action, setAction] = useState<'pay' | 'void' | 'extend' | 'credit' | null>(null);
  const form = useForm({ amount: '', method: 'bank_transfer', reference: '', note: '', received_on: '', reason: '', due_on: '', settlement: 'account_credit' });
  const [key, setKey] = useState(idempotencyKey);
  const [local, setLocal] = useState<string | null>(null);
  const invoice = state.data?.data;

  function start(next: 'pay' | 'void' | 'extend' | 'credit') {
    form.reset({ amount: invoice ? fromMinor(next === 'credit' ? 0 : invoice.amount_due_minor, invoice.currency) : '', method: 'bank_transfer', reference: '', note: '', received_on: '', reason: '', due_on: '', settlement: invoice?.status === 'open' ? 'reduce_balance' : 'account_credit' });
    setKey(idempotencyKey());
    setLocal(null);
    setAction(next);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    if (!invoice || action === null) return;
    const v = form.values;
    let path = '';
    let body: Record<string, unknown> = {};
    if (action === 'pay') {
      const minor = toMinor(v.amount, invoice.currency);
      if (minor === null || minor < 1) {
        setLocal(`Enter an amount above zero (${invoice.currency}).`);

        return;
      }
      path = 'payments';
      body = { amount_minor: minor, method: v.method, reference: v.reference || null, note: v.note || null, received_at: v.received_on === '' ? null : v.received_on, idempotency_key: key };
    } else if (action === 'credit') {
      // Phase B47 (Module 29 §43): a credit note; one Idempotency-Key per form.
      const minor = toMinor(v.amount, invoice.currency);
      if (minor === null || minor < 1) {
        setLocal(`Enter an amount above zero (${invoice.currency}).`);

        return;
      }
      setLocal(null);
      const created = await form.submit(
        () => adminFetch<{ data: { status: string } }>(`/super-admin/billing/invoices/${invoice.id}/credit-notes`, {
          method: 'POST',
          headers: { 'Idempotency-Key': key },
          body: { amount_minor: minor, settlement: v.settlement, reason: v.reason, refund_method: v.settlement === 'refund' ? v.method : null, refund_reference: v.settlement === 'refund' ? v.reference : null },
        }),
        'Credit note saved.',
      );
      if (created !== undefined) {
        setAction(null);
        state.reload();
        onChanged();
      }

      return;
    } else if (action === 'void') {
      path = 'void';
      body = { reason: v.reason };
    } else {
      path = 'extend-due-date';
      body = { due_at: v.due_on === '' ? null : `${v.due_on}T23:59:59Z`, reason: v.reason };
    }
    setLocal(null);
    const saved = await form.submit(() => adminFetch(`/super-admin/billing/invoices/${invoice.id}/${path}`, { method: 'POST', body }), action === 'pay' ? 'Payment recorded.' : action === 'void' ? 'Invoice voided.' : 'Due date moved.');
    if (saved !== undefined) {
      setAction(null);
      state.reload();
      onChanged();
    }
  }

  return (
    <Dialog open wide title={invoice ? `Invoice ${invoice.number}` : 'Invoice'} description={invoice?.store ? invoice.store.name : undefined} onClose={onClose} busy={form.busy}>
      <QueryState state={state}>
        {({ data }) => (
          <div className="space-y-4">
            <Details
              items={[
                { label: 'Status', value: <StatusBadge status={data.is_overdue ? 'overdue' : data.status} /> },
                { label: 'Package', value: data.package?.name ?? '—' },
                { label: 'Period (UTC)', value: `${formatUtcDate(data.period_start)} – ${formatUtcDate(data.period_end)}` },
                { label: 'Due (UTC)', value: formatUtcDate(data.due_at) },
                { label: 'Total', value: money(data.total_minor, data.currency) },
                { label: 'Still due', value: money(data.amount_due_minor, data.currency) },
              ]}
            />
            {(data.payments ?? []).length > 0 && (
              <ul className="divide-y divide-slate-100 rounded-md border border-slate-200 text-sm">
                {(data.payments ?? []).map((payment) => (
                  <li key={payment.id} className="flex flex-wrap justify-between gap-2 px-3 py-2">
                    <span>{formatUtcDate(payment.received_at)} · {humanize(payment.method)}{payment.reference ? ` · ${payment.reference}` : ''}</span>
                    <span>{money(payment.amount_minor, payment.currency)}</span>
                  </li>
                ))}
              </ul>
            )}

            {action === null && (
              <div className="flex flex-wrap gap-2">
                {data.status === 'open' && (
                  <>
                    <Button variant="primary" onClick={() => start('pay')}>Record a payment</Button>
                    <Button onClick={() => start('extend')}>Move the due date</Button>
                    <Button variant="danger" onClick={() => start('void')}>Void invoice</Button>
                  </>
                )}
                {data.status !== 'void' && <Button onClick={() => start('credit')}>Credit note</Button>}
                <a href={`/api/v1/super-admin/billing/invoices/${data.id}/pdf`} className="inline-flex items-center rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-50">Download PDF</a>
              </div>
            )}

            {action !== null && (
              <form onSubmit={save} className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4" noValidate>
                <h3 className="text-sm font-semibold text-slate-900">{action === 'pay' ? 'Record a payment received' : action === 'void' ? 'Void this invoice' : action === 'credit' ? 'Credit note' : 'Move the due date'}</h3>
                {action === 'credit' && <p className="text-sm text-slate-700">Corrects this invoice without changing it. Amount including tax. Above the approval threshold, another team member approves it before it counts.</p>}
                {action === 'void' && <p className="text-sm text-slate-700">A voided invoice is no longer owed and cannot be reopened. An invoice with payments cannot be voided.</p>}
                <FormError message={form.formError} />
                {action === 'pay' && (
                  <div className="grid gap-3 sm:grid-cols-2">
                    <TextField label={`Amount (${data.currency})`} inputMode="decimal" value={form.values.amount} onChange={(v) => form.set('amount', v)} error={local ?? form.errors.amount_minor} required data-autofocus />
                    <SelectField label="Method" value={form.values.method} onChange={(v) => form.set('method', v)} error={form.errors.method} options={options(PAYMENT_METHODS)} />
                    <TextField label="Reference" optional value={form.values.reference} onChange={(v) => form.set('reference', v)} error={form.errors.reference} maxLength={128} />
                    <TextField label="Received on (UTC)" optional type="date" value={form.values.received_on} onChange={(v) => form.set('received_on', v)} error={form.errors.received_at} hint="Empty: now." />
                    <div className="sm:col-span-2"><TextField label="Note" optional value={form.values.note} onChange={(v) => form.set('note', v)} error={form.errors.note} maxLength={500} /></div>
                  </div>
                )}
                {action === 'credit' && (
                  <div className="grid gap-3 sm:grid-cols-2">
                    <TextField label={`Amount (${data.currency})`} inputMode="decimal" value={form.values.amount} onChange={(v) => form.set('amount', v)} error={local ?? form.errors.amount_minor} required data-autofocus />
                    <SelectField
                      label="Settle as"
                      value={form.values.settlement}
                      onChange={(v) => form.set('settlement', v)}
                      error={form.errors.settlement}
                      options={data.status === 'open' ? [{ value: 'reduce_balance', label: 'Less to pay on this invoice' }] : [{ value: 'account_credit', label: 'Account credit (next invoices)' }, { value: 'refund', label: 'Refund (money paid back)' }]}
                    />
                    {form.values.settlement === 'refund' && (
                      <>
                        <SelectField label="Paid back by" value={form.values.method} onChange={(v) => form.set('method', v)} error={form.errors.refund_method} options={options(PAYMENT_METHODS)} />
                        <TextField label="Refund reference" value={form.values.reference} onChange={(v) => form.set('reference', v)} error={form.errors.refund_reference} maxLength={128} required />
                      </>
                    )}
                  </div>
                )}
                {action === 'extend' && <TextField label="New due date (UTC)" type="date" value={form.values.due_on} onChange={(v) => form.set('due_on', v)} error={form.errors.due_at} required data-autofocus />}
                {action !== 'pay' && <TextField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={255} required />}
                <div className="flex justify-end gap-2">
                  <Button onClick={() => setAction(null)} disabled={form.busy}>Cancel</Button>
                  <Button type="submit" variant={action === 'void' ? 'danger' : 'primary'} busy={form.busy} busyLabel="Saving…">{action === 'pay' ? 'Record payment' : action === 'void' ? 'Void invoice' : action === 'credit' ? 'Save credit note' : 'Move due date'}</Button>
                </div>
              </form>
            )}
          </div>
        )}
      </QueryState>
    </Dialog>
  );
}

function Invoices() {
  const [filters, setFilters] = useUrlState({ status: '', overdue: '', page: '1' });
  const list = usePagedApi<Invoice>('/super-admin/billing/invoices', { status: filters.status, overdue: filters.overdue === '1' ? 1 : undefined, page: filters.page });
  const [open, setOpen] = useState<string | null>(null);

  const columns: Column<Invoice>[] = [
    {
      key: 'number',
      header: 'Invoice',
      render: (invoice) => (
        <div>
          <span className="font-medium">{invoice.number}</span>
          <p className="text-xs text-slate-600">{invoice.store?.name ?? '—'}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (invoice) => <StatusBadge status={invoice.is_overdue ? 'overdue' : invoice.status} /> },
    { key: 'due', header: 'Due (UTC)', render: (invoice) => formatUtcDate(invoice.due_at) },
    { key: 'total', header: 'Total', align: 'right', render: (invoice) => money(invoice.total_minor, invoice.currency) },
    { key: 'due_amount', header: 'Still due', align: 'right', priority: true, render: (invoice) => money(invoice.amount_due_minor, invoice.currency) },
    { key: 'open', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (invoice) => <Button size="sm" variant="ghost" onClick={() => setOpen(invoice.id)}>Open</Button> },
  ];

  return (
    <>
      <FilterBar>
        <div className="w-44"><SelectField label="Status" value={filters.status} onChange={(status) => setFilters({ status, page: '1' })} options={options(INVOICE_STATUSES)} placeholder="Any status" /></div>
        <div className="w-44"><SelectField label="Show" value={filters.overdue} onChange={(overdue) => setFilters({ overdue, page: '1' })} options={[{ value: '1', label: 'Overdue only' }]} placeholder="All invoices" /></div>
      </FilterBar>
      <DataTable caption="Platform invoices" columns={columns} rows={list.rows} rowKey={(invoice) => invoice.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No invoices match" />} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />
      {open && <InvoiceDialog invoiceId={open} onClose={() => setOpen(null)} onChanged={list.reload} />}
    </>
  );
}

export default function Billing() {
  const [state, setState] = useUrlState({ tab: 'overview' });
  const tab = ['overview', 'invoices', 'notices', 'credits', 'prices'].includes(state.tab) ? state.tab : 'overview';

  return (
    <AdminPage title="Platform billing" description="What stores pay the platform. All dates are UTC.">
      <Tabs label="Billing sections" tabs={[{ id: 'overview', label: 'Overview' }, { id: 'invoices', label: 'Invoices' }, { id: 'notices', label: 'Payments to confirm' }, { id: 'credits', label: 'Credit notes' }, { id: 'prices', label: 'Prices' }]} active={tab} onChange={(next) => setState({ tab: next })} />
      <div role="tabpanel">{tab === 'overview' ? <Overview /> : tab === 'invoices' ? <Invoices /> : tab === 'notices' ? <PaymentNotices /> : tab === 'credits' ? <CreditNotes /> : <Prices />}</div>
    </AdminPage>
  );
}
