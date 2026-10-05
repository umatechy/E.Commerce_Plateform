import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Categories from '@/Pages/Catalog/Categories';
import Register from '@/Pages/Auth/Register';
import { describeResult } from '@/Components/Catalog/StarterTemplateDialog';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B45 (Module 07 §20, §105–106): starter templates. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const templates = {
  data: [
    { key: 'fashion', name: 'Clothing & fashion', version: 1, summary: 'Women, men…', categories: 4, attributes: 7, brands: 0 },
    { key: 'electronics', name: 'Mobiles & electronics', version: 1, summary: 'Phones…', categories: 5, attributes: 7, brands: 8 },
  ],
  recommended: 'electronics',
  history: [],
};

const preview = {
  key: 'electronics',
  name: 'Mobiles & electronics',
  summary: 'Phones, tablets, laptops.',
  categories: [
    { name: 'Mobile phones', description: 'Smartphones.', exists: true, children: [], attributes: [{ key: 'storage', name: 'Storage', filter: true, required: false }, { key: 'condition', name: 'Condition', filter: true, required: true }] },
    { name: 'Accessories', description: 'Chargers.', exists: false, children: [{ name: 'Power banks', exists: false }], attributes: [] },
  ],
  attributes: [
    { key: 'storage', name: 'Storage', type: 'select', unit: null, values: ['32 GB', '64 GB', '128 GB', '256 GB', '512 GB', '1 TB', '2 TB', '4 TB', '8 TB', '16 TB'], exists: false, kept: false },
    { key: 'condition', name: 'Condition', type: 'select', unit: null, values: ['New', 'Used'], exists: true, kept: true },
  ],
  brands: [{ name: 'Samsung', exists: false }],
  theme: { key: 'default', name: 'Classic', preferred: false },
  default_sort: 'newest',
  default_sort_is_set: true,
  store_live: true,
};

describe('Start from a template', () => {
  beforeEach(() => setPage(owner, '/catalog/categories'));

  it('previews the store’s template, marks what exists and applies only the chosen extras', async () => {
    let sent: unknown = null;
    let categoriesLoads = 0;
    vi.stubGlobal('fetch', routeFetch({
      '/categories': () => {
        categoriesLoads++;

        return json(200, { data: [] });
      },
      '/starter-templates': () => json(200, templates),
      '/starter-templates/electronics': () => json(200, { data: preview }),
      'POST /starter-templates/electronics/apply': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: { categories_added: 2, attributes_added: 1, values_added: 0, category_attributes_added: 2, brands_added: 1, attributes_kept: ['condition'], theme: { name: 'Classic', published: false }, default_sort: null } });
      },
    }));
    render(<Categories />);

    fireEvent.click(await screen.findByRole('button', { name: 'Start from a template' }));
    expect(await screen.findByText('Phones, tablets, laptops.')).toBeTruthy();
    expect((screen.getByLabelText('What you sell') as HTMLSelectElement).value).toBe('electronics');
    expect(screen.getByRole('option', { name: 'Mobiles & electronics (your store)' })).toBeTruthy();
    expect(screen.getByText('already in your store')).toBeTruthy();
    expect(screen.getByText('yours is kept (different type)')).toBeTruthy();
    expect(screen.getByText(/\+2 more/)).toBeTruthy();
    expect(screen.getByText('Filters: Storage, Condition')).toBeTruthy();
    expect(screen.getByText(/goes into your theme draft/)).toBeTruthy();
    // The store already has its order: the box is off and cannot be turned on.
    expect((screen.getByLabelText(/by default/) as HTMLInputElement).disabled).toBe(true);

    fireEvent.click(screen.getByLabelText('Also add these brands'));
    fireEvent.click(screen.getByRole('button', { name: 'Apply template' }));

    await waitFor(() => expect(sent).toEqual({ theme: true, brands: true, default_sort: false }));
    expect(await screen.findByText(/Kept your own: condition/)).toBeTruthy();
    await waitFor(() => expect(categoriesLoads).toBe(2));
  });

  it('is offered only to staff who manage both categories and attributes', async () => {
    setPage({ ...owner, is_owner: false, permissions: ['categories.manage'] }, '/catalog/categories');
    vi.stubGlobal('fetch', routeFetch({ '/categories': () => json(200, { data: [] }) }));
    render(<Categories />);

    expect(await screen.findByText('No categories yet')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Start from a template' })).toBeNull();
  });

  it('says plainly what was added and where the theme went', () => {
    const base = { categories_added: 17, attributes_added: 7, values_added: 0, category_attributes_added: 22, brands_added: 0, attributes_kept: [], default_sort: 'newest' };
    expect(describeResult({ ...base, theme: { name: 'Boutique', published: true } })).toBe('Template applied: added 17 categories, 7 attributes, 0 values, 22 filters and fields. The Boutique theme is live.');
    expect(describeResult({ ...base, categories_added: 1, theme: null })).toMatch(/^Template applied: added 1 category,/);
  });
});

describe('Sign-up', () => {
  beforeEach(() => setPage(owner, '/register'));

  it('offers the starter template once the customer says what they sell', () => {
    render(<Register businessCategories={[{ value: 'food', label: 'Food, bakery & sweets' }]} />);
    expect(screen.queryByLabelText(/Set up categories and filters/)).toBeNull();
    fireEvent.change(screen.getByLabelText('What will you sell?'), { target: { value: 'food' } });
    const box = screen.getByLabelText(/Set up categories and filters/) as HTMLInputElement;
    expect(box.checked).toBe(true);
    fireEvent.click(box);
    expect(box.checked).toBe(false);
  });
});
