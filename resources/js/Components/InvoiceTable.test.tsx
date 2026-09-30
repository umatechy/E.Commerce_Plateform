import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import InvoiceTable, { formatMoney, type Invoice } from './InvoiceTable';

afterEach(cleanup);

const invoice = (overrides: Partial<Invoice> = {}): Invoice => ({
  id: '01JTESTINVOICE0000000000001',
  number: 'INV-000001',
  status: 'open',
  is_overdue: false,
  currency: 'USD',
  total_minor: 3393,
  amount_due_minor: 3393,
  period_start: '2026-03-15T09:00:00+00:00',
  period_end: '2026-04-15T09:00:00+00:00',
  due_at: '2026-03-15T09:00:00+00:00',
  ...overrides,
});

describe('formatMoney', () => {
  it('uses the currency’s own number of decimals', () => {
    expect(formatMoney(3393, 'USD')).toContain('33.93');
    expect(formatMoney(500, 'JPY')).toContain('500');
    expect(formatMoney(500, 'JPY')).not.toContain('5.00');
  });
});

describe('InvoiceTable', () => {
  it('lists each invoice with its total and status', () => {
    render(<InvoiceTable invoices={[invoice(), invoice({ id: '2', number: 'INV-000002', status: 'paid' })]} />);

    expect(screen.getByText('INV-000001')).toBeTruthy();
    expect(screen.getByText('INV-000002')).toBeTruthy();
    expect(screen.getByText('paid')).toBeTruthy();
  });

  it('flags an overdue invoice instead of showing it as merely open', () => {
    render(<InvoiceTable invoices={[invoice({ is_overdue: true })]} />);

    expect(screen.getByText('overdue')).toBeTruthy();
    expect(screen.queryByText('open')).toBeNull();
  });

  it('shows an empty state before the first invoice', () => {
    render(<InvoiceTable invoices={[]} />);

    expect(screen.getByText('No invoices yet')).toBeTruthy();
  });
});
