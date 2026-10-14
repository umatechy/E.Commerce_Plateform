import { FormEvent, useState } from 'react';
import Button from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError } from '@/Components/ui/Form';
import { QueryState } from '@/Components/ui/Page';
import AttemptStatus from '@/Components/Payments/AttemptStatus';
import PayerFields, { type PayerValues } from '@/Components/Payments/PayerFields';
import { useApi } from '@/lib/useApi';
import { adminErrorMessage, adminFetch, fieldErrors, wasCancelled } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { isInFlight, normalizeMobile, usePoll, validCnicLast6, type Attempt, type PayerFieldDef } from '@/lib/payments';

/**
 * Phase B48 (G21 "card gateway"; Module 29 §73): pay an Umar Techy invoice
 * from a JazzCash or Easypaisa wallet. The owner approves on the phone; the
 * invoice is marked paid only when the provider has confirmed the payment
 * to the server.
 */
export type OnlineInvoicePayment = {
  invoice: { id: string; number: string; status: string; amount_due_minor: number; currency: string };
  options: { provider: string; label: string; payer_fields: PayerFieldDef[] }[];
  can_pay: boolean;
  attempt: Attempt | null;
};

export default function PayOnlineDialog({ invoiceId, onClose, onPaid }: { invoiceId: string; onClose: () => void; onPaid: () => void }) {
  const state = useApi<{ data: OnlineInvoicePayment }>(`/billing/invoices/${invoiceId}/online-payment`);
  const [provider, setProvider] = useState<string | null>(null);
  const [payer, setPayer] = useState<PayerValues>({ mobile: '', cnic_last6: '' });
  const [errors, setErrors] = useState<Partial<Record<keyof PayerValues, string>>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState<Attempt | null>(null);
  const [busy, setBusy] = useState(false);
  const data = state.data?.data;
  const current = attempt ?? data?.attempt ?? null;
  const watching = current !== null && isInFlight(current.state);

  usePoll(async () => {
    const res = await adminFetch<{ data: OnlineInvoicePayment }>(`/billing/invoices/${invoiceId}/online-payment`);
    setAttempt(res.data.attempt);
  }, watching);

  async function pay(event: FormEvent, option: OnlineInvoicePayment['options'][number]) {
    event.preventDefault();
    const next: typeof errors = {};
    if (normalizeMobile(payer.mobile) === null) next.mobile = 'Enter a mobile number such as 03001234567.';
    if (option.payer_fields.some((f) => f.key === 'cnic_last6') && !validCnicLast6(payer.cnic_last6)) next.cnic_last6 = 'Enter the last 6 digits.';
    setErrors(next);
    if (Object.keys(next).length > 0) return;
    setBusy(true);
    setFormError(null);
    try {
      const res = await adminFetch<{ data: Attempt }>(`/billing/invoices/${invoiceId}/online-payment`, { method: 'POST', body: { provider: option.provider, payer } });
      setAttempt(res.data);
    } catch (e) {
      if (!wasCancelled(e)) {
        const fe = fieldErrors(e);
        setErrors({ mobile: fe['payer.mobile'], cnic_last6: fe['payer.cnic_last6'] });
        setFormError(adminErrorMessage(e));
      }
    } finally {
      setBusy(false);
    }
  }

  const paid = current?.state === 'paid';

  return (
    <Dialog open title="Pay online" description={data ? `Invoice ${data.invoice.number} · ${money(data.invoice.amount_due_minor, data.invoice.currency)} due` : undefined} onClose={paid ? onPaid : onClose} busy={busy}>
      <QueryState state={state}>
        {({ data: d }) => {
          const option = d.options.find((o) => o.provider === provider) ?? d.options[0];
          const label = d.options.find((o) => o.provider === current?.provider)?.label ?? current?.provider ?? '';
          const showForm = !paid && !watching && current?.state !== 'review' && d.invoice.status === 'open' && option !== undefined;

          return (
            <div className="space-y-4">
              {current && <AttemptStatus attempt={current} providerLabel={label} />}
              {d.options.length === 0 && !current && (
                <p className="text-sm text-slate-700">Online payment is not available yet. Pay by bank transfer and tell us with “I have paid”.</p>
              )}
              {showForm && option && (
                <form onSubmit={(event) => pay(event, option)} className="space-y-4" noValidate>
                  <FormError message={formError} />
                  {d.options.length > 1 && (
                    <fieldset>
                      <legend className="text-sm font-medium text-slate-700">Pay with</legend>
                      <div className="mt-2 flex flex-wrap gap-2">
                        {d.options.map((o) => (
                          <label key={o.provider} className={`flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm ${option.provider === o.provider ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-slate-300'}`}>
                            <input type="radio" name="provider" checked={option.provider === o.provider} onChange={() => setProvider(o.provider)} />
                            {o.label}
                          </label>
                        ))}
                      </div>
                    </fieldset>
                  )}
                  <PayerFields fields={option.payer_fields} values={payer} onChange={setPayer} errors={errors} />
                  <div className="flex justify-end gap-2">
                    <Button onClick={onClose} disabled={busy}>Cancel</Button>
                    <Button type="submit" variant="primary" busy={busy} busyLabel="Sending…">
                      {current ? 'Try again' : `Pay ${money(d.invoice.amount_due_minor, d.invoice.currency)}`}
                    </Button>
                  </div>
                </form>
              )}
              {(paid || watching || current?.state === 'review') && (
                <div className="flex justify-end">
                  <Button variant={paid ? 'primary' : 'secondary'} onClick={paid ? onPaid : onClose}>{paid ? 'Done' : 'Close — it keeps going'}</Button>
                </div>
              )}
            </div>
          );
        }}
      </QueryState>
    </Dialog>
  );
}
