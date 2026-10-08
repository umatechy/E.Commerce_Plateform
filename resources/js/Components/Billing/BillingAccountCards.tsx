import Badge from '@/Components/ui/Badge';
import { Card, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { money } from '@/lib/money';
import { formatUtcDate } from '@/lib/datetime';
import { NOTICE_METHODS } from './PaymentNoticeDialog';

/**
 * Phase B47 (Module 29 §43, §45, §79): the store's account credit, the
 * payments it reported and their review, and its credit notes as PDFs.
 */
type Credit = { currency: string; balance_minor: number; movements: { amount_minor: number; currency: string; source: string; note: string | null; at: string }[] };
type Notice = { id: string; invoice: string | null; amount_minor: number; currency: string; method: string; reference: string; paid_on: string; status: string; rejection_reason: string | null };
type Note = { id: string; number: string; invoice: string | null; settlement: string; reason: string; total_minor: number; currency: string; issued_at: string | null };

const SETTLEMENT: Record<string, string> = { refund: 'Paid back', account_credit: 'Account credit', reduce_balance: 'Less to pay' };
const NOTICE_TONE: Record<string, 'amber' | 'green' | 'red'> = { pending: 'amber', approved: 'green', rejected: 'red' };
const NOTICE_LABEL: Record<string, string> = { pending: 'Being checked', approved: 'Confirmed', rejected: 'Not confirmed' };

export default function BillingAccountCards({ version }: { version: number }) {
  const credit = useApi<{ data: Credit }>('/billing/credit', { v: String(version) });
  const notices = useApi<{ data: Notice[] }>('/billing/payment-notices', { v: String(version) });
  const notes = useApi<{ data: Note[] }>('/billing/credit-notes', { v: String(version) });
  const methodLabel = (m: string) => NOTICE_METHODS.find((x) => x.value === m)?.label ?? m;

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Account credit" description="Credit from Umar Techy pays towards your next invoices first.">
        {credit.error ? <ErrorPanel message={credit.error} onRetry={credit.reload} /> : !credit.data ? <Skeleton lines={2} /> : (
          <>
            <p className="text-xl font-semibold text-slate-900">{money(credit.data.data.balance_minor, credit.data.data.currency)}</p>
            {credit.data.data.movements.length > 0 && (
              <ul className="mt-2 divide-y divide-slate-100 text-sm">
                {credit.data.data.movements.slice(0, 5).map((m, i) => (
                  <li key={i} className="flex justify-between gap-2 py-1"><span>{formatUtcDate(m.at)} · {m.note ?? m.source}</span><span>{m.amount_minor > 0 ? '+' : '−'} {money(Math.abs(m.amount_minor), m.currency)}</span></li>
                ))}
              </ul>
            )}
          </>
        )}
      </Card>

      <Card title="Payments you reported">
        {notices.error ? <ErrorPanel message={notices.error} onRetry={notices.reload} /> : !notices.data ? <Skeleton lines={2} /> : notices.data.data.length === 0 ? (
          <p className="text-sm text-slate-600">None yet. After paying an invoice by transfer, open it and choose “I have paid”.</p>
        ) : (
          <ul className="divide-y divide-slate-100 text-sm">
            {notices.data.data.map((n) => (
              <li key={n.id} className="py-1.5">
                <span className="flex flex-wrap items-center justify-between gap-2">
                  <span>{n.invoice} · {methodLabel(n.method)} · {n.reference}</span>
                  <span className="flex items-center gap-2">{money(n.amount_minor, n.currency)} <Badge tone={NOTICE_TONE[n.status] ?? 'neutral'}>{NOTICE_LABEL[n.status] ?? n.status}</Badge></span>
                </span>
                {n.rejection_reason && <span className="block text-xs text-red-700">{n.rejection_reason}</span>}
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title="Credit notes" className="lg:col-span-2">
        {notes.error ? <ErrorPanel message={notes.error} onRetry={notes.reload} /> : !notes.data ? <Skeleton lines={2} /> : notes.data.data.length === 0 ? (
          <p className="text-sm text-slate-600">No credit notes.</p>
        ) : (
          <ul className="divide-y divide-slate-100 text-sm">
            {notes.data.data.map((n) => (
              <li key={n.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                <span>{n.number} · invoice {n.invoice} · {SETTLEMENT[n.settlement] ?? n.settlement} · {n.reason}</span>
                <span className="flex items-center gap-3">{money(n.total_minor, n.currency)} <a href={`/api/v1/billing/credit-notes/${n.id}/pdf`} className="text-indigo-700 underline">PDF<span className="sr-only"> of {n.number}</span></a></span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
