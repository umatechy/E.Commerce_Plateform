import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import ProductImportDialog from '@/Components/Catalog/ProductImportDialog';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B40: check the file, see what will happen, confirm, read the report. */
beforeEach(() => setPage(owner, '/products'));

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const ID = '01JIMPORT00000000000000001';

describe('ProductImportDialog', () => {
  it('previews, then imports and reports the rows it skipped', async () => {
    const confirm = vi.fn(() => json(200, { data: { created: 1, updated: 1, variants_created: 2, variants_updated: 0, skipped: [{ row: 5, reason: "Your package's product limit (10) is reached." }] } }));
    vi.stubGlobal('fetch', routeFetch({
      'POST /products/import': () =>
        json(200, {
          data: {
            id: ID,
            totals: { rows: 5, create: 3, update: 1, invalid: 1, variants: 2 },
            rows: [{ row: 4, kind: 'product', label: 'Bad', sku: 'B', status: 'invalid', messages: ['price "abc" is not an amount in PKR.'] }],
            new_brands: ['Gul Ahmed'],
            new_categories: ['Clothing > Women'],
            notices: [],
            columns: [],
          },
        }),
      [`POST /products/import/${ID}/confirm`]: confirm,
    }));
    const onDone = vi.fn();
    render(<ProductImportDialog onClose={() => undefined} onDone={onDone} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check the file' }));
    expect((await screen.findByRole('alert')).textContent).toContain('Choose a CSV file first');

    const input = screen.getByLabelText('CSV file') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['sku,name\nA,One'], 'p.csv', { type: 'text/csv' })] } });
    fireEvent.click(screen.getByRole('button', { name: 'Check the file' }));

    expect(await screen.findByText(/not an amount in PKR/)).toBeTruthy();
    expect(screen.getByText(/Gul Ahmed/)).toBeTruthy();
    expect(screen.getByText(/Clothing > Women/)).toBeTruthy();
    expect(confirm).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Import 4 rows' }));
    expect(await screen.findByText(/Row 5: Your package's product limit/)).toBeTruthy();
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(onDone).toHaveBeenCalled();
  });
});
