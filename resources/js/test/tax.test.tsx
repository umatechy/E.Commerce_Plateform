import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import Tax, { percentToBps } from '@/Pages/Settings/Tax';
import { ratePercent } from '@/lib/orders';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B46 (gap G3, owner decision 1): the store's tax page. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const settings = { enabled: false, prices_include_tax: false, based_on: 'shipping', shipping_taxable: false, discount_basis: 'before_tax', rounding: 'line', label: 'Tax' };

describe('percentages', () => {
  it('turn into basis points and back without floating point surprises', () => {
    expect([percentToBps('17'), percentToBps('16.5'), percentToBps('0.25'), percentToBps('16,5 %'), percentToBps('100')]).toEqual([1700, 1650, 25, 1650, 10000]);
    expect([percentToBps('100.01'), percentToBps('1.234'), percentToBps('-1'), percentToBps('abc')]).toEqual([null, null, null, null]);
    expect([ratePercent(1700), ratePercent(1650), ratePercent(25), ratePercent(0)]).toEqual(['17', '16.5', '0.25', '0']);
  });
});

describe('Tax page', () => {
  beforeEach(() => setPage(owner, '/settings/tax'));

  it('says no rate is built in and keeps tax off until there is a rate', async () => {
    vi.stubGlobal('fetch', routeFetch({ '/tax': () => json(200, { data: { settings, classes: [], rates: [], store_country: 'PK' } }) }));
    render(<Tax />);

    expect(await screen.findByText(/No tax rates come with the platform/)).toBeTruthy();
    expect((screen.getByLabelText('Charge tax') as HTMLInputElement).disabled).toBe(true);
    expect(screen.getByText('Add at least one active rate first.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Add rate' })).toBeNull();
  });

  it('lists classes and rates and adds a rate as basis points', async () => {
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/tax': () => json(200, { data: {
        settings: { ...settings, enabled: true },
        classes: [{ id: 1, name: 'Standard', description: null, is_default: true, rates_count: 1, products_count: 3 }],
        rates: [{ id: 9, tax_class_id: 1, name: 'Sales tax', country: 'PK', region: null, rate_bps: 1700, is_active: true, starts_on: null, ends_on: null }],
        store_country: 'PK',
      } }),
      'POST /tax/rates': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(201, { data: {} });
      },
    }));
    render(<Tax />);

    expect((await screen.findAllByText('Sales tax')).length).toBeGreaterThan(0);
    expect(screen.getAllByText('17 %').length).toBeGreaterThan(0);
    expect(screen.getAllByText('PK (whole country)').length).toBeGreaterThan(0);
    expect(screen.getAllByText('3 + all without a class').length).toBeGreaterThan(0);

    fireEvent.click(screen.getByRole('button', { name: 'Add rate' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Provincial' } });
    fireEvent.change(within(dialog).getByLabelText('Rate (%)'), { target: { value: '120' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save rate' }));
    expect(await within(dialog).findByText(/A percentage from 0 to 100/)).toBeTruthy();
    expect(sent).toBeNull();

    fireEvent.change(within(dialog).getByLabelText('Rate (%)'), { target: { value: '0.5' } });
    fireEvent.change(within(dialog).getByLabelText(/^Region/), { target: { value: 'Punjab' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save rate' }));
    await waitFor(() => expect(sent).toMatchObject({ name: 'Provincial', rate_bps: 50, tax_class_id: 1, country: 'PK', region: 'Punjab', is_active: true }));
  });
});
