import EmptyState from '@/Components/EmptyState';
import { formatMoney } from '@/lib/money';

export { formatMoney };

/**
 * Module 29 (Phase B23) — a store's platform invoices. Amounts arrive in
 * minor units (ADR-003); they are converted with the currency's own
 * number of decimals, never an assumed 2.
 */
export type Invoice = {
  id: string;
  number: string;
  status: 'open' | 'paid' | 'void' | 'uncollectible';
  is_overdue: boolean;
  currency: string;
  total_minor: number;
  amount_due_minor: number;
  period_start: string;
  period_end: string;
  due_at: string;
};

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString();
}

const STATUS_STYLE: Record<Invoice['status'], string> = {
  open: 'bg-yellow-100 text-yellow-800',
  paid: 'bg-green-100 text-green-800',
  void: 'bg-gray-100 text-gray-700',
  uncollectible: 'bg-red-100 text-red-700',
};

export default function InvoiceTable({ invoices }: { invoices: Invoice[] }) {
  if (invoices.length === 0) {
    return <EmptyState title="No invoices yet" description="Your first invoice is issued a few days before your trial ends." />;
  }

  return (
    <table className="w-full text-left text-sm">
      <thead className="text-gray-500">
        <tr>
          <th className="py-2">Invoice</th>
          <th className="py-2">Period</th>
          <th className="py-2">Due</th>
          <th className="py-2 text-right">Total</th>
          <th className="py-2 text-right">Status</th>
        </tr>
      </thead>
      <tbody>
        {invoices.map((invoice) => (
          <tr key={invoice.id} className="border-t">
            <td className="py-2 font-medium">{invoice.number}</td>
            <td className="py-2">
              {formatDate(invoice.period_start)} – {formatDate(invoice.period_end)}
            </td>
            <td className="py-2">{formatDate(invoice.due_at)}</td>
            <td className="py-2 text-right">{formatMoney(invoice.total_minor, invoice.currency)}</td>
            <td className="py-2 text-right">
              <span className={`rounded px-2 py-0.5 ${STATUS_STYLE[invoice.status]}`}>
                {invoice.is_overdue ? 'overdue' : invoice.status}
              </span>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
