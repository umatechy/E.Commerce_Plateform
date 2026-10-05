import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import AttributeFilters, { type FilterFacet } from '@/Components/Storefront/AttributeFilters';
import SpecificationsCard from '@/Components/Catalog/SpecificationsCard';
import AttributeTranslationsDialog from '@/Components/Catalog/AttributeTranslationsDialog';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B41: storefront attribute filters and the product specifications card. */
beforeEach(() => setPage(owner, '/products', { storefront: { language: { current: 'en' } } }));

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const facets: FilterFacet[] = [
  { key: 'ram', name: 'RAM', type: 'select', unit: null, options: [{ slug: '8-gb', value: '8 GB', color_code: null, count: 2, selected: true }, { slug: '16-gb', value: '16 GB', color_code: null, count: 1, selected: false }] },
  { key: 'colour', name: 'Colour', type: 'color', unit: null, options: [{ slug: 'black', value: 'Black', color_code: '#000000', count: 3, selected: false }] },
  { key: 'screen', name: 'Screen', type: 'numeric', unit: 'inch', range: { min: 6.1, max: 6.7 }, selected: null },
];

describe('AttributeFilters', () => {
  it('adds and removes choices, swatches and ranges in the address form', () => {
    const onChange = vi.fn();
    render(<AttributeFilters facets={facets} onChange={onChange} />);

    expect(screen.getByText('(2)')).toBeTruthy();
    fireEvent.click(screen.getByLabelText(/16 GB/));
    expect(onChange).toHaveBeenLastCalledWith('ram', '8-gb,16-gb');
    fireEvent.click(screen.getByLabelText(/8 GB/));
    expect(onChange).toHaveBeenLastCalledWith('ram', undefined);

    fireEvent.click(screen.getByRole('button', { name: /Black/ }));
    expect(onChange).toHaveBeenLastCalledWith('colour', 'black');

    fireEvent.change(screen.getByLabelText('Screen (inch): Min'), { target: { value: '6.5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
    expect(onChange).toHaveBeenLastCalledWith('screen', '6.5-');
  });
});

describe('AttributeTranslationsDialog', () => {
  it('saves the name and every value of one language together', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/attributes/7/translations': () => json(200, { data: {
        languages: [{ code: 'ur', name: 'Urdu', native: 'اردو', dir: 'rtl' }],
        original: { name: 'Colour', values: [{ id: 70, value: 'Black', is_active: true }, { id: 71, value: 'White', is_active: false }] },
        translations: {},
      } }),
      'PUT /attributes/7/translations': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: { languages: [], original: { name: 'Colour', values: [] }, translations: {} } });
      },
    }));
    render(<AttributeTranslationsDialog attribute={{ id: 7, name: 'Colour', key: 'colour', type: 'color' }} canEdit onClose={() => undefined} />);

    fireEvent.change(await screen.findByLabelText(/^Name/), { target: { value: 'رنگ' } });
    fireEvent.change(screen.getByLabelText(/^Black/), { target: { value: 'کالا' } });
    expect(screen.getByText('Not offered now')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Save Urdu' }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toEqual({ locale: 'ur', name: 'رنگ', values: { '70': 'کالا', '71': null } });
  });
});

describe('SpecificationsCard', () => {
  it('shows the category suggestions, marks required ones and saves typed values', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/products/P1/specifications': () => json(200, { data: { values: [{ attribute_id: 2, value: 6.1 }], suggested: [{ attribute_id: 1, is_required: true }, { attribute_id: 2, is_required: false }] } }),
      '/attributes': () => json(200, { data: [
        { id: 1, name: 'RAM', key: 'ram', type: 'select', options: [{ id: 11, value: '8 GB', slug: '8-gb', color_code: null, is_active: true }] },
        { id: 2, name: 'Screen', key: 'screen', type: 'numeric', unit: 'inch', options: [] },
        { id: 3, name: 'Waterproof', key: 'waterproof', type: 'boolean', options: [] },
      ] }),
      'PUT /products/P1/specifications': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: { values: [], suggested: [] } });
      },
    }));
    render(<SpecificationsCard productId="P1" canEdit />);

    const ram = await screen.findByLabelText('RAM (required)');
    expect((screen.getByLabelText(/Screen/) as HTMLInputElement).value).toBe('6.1');
    fireEvent.change(ram, { target: { value: '11' } });
    fireEvent.change(screen.getByLabelText('Add an attribute'), { target: { value: '3' } });
    fireEvent.change(screen.getByLabelText('Waterproof'), { target: { value: 'yes' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save specifications' }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toEqual({ specifications: [{ attribute_id: 1, value: 11 }, { attribute_id: 2, value: 6.1 }, { attribute_id: 3, value: true }] });
  });
});
