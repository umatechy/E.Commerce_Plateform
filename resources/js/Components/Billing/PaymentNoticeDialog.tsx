import { FormEvent } from 'react';
import Button from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { fromMinor, toMinor } from '@/lib/money';

/**
 * Phase B47 (Module 29 §73–74, §79): tell Umar Techy you paid an invoice by
 * bank transfer, wallet or cash. It counts as paid once the team has checked
 * the transfer.
 */
export const NOTICE_METHODS = [
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'mobile_wallet', label: 'Mobile wallet (JazzCash, Easypaisa)' },
  { value: 'cash', label: 'Cash' },
  { value: 'other', label: 'Other' },
];

export default function PaymentNoticeDialog({ invoice, onClose, onDone }: { invoice: { id: string; number: string; currency: string; amount_due_minor: number }; onClose: () => void; onDone: () => void }) {
  const today = new Date().toISOString().slice(0, 10);
  const form = useForm({ amount: fromMinor(invoice.amount_due_minor, invoice.currency), method: 'bank_transfer', reference: '', paid_on: today, note: '' });
  const v = form.values;

  async function save(event: FormEvent) {
    event.preventDefault();
    const amount = toMinor(v.amount, invoice.currency);
    const body = { amount_minor: amount, method: v.method, reference: v.reference, paid_on: v.paid_on, note: v.note === '' ? null : v.note };
    if ((await form.submit(() => adminFetch(`/billing/invoices/${invoice.id}/payment-notices`, { method: 'POST', body }), 'Thank you. We will confirm your payment shortly.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={`I have paid ${invoice.number}`} description="The invoice counts as paid once the Umar Techy team has checked your payment." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={`Amount (${invoice.currency})`} inputMode="decimal" value={v.amount} onChange={(x) => form.set('amount', x)} error={form.errors.amount_minor} required />
          <SelectField label="Paid by" value={v.method} onChange={(x) => form.set('method', x)} options={NOTICE_METHODS} error={form.errors.method} />
          <TextField label="Transaction or reference number" value={v.reference} onChange={(x) => form.set('reference', x)} error={form.errors.reference} required maxLength={128} />
          <TextField label="Paid on" type="date" max={today} value={v.paid_on} onChange={(x) => form.set('paid_on', x)} error={form.errors.paid_on} required />
        </div>
        <TextAreaField label="Note" optional rows={2} value={v.note} onChange={(x) => form.set('note', x)} error={form.errors.note} maxLength={500} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Sending…">Send</Button>
        </div>
      </form>
    </Dialog>
  );
}
