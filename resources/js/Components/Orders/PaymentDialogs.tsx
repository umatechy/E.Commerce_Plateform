import { FormEvent, useState } from 'react';
import Dialog from '@/Components/ui/Dialog';
import Button from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import { FormError, TextAreaField, TextField } from '@/Components/ui/Form';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { Details, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch, AdminApiError, idempotencyKey } from '@/lib/adminApi';
import { fromMinor, money, toMinor } from '@/lib/money';
import { dateTimeOrDash, formatDateTime } from '@/lib/datetime';
import { PAYMENT_METHOD_LABELS, type Payment, type PaymentBalance, type PaymentTransaction } from '@/lib/orders';
import { RECONCILIATION_LABELS } from '@/lib/payments';

/**
 * Module 12 §68 "Store Admin Payment View": one payment with its
 * transactions, and the two things staff can do to it: record a manual
 * confirmation (cash or bank transfer received) and refund. Both are
 * decided by the server (PaymentService); this only collects the input.
 * Phase B48: online payments also show their attempts, and a refund goes
 * through the provider where it can (else it is made in the provider's
 * portal and recorded here). No gateway credentials are asked for here.
 */
type Action = 'confirm' | 'refund' | null;

function ActionForm({ payment, action, refundable, refundMode, providerLabel, onClose, onDone }: { payment: Payment; action: 'confirm' | 'refund'; refundable: number; refundMode: 'provider' | 'portal' | 'record'; providerLabel: string | null; onClose: () => void; onDone: () => void }) {
  const form = useForm({ amount: action === 'refund' ? fromMinor(refundable, payment.currency) : fromMinor(payment.amount_minor, payment.currency), reference: '', notes: '', provider_reference: '' });
  // One key per opened form: a double click is one refund.
  const [key] = useState(idempotencyKey);
  const [amountError, setAmountError] = useState<string | null>(null);

  async function save(event: FormEvent) {
    event.preventDefault();
    const minor = toMinor(form.values.amount, payment.currency);
    if (minor === null || minor < 1) {
      setAmountError(`Enter an amount above zero, such as 12.50 (${payment.currency}).`);

      return;
    }
    setAmountError(null);
    const saved = await form.submit(async () => {
      try {
        return await adminFetch(
          `/payments/${payment.id}/${action === 'confirm' ? 'manual-confirm' : 'refund'}`,
          {
            method: 'POST',
            body: action === 'confirm'
              ? { amount_minor: minor, reference: form.values.reference, notes: form.values.notes === '' ? null : form.values.notes }
              : { amount_minor: minor, reason: form.values.notes === '' ? null : form.values.notes, idempotency_key: key, provider_reference: form.values.provider_reference === '' ? null : form.values.provider_reference },
          },
        );
      } catch (e) {
        // The server names the most that can be refunded; say it in money.
        if (e instanceof AdminApiError && e.code === 'refund_exceeds_refundable_balance' && typeof e.body.refundable_amount_minor === 'number') {
          setAmountError(`At most ${money(e.body.refundable_amount_minor, payment.currency)} can be refunded.`);
        }
        throw e;
      }
    }, action === 'confirm' ? 'Payment confirmation recorded.' : 'Refund recorded.');
    if (saved !== undefined) onDone();
  }

  return (
    <form onSubmit={save} className="space-y-4 rounded-md border border-slate-200 bg-slate-50 p-4" noValidate>
      <h3 className="text-sm font-semibold text-slate-900">{action === 'confirm' ? 'Record a payment you received' : 'Refund'}</h3>
      <p className="text-sm text-slate-700">
        {action === 'confirm'
          ? 'Use this when the customer paid outside the system, for example cash on delivery or a bank transfer you have seen arrive.'
          : `Returns money to the customer. A refund cannot be undone. Up to ${money(refundable, payment.currency)} can be refunded.`}
      </p>
      {action === 'refund' && refundMode === 'provider' && <p className="rounded-md bg-brand-50 p-2 text-sm text-brand-900">{providerLabel} sends the money back to the customer’s wallet straight away; it is recorded once {providerLabel} confirms.</p>}
      {action === 'refund' && refundMode === 'portal' && <p className="rounded-md bg-amber-50 p-2 text-sm text-amber-900">Make the refund in your {providerLabel} merchant portal first, then record it here with the portal’s reference. {providerLabel} refunds cannot be sent from here yet.</p>}
      <FormError message={form.formError} />
      <TextField label={`Amount (${payment.currency})`} inputMode="decimal" value={form.values.amount} onChange={(v) => form.set('amount', v)} error={amountError ?? form.errors.amount_minor} required data-autofocus />
      {action === 'confirm' && <TextField label="Reference" value={form.values.reference} onChange={(v) => form.set('reference', v)} error={form.errors.reference} required maxLength={255} hint="For example the bank transfer reference or receipt number." />}
      {action === 'refund' && refundMode === 'portal' && <TextField label={`${providerLabel} refund reference`} optional value={form.values.provider_reference} onChange={(v) => form.set('provider_reference', v)} error={form.errors.provider_reference} maxLength={100} />}
      <TextAreaField label={action === 'confirm' ? 'Notes' : 'Reason'} optional rows={2} value={form.values.notes} onChange={(v) => form.set('notes', v)} error={form.errors.notes ?? form.errors.reason} maxLength={500} />
      <div className="flex justify-end gap-2">
        <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
        <Button type="submit" variant={action === 'refund' ? 'danger' : 'primary'} busy={form.busy} busyLabel="Saving…">
          {action === 'confirm' ? 'Record payment' : refundMode === 'provider' ? `Refund through ${providerLabel}` : refundMode === 'portal' ? 'Record the refund' : 'Refund this amount'}
        </Button>
      </div>
    </form>
  );
}

export default function PaymentDrawer({ paymentId, onClose, onChanged }: { paymentId: string; onClose: () => void; onChanged: () => void }) {
  const access = useAccess();
  const payment = useApi<{ data: Payment; meta: PaymentBalance }>(`/payments/${paymentId}`);
  const transactions = useApi<{ data: PaymentTransaction[] }>(`/payments/${paymentId}/transactions`);
  const [action, setAction] = useState<Action>(null);

  const columns: Column<PaymentTransaction>[] = [
    { key: 'when', header: 'When', render: (t) => formatDateTime(t.created_at) },
    { key: 'type', header: 'Type', priority: true, render: (t) => humanize(t.type) },
    { key: 'status', header: 'Status', render: (t) => <StatusBadge status={t.status} /> },
    { key: 'amount', header: 'Amount', align: 'right', priority: true, render: (t) => money(t.amount_minor, t.currency) },
    { key: 'reference', header: 'Reference', render: (t) => t.provider_transaction_reference ?? t.failure_reason ?? '—' },
  ];

  function done() {
    setAction(null);
    payment.reload();
    transactions.reload();
    onChanged();
  }

  const data = payment.data?.data;
  const balance = payment.data?.meta;

  return (
    <Dialog open side title="Payment" description={data?.order ? `Order ${data.order.order_number}` : undefined} onClose={onClose}>
      {payment.error ? (
        <ErrorPanel message={payment.error} onRetry={payment.reload} />
      ) : !data || !balance ? (
        <Skeleton lines={4} />
      ) : (
        <div className="space-y-5">
          <Details
            items={[
              { label: 'Status', value: <StatusBadge status={data.status} /> },
              { label: 'Method', value: PAYMENT_METHOD_LABELS[data.method] ?? humanize(data.method) },
              { label: 'Amount', value: money(data.amount_minor, data.currency) },
              { label: 'Paid', value: money(balance.paid_amount_minor, data.currency) },
              { label: 'Refunded', value: money(balance.refunded_amount_minor, data.currency) },
              { label: 'Created', value: dateTimeOrDash(data.created_at) },
            ]}
          />

          {action === null ? (
            <div className="flex flex-wrap gap-2">
              {access.can('payments.manage') && <Button onClick={() => setAction('confirm')}>Record a payment received</Button>}
              {access.can('payments.refund') && (
                <Button variant="danger" onClick={() => setAction('refund')} disabled={balance.refundable_amount_minor <= 0} title={balance.refundable_amount_minor <= 0 ? 'Nothing has been paid that could be refunded' : undefined}>
                  Refund
                </Button>
              )}
            </div>
          ) : (
            <ActionForm payment={data} action={action} refundable={balance.refundable_amount_minor} refundMode={balance.refund_mode ?? 'record'} providerLabel={balance.provider_label ?? null} onClose={() => setAction(null)} onDone={done} />
          )}

          {(balance.reconciliation ?? []).some((item) => item.status === 'open') && (
            <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
              Reconciliation flagged this payment: {(balance.reconciliation ?? []).filter((item) => item.status === 'open').map((item) => RECONCILIATION_LABELS[item.kind]?.title ?? item.kind).join(', ')}. See Payments → Needs attention.
            </p>
          )}

          {(balance.attempts ?? []).length > 0 && (
            <div>
              <h3 className="mb-2 text-sm font-semibold text-slate-900">Online payment attempts</h3>
              <ol className="space-y-2">
                {(balance.attempts ?? []).map((attempt) => (
                  <li key={attempt.id} className="rounded-md border border-slate-200 p-3 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-medium">{balance.provider_label ?? attempt.provider} · {attempt.payer_hint ?? '—'}</span>
                      <StatusBadge status={attempt.status} />
                    </div>
                    <p className="mt-1 text-xs text-slate-600">
                      {formatDateTime(attempt.created_at)} · reference <span className="font-mono">{attempt.reference}</span>
                      {attempt.provider_transaction_reference && <> · provider <span className="font-mono">{attempt.provider_transaction_reference}</span></>}
                      {attempt.environment === 'sandbox' && ' · sandbox'}
                    </p>
                    {attempt.failure_label && <p className="mt-1 text-xs text-slate-700">{attempt.failure_label}{attempt.provider_code ? ` (code ${attempt.provider_code})` : ''}{attempt.provider_message ? ` — ${attempt.provider_message}` : ''}</p>}
                  </li>
                ))}
              </ol>
            </div>
          )}

          <div>
            <h3 className="mb-2 text-sm font-semibold text-slate-900">Transactions</h3>
            <DataTable
              caption="Payment transactions"
              columns={columns}
              rows={transactions.data?.data ?? null}
              rowKey={(t) => `${t.created_at}-${t.type}-${t.amount_minor}`}
              loading={transactions.loading}
              error={transactions.error}
              onRetry={transactions.reload}
              empty={<p className="text-sm text-slate-600">No transactions yet.</p>}
            />
          </div>
        </div>
      )}
    </Dialog>
  );
}
