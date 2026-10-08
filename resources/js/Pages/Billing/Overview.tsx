import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, TextAreaField } from '@/Components/ui/Form';
import Badge, { StatusBadge, humanize } from '@/Components/ui/Badge';
import { Card, Details, ErrorPanel, QueryState, Skeleton, UsageMeter } from '@/Components/ui/Page';
import InvoiceTable, { type Invoice } from '@/Components/InvoiceTable';
import { useAccess } from '@/lib/access';
import { readPage, useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { formatUtcDate } from '@/lib/datetime';
import { FEATURE_LABELS, USAGE_LABELS, labelFor } from '@/lib/labels';
import PlanChangeDialog from '@/Components/Billing/PlanChangeDialog';
import PaymentNoticeDialog from '@/Components/Billing/PaymentNoticeDialog';
import BillingAccountCards from '@/Components/Billing/BillingAccountCards';

/**
 * Module 04 §40-41 "Customer Package Visibility / Usage Dashboard" and
 * Module 29 (Phase B23) billing: /api/v1/billing, /billing/invoices,
 * /subscription and /subscription/usage. The store's OWN data only,
 * resolved server-side (this page sends no store id anywhere).
 *
 * Prices and limits are the server's; nothing here is a price list.
 * Billing periods and due dates are platform dates in UTC (Module 33
 * §50.5) and are shown as UTC dates, never moved into the store's
 * timezone.
 */
type Billing = {
  subscription: {
    status: string;
    grants_access: boolean;
    package: { code: string; name: string };
    billing_interval: string;
    currency: string;
    trial_ends_at: string | null;
    current_period_ends_at: string | null;
    grace_period_ends_at: string | null;
    cancel_at_period_end: boolean;
    /** Phase B47: a downgrade waiting for the period end. */
    scheduled_package?: { code: string; name: string } | null;
  };
  upcoming: { period_start: string; currency: string; total_minor: number } | null;
  balance: { open_invoices: number; overdue_invoices: number; amount_due_minor: number; account_credit_minor?: number };
};

type Entitlement = { key: string; type: string; enabled: boolean | null; limit: number | null; unlimited: boolean };
type Subscription = { package?: { name: string; entitlements?: Entitlement[] } };
type UsageEntry = { limit: number | null; current: number; unlimited: boolean; remaining: number | null };
type InvoiceDetail = Invoice & {
  subtotal_minor: number;
  tax_minor: number;
  tax_label: string;
  amount_paid_minor: number;
  amount_due_minor: number;
  credit_applied_minor?: number;
  amount_credited_minor?: number;
  issued_at: string;
  paid_at: string | null;
  lines?: { kind?: string; description: string; quantity: number; unit_amount_minor: number; amount_minor: number }[];
  payments?: { id: string; amount_minor: number; currency: string; method: string; reference: string | null; received_at: string }[];
};

function InvoiceDialog({ invoice, onClose, onPaid }: { invoice: Invoice; onClose: () => void; onPaid?: (invoice: InvoiceDetail) => void }) {
  const state = useApi<{ data: InvoiceDetail }>(`/billing/invoices/${invoice.id}`);

  return (
    <Dialog open wide title={`Invoice ${invoice.number}`} onClose={onClose}>
      <QueryState state={state}>
        {({ data }) => (
          <div className="space-y-4">
            <Details
              items={[
                { label: 'Status', value: <StatusBadge status={data.is_overdue ? 'overdue' : data.status} /> },
                { label: 'Period (UTC)', value: `${formatUtcDate(data.period_start)} – ${formatUtcDate(data.period_end)}` },
                { label: 'Issued (UTC)', value: formatUtcDate(data.issued_at) },
                { label: 'Due (UTC)', value: formatUtcDate(data.due_at) },
              ]}
            />
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Invoice lines</caption>
              <thead className="text-xs uppercase text-slate-500">
                <tr><th scope="col" className="py-1">Item</th><th scope="col" className="py-1 text-right">Amount</th></tr>
              </thead>
              <tbody>
                {(data.lines ?? []).map((line, index) => (
                  <tr key={index} className="border-t border-slate-100">
                    <td className="py-1.5">{line.description}{line.quantity > 1 ? ` × ${line.quantity}` : ''}</td>
                    <td className="py-1.5 text-right">{line.kind === 'credit' ? '− ' : ''}{money(line.amount_minor, data.currency)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <dl className="ml-auto max-w-xs space-y-1 text-sm">
              <div className="flex justify-between"><dt className="text-slate-600">Subtotal</dt><dd>{money(data.subtotal_minor, data.currency)}</dd></div>
              <div className="flex justify-between"><dt className="text-slate-600">{data.tax_label}</dt><dd>{money(data.tax_minor, data.currency)}</dd></div>
              {(data.credit_applied_minor ?? 0) > 0 && <div className="flex justify-between"><dt className="text-slate-600">Account credit used</dt><dd>− {money(data.credit_applied_minor ?? 0, data.currency)}</dd></div>}
              <div className="flex justify-between border-t border-slate-200 pt-1 font-semibold"><dt>Total</dt><dd>{money(data.total_minor, data.currency)}</dd></div>
              <div className="flex justify-between"><dt className="text-slate-600">Paid</dt><dd>{money(data.amount_paid_minor, data.currency)}</dd></div>
              {(data.amount_credited_minor ?? 0) > 0 && <div className="flex justify-between"><dt className="text-slate-600">Credit notes</dt><dd>{money(data.amount_credited_minor ?? 0, data.currency)}</dd></div>}
              <div className="flex justify-between font-medium"><dt>Still due</dt><dd>{money(data.amount_due_minor, data.currency)}</dd></div>
            </dl>
            {(data.payments ?? []).length > 0 && (
              <div>
                <h3 className="text-sm font-semibold text-slate-900">Payments received</h3>
                <ul className="mt-1 divide-y divide-slate-100 text-sm">
                  {(data.payments ?? []).map((payment) => (
                    <li key={payment.id} className="flex flex-wrap justify-between gap-2 py-1.5">
                      <span>{formatUtcDate(payment.received_at)} · {humanize(payment.method)}{payment.reference ? ` · ${payment.reference}` : ''}</span>
                      <span>{money(payment.amount_minor, payment.currency)}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {/* Phase B47 (Module 29 §75, §73): the PDF, and telling Umar Techy you paid. */}
            <div className="flex flex-wrap justify-end gap-2">
              <a href={`/api/v1/billing/invoices/${data.id}/pdf`} className="inline-flex items-center rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-50">Download PDF</a>
              {onPaid && data.status === 'open' && data.amount_due_minor > 0 && <Button size="sm" variant="primary" onClick={() => onPaid(data)}>I have paid</Button>}
            </div>
          </div>
        )}
      </QueryState>
    </Dialog>
  );
}

export default function Overview() {
  const access = useAccess();
  const canManage = access.can('billing.manage');
  const billing = useApi<{ data: Billing }>('/billing');
  const invoices = useApi<unknown>('/billing/invoices');
  const usage = useApi<{ data: Record<string, UsageEntry> }>('/subscription/usage');
  const plan = useApi<{ data: Subscription }>('/subscription');
  const [openInvoice, setOpenInvoice] = useState<Invoice | null>(null);
  const [cancelling, setCancelling] = useState(false);
  const [interval, setIntervalChoice] = useState<string | null>(null);
  const [changingPlan, setChangingPlan] = useState(false);
  const [paying, setPaying] = useState<InvoiceDetail | null>(null);
  const [version, setVersion] = useState(0);
  const refresh = () => {
    billing.reload();
    invoices.reload();
    plan.reload();
    setVersion((n) => n + 1);
  };
  const cancelForm = useForm({ reason: '' });
  const { busy, run } = useAction();

  const invoiceRows = invoices.data === null ? null : readPage<Invoice>(invoices.data).rows;
  const features = (plan.data?.data.package?.entitlements ?? []).filter((entitlement) => entitlement.type === 'feature');

  async function cancel(event: FormEvent) {
    event.preventDefault();
    const done = await cancelForm.submit(() => adminFetch('/billing/cancel', { method: 'POST', body: { reason: cancelForm.values.reason === '' ? null : cancelForm.values.reason } }), 'Your subscription ends at the end of the period.');
    if (done !== undefined) {
      setCancelling(false);
      billing.reload();
    }
  }

  return (
    <AdminPage title="Plan and billing" description="Your package, what it includes, and your invoices from the platform.">
      <QueryState state={billing} lines={5}>
        {({ data }) => {
          const subscription = data.subscription;
          const currency = subscription.currency;
          const other = subscription.billing_interval === 'monthly' ? 'yearly' : 'monthly';

          return (
            <div className="space-y-4">
              {data.balance.overdue_invoices > 0 && (
                <div role="alert" className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                  {money(data.balance.amount_due_minor, currency)} is overdue.
                  {subscription.grace_period_ends_at && ` Your store will be suspended on ${formatUtcDate(subscription.grace_period_ends_at)} (UTC) unless it is paid.`}
                </div>
              )}

              <div className="grid gap-4 md:grid-cols-2">
                <Card title="Current package">
                  <p className="text-xl font-semibold text-slate-900">{subscription.package.name}</p>
                  <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                    <StatusBadge status={subscription.status} />
                    <span>billed {subscription.billing_interval}</span>
                    {!subscription.grants_access && <Badge tone="red">Access restricted</Badge>}
                  </p>
                  {subscription.trial_ends_at && subscription.status === 'trialing' && <p className="mt-2 text-sm text-slate-700">Trial ends {formatUtcDate(subscription.trial_ends_at)} (UTC).</p>}
                  {subscription.scheduled_package && (
                    <p role="status" className="mt-2 rounded-md bg-amber-50 p-2 text-sm text-amber-900">
                      Moves to {subscription.scheduled_package.name} at the end of the period you have paid for.{' '}
                      {canManage && (
                        <button type="button" className="font-medium underline" disabled={busy === 'keep'} onClick={() => run('keep', () => adminFetch('/billing/plan-change', { method: 'DELETE' }), { success: `You stay on ${subscription.package.name}.` }).then((r) => r !== undefined && refresh())}>
                          Keep {subscription.package.name}
                        </button>
                      )}
                    </p>
                  )}
                  {canManage && !subscription.cancel_at_period_end && (
                    <div className="mt-3 flex flex-wrap gap-2">
                      {/* Phase B47 (Module 29 §47–49): with a preview of the cost and what changes. */}
                      <Button size="sm" variant="primary" onClick={() => setChangingPlan(true)}>Change plan</Button>
                      <Button size="sm" onClick={() => setIntervalChoice(other)}>Switch to {other} billing</Button>
                    </div>
                  )}
                </Card>

                <Card title={subscription.cancel_at_period_end ? 'Subscription ends' : 'Next charge'}>
                  {subscription.cancel_at_period_end || !data.upcoming ? (
                    <p className="text-xl font-semibold text-slate-900">{formatUtcDate(subscription.current_period_ends_at)}</p>
                  ) : (
                    <>
                      <p className="text-xl font-semibold text-slate-900">{money(data.upcoming.total_minor, data.upcoming.currency)}</p>
                      <p className="mt-1 text-sm text-slate-600">on {formatUtcDate(data.upcoming.period_start)}</p>
                    </>
                  )}
                  <p className="mt-1 text-xs text-slate-500">Billing dates are UTC dates.</p>
                  {canManage && (
                    <div className="mt-3">
                      {subscription.cancel_at_period_end ? (
                        <Button size="sm" variant="primary" busy={busy === 'resume'} onClick={() => run('resume', () => adminFetch('/billing/resume', { method: 'POST' }), { success: 'Your subscription continues.' }).then((result) => result !== undefined && billing.reload())}>
                          Keep my subscription
                        </Button>
                      ) : (
                        <Button size="sm" onClick={() => { cancelForm.reset({ reason: '' }); setCancelling(true); }}>Cancel subscription</Button>
                      )}
                    </div>
                  )}
                </Card>
              </div>
            </div>
          );
        }}
      </QueryState>

      <div className="mt-4 space-y-4">
        <Card title="Usage" description="What your package limits, and how much is used.">
          {usage.error ? (
            <ErrorPanel message={usage.error} onRetry={usage.reload} />
          ) : usage.data === null ? (
            <Skeleton lines={2} />
          ) : Object.keys(usage.data.data).length === 0 ? (
            <p className="text-sm text-slate-600">Your package sets no usage limits.</p>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2">
              {Object.entries(usage.data.data).map(([key, entry]) => (
                <UsageMeter key={key} label={labelFor(USAGE_LABELS, key)} current={entry.current} limit={entry.unlimited ? null : entry.limit} />
              ))}
            </div>
          )}
        </Card>

        {features.length > 0 && (
          <Card title="What your package includes">
            <ul className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
              {features.map((feature) => (
                <li key={feature.key} className="flex items-center justify-between gap-3 border-b border-slate-100 py-1">
                  <span>{labelFor(FEATURE_LABELS, feature.key)}</span>
                  <Badge tone={feature.enabled ? 'green' : 'neutral'}>{feature.enabled ? 'Included' : 'Not included'}</Badge>
                </li>
              ))}
            </ul>
            <p className="mt-3 text-sm text-slate-600">To move to another package, use “Change plan” above: you see what it costs and what changes first.</p>
          </Card>
        )}

        <Card title="Invoices">
          {invoices.error ? <ErrorPanel message={invoices.error} onRetry={invoices.reload} /> : invoiceRows === null ? <Skeleton lines={3} /> : <InvoiceTable invoices={invoiceRows} onOpen={setOpenInvoice} />}
        </Card>

        <BillingAccountCards version={version} />
      </div>

      {openInvoice && (
        <InvoiceDialog
          invoice={openInvoice}
          onClose={() => setOpenInvoice(null)}
          onPaid={canManage ? (detail) => { setOpenInvoice(null); setPaying(detail); } : undefined}
        />
      )}
      {paying && <PaymentNoticeDialog invoice={paying} onClose={() => setPaying(null)} onDone={() => { setPaying(null); refresh(); }} />}
      {changingPlan && <PlanChangeDialog onClose={() => setChangingPlan(false)} onDone={() => { setChangingPlan(false); refresh(); }} />}

      <Dialog open={cancelling} title="Cancel your subscription?" description="Your store keeps working until the end of the period you have paid for. After that it closes to customers. You can change your mind until then." onClose={() => setCancelling(false)} busy={cancelForm.busy}>
        <form onSubmit={cancel} className="space-y-4" noValidate>
          <FormError message={cancelForm.formError} />
          <TextAreaField label="Why are you leaving?" optional rows={3} value={cancelForm.values.reason} onChange={(v) => cancelForm.set('reason', v)} error={cancelForm.errors.reason} maxLength={500} />
          <div className="flex justify-end gap-2">
            <Button onClick={() => setCancelling(false)} disabled={cancelForm.busy} data-autofocus>Keep my subscription</Button>
            <Button type="submit" variant="danger" busy={cancelForm.busy} busyLabel="Cancelling…">Cancel at the end of the period</Button>
          </div>
        </form>
      </Dialog>

      <ConfirmDialog
        open={interval !== null}
        title={`Switch to ${interval} billing?`}
        confirmLabel={`Switch to ${interval}`}
        variant="primary"
        busy={busy === 'interval'}
        onClose={() => setIntervalChoice(null)}
        onConfirm={() =>
          interval &&
          run('interval', () => adminFetch('/billing/interval', { method: 'PUT', body: { billing_interval: interval } }), { success: 'Billing interval changed.' }).then((result) => {
            if (result !== undefined) {
              setIntervalChoice(null);
              billing.reload();
            }
          })
        }
      >
        <p>The change applies from your next billing period. The amount of your next invoice is shown here once the change is saved.</p>
      </ConfirmDialog>
    </AdminPage>
  );
}
