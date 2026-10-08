import { useState } from 'react';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField } from '@/Components/ui/Form';
import { EmptyPanel } from '@/Components/ui/Page';
import { usePagedApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { formatUtcDate } from '@/lib/datetime';

/**
 * Phase B47 (Module 29 §42–43, §73–74, §79, §92): Umar Techy's billing desk —
 * payments stores reported (confirm or reject) and credit notes (approve or
 * reject those waiting for a second person). Every action asks for step-up.
 */
type Notice = {
  id: string;
  store: { id: number; name: string | null };
  invoice: { id: string; number: string; due_minor: number } | null;
  amount_minor: number;
  currency: string;
  method: string;
  reference: string;
  paid_on: string;
  note: string | null;
  status: string;
  rejection_reason: string | null;
};
type Note = {
  id: string;
  number: string | null;
  status: string;
  settlement: string;
  reason: string;
  store: { id: number; name: string | null };
  invoice: string | null;
  total_minor: number;
  currency: string;
  refund_reference: string | null;
  rejection_reason: string | null;
  created_at: string;
  mine: boolean;
};

const METHOD: Record<string, string> = { bank_transfer: 'Bank transfer', mobile_wallet: 'Mobile wallet', cash: 'Cash', other: 'Other' };
const SETTLEMENT: Record<string, string> = { refund: 'Refund', account_credit: 'Account credit', reduce_balance: 'Lower balance' };
const NOTE_STATUS: Record<string, { label: string; tone: 'amber' | 'green' | 'red' }> = {
  pending_approval: { label: 'Waiting for approval', tone: 'amber' },
  issued: { label: 'Issued', tone: 'green' },
  rejected: { label: 'Rejected', tone: 'red' },
};

function RejectDialog({ title, path, onClose, onDone }: { title: string; path: string; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const { busy, run } = useAction();

  return (
    <Dialog open title={title} description="The store sees the reason. You will be asked for your password." onClose={onClose} busy={busy === 'reject'}>
      <div className="space-y-4">
        <FormError message={error} />
        <TextAreaField label="Reason" rows={3} value={reason} onChange={setReason} maxLength={500} required />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={busy === 'reject'}>Cancel</Button>
          <Button variant="danger" busy={busy === 'reject'} disabled={reason.trim() === ''} onClick={() => run('reject', () => adminFetch(path, { method: 'POST', body: { reason } }), { success: 'Rejected.', onError: setError }).then((r) => r !== undefined && onDone())}>
            Reject
          </Button>
        </div>
      </div>
    </Dialog>
  );
}

export function PaymentNotices() {
  const [status, setStatus] = useState('pending');
  const [page, setPage] = useState(1);
  const list = usePagedApi<Notice>('/super-admin/billing/payment-notices', { status, page: String(page) });
  const [rejecting, setRejecting] = useState<Notice | null>(null);
  const { busy, run } = useAction();

  const columns: Column<Notice>[] = [
    { key: 'store', header: 'Store', priority: true, render: (n) => <span><span className="font-medium">{n.store.name}</span><span className="block text-xs text-slate-600">invoice {n.invoice?.number ?? '—'}</span></span> },
    { key: 'paid', header: 'Paid', render: (n) => <span>{METHOD[n.method] ?? n.method} · {n.reference}<span className="block text-xs text-slate-600">on {formatUtcDate(n.paid_on)}{n.note ? ` · ${n.note}` : ''}</span></span> },
    { key: 'amount', header: 'Amount', align: 'right', priority: true, render: (n) => <span>{money(n.amount_minor, n.currency)}{n.invoice && <span className="block text-xs text-slate-600">due {money(n.invoice.due_minor, n.currency)}</span>}</span> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (n) => n.status === 'pending' ? (
        <span className="flex flex-wrap justify-end gap-1">
          <Button size="sm" variant="primary" busy={busy === n.id} onClick={() => run(n.id, () => adminFetch(`/super-admin/billing/payment-notices/${n.id}/approve`, { method: 'POST' }), { success: 'Payment confirmed and recorded.' }).then((r) => r !== undefined && list.reload())}>
            Confirm<span className="sr-only"> payment {n.reference}</span>
          </Button>
          <Button size="sm" onClick={() => setRejecting(n)}>Reject<span className="sr-only"> payment {n.reference}</span></Button>
        </span>
      ) : n.rejection_reason ? <span className="text-xs text-red-700">{n.rejection_reason}</span> : <Badge tone="green">Confirmed</Badge>,
    },
  ];

  return (
    <>
      <div className="mb-3 w-56">
        <SelectField label="Show" value={status} onChange={(s) => { setStatus(s); setPage(1); }} options={[{ value: 'pending', label: 'To confirm' }, { value: 'approved', label: 'Confirmed' }, { value: 'rejected', label: 'Rejected' }]} />
      </div>
      <p className="mb-3 text-sm text-slate-600">Check the transfer in the bank or wallet account first. Confirming records the payment on the invoice.</p>
      <DataTable caption="Payments stores reported" columns={columns} rows={list.rows} rowKey={(n) => n.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title={status === 'pending' ? 'Nothing to confirm' : 'None'} />} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />
      {rejecting && <RejectDialog title={`Reject ${rejecting.reference}?`} path={`/super-admin/billing/payment-notices/${rejecting.id}/reject`} onClose={() => setRejecting(null)} onDone={() => { setRejecting(null); list.reload(); }} />}
    </>
  );
}

export function CreditNotes() {
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const list = usePagedApi<Note>('/super-admin/billing/credit-notes', { status, page: String(page) });
  const [rejecting, setRejecting] = useState<Note | null>(null);
  const { busy, run } = useAction();

  const columns: Column<Note>[] = [
    { key: 'number', header: 'Credit note', priority: true, render: (n) => <span><span className="font-medium">{n.number ?? 'Not issued'}</span><span className="block text-xs text-slate-600">{n.store.name} · invoice {n.invoice}</span></span> },
    { key: 'what', header: 'What', render: (n) => <span>{SETTLEMENT[n.settlement] ?? n.settlement}{n.refund_reference ? ` · ${n.refund_reference}` : ''}<span className="block text-xs text-slate-600">{n.reason}</span></span> },
    { key: 'amount', header: 'Amount', align: 'right', render: (n) => money(n.total_minor, n.currency) },
    { key: 'status', header: 'Status', priority: true, render: (n) => <Badge tone={NOTE_STATUS[n.status]?.tone ?? 'neutral'}>{NOTE_STATUS[n.status]?.label ?? n.status}</Badge> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (n) => n.status === 'pending_approval' ? (
        n.mine ? <span className="text-xs text-slate-600">Another team member approves</span> : (
          <span className="flex flex-wrap justify-end gap-1">
            <Button size="sm" variant="primary" busy={busy === n.id} onClick={() => run(n.id, () => adminFetch(`/super-admin/billing/credit-notes/${n.id}/approve`, { method: 'POST' }), { success: 'Credit note issued.' }).then((r) => r !== undefined && list.reload())}>Approve<span className="sr-only"> credit note for {n.invoice}</span></Button>
            <Button size="sm" onClick={() => setRejecting(n)}>Reject<span className="sr-only"> credit note for {n.invoice}</span></Button>
          </span>
        )
      ) : n.status === 'issued' ? <a href={`/api/v1/super-admin/billing/credit-notes/${n.id}/pdf`} className="text-sm text-indigo-700 underline">PDF<span className="sr-only"> of {n.number}</span></a> : null,
    },
  ];

  return (
    <>
      <div className="mb-3 w-56">
        <SelectField label="Show" value={status} onChange={(s) => { setStatus(s); setPage(1); }} placeholder="All" options={[{ value: 'pending_approval', label: 'Waiting for approval' }, { value: 'issued', label: 'Issued' }, { value: 'rejected', label: 'Rejected' }]} />
      </div>
      <p className="mb-3 text-sm text-slate-600">Create a credit note from an invoice (Invoices tab). Above the approval threshold (Platform settings) a second team member approves it.</p>
      <DataTable caption="Credit notes" columns={columns} rows={list.rows} rowKey={(n) => n.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No credit notes" />} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />
      {rejecting && <RejectDialog title="Reject this credit note?" path={`/super-admin/billing/credit-notes/${rejecting.id}/reject`} onClose={() => setRejecting(null)} onDone={() => { setRejecting(null); list.reload(); }} />}
    </>
  );
}
