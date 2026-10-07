import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import StarterTemplates, { type TemplateRow } from '@/Pages/SuperAdmin/StarterTemplates';
import Stores from '@/Pages/SuperAdmin/Stores';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, routerMock, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B45 follow-up (Module 07 §101, Module 03 §52): starter templates in the Super Admin. */
beforeEach(() => setPage({ ...owner, is_owner: false }, '/super-admin/starter-templates'));

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const builtIn: TemplateRow = {
  key: 'fashion', name: 'Clothing & fashion', summary: 'Women, men…', version: 1, source: 'built_in', business_category: 'fashion',
  is_active: true, offered_to_stores: true, source_store: null, categories: 4, attributes: 7, brands: 0, urdu: 0, stores_using: 3,
};
const saved: TemplateRow = {
  key: 'store_lahore_bakery_ab12', name: 'Lahore bakery', summary: 'Saved from Lahore Sweets.', version: 1, source: 'store', business_category: 'food',
  is_active: true, offered_to_stores: false, source_store: { id: 9, name: 'Lahore Sweets' }, categories: 3, attributes: 2, brands: 1, urdu: 5, stores_using: 0,
};
const categories = { data: [{ value: 'fashion', label: 'Clothing & fashion' }, { value: 'food', label: 'Food, bakery & sweets' }] };

describe('Super Admin starter templates', () => {
  it('lists built-in and saved templates; a built-in one changes only its availability', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/starter-templates': () => json(200, { data: [builtIn, saved] }),
      '/super-admin/business-categories': () => json(200, categories),
      'PUT /super-admin/starter-templates/fashion': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: {} });
      },
    }));
    render(<StarterTemplates />);

    expect(await screen.findByText('From Lahore Sweets')).toBeTruthy();
    expect(screen.getByText('Staff only')).toBeTruthy();
    expect(screen.getByText('4 categories · 7 attributes')).toBeTruthy();
    expect(screen.getByText('3 categories · 2 attributes · 1 brand · Urdu')).toBeTruthy();
    // Built-in templates cannot be deleted.
    expect(screen.getAllByRole('button', { name: /^Delete/ })).toHaveLength(1);

    fireEvent.click(screen.getByRole('button', { name: 'Edit Clothing & fashion' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).queryByLabelText('Name')).toBeNull();
    fireEvent.click(within(dialog).getByLabelText('Offered to store owners'));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save template' }));
    await waitFor(() => expect(sent).toEqual({ is_active: true, offered_to_stores: false }));
  });

  it('deletes a saved template after confirmation', async () => {
    const deleted = vi.fn(() => json(204));
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/starter-templates': () => json(200, { data: [builtIn, saved] }),
      '/super-admin/business-categories': () => json(200, categories),
      'DELETE /super-admin/starter-templates/store_lahore_bakery_ab12': deleted,
    }));
    render(<StarterTemplates />);

    fireEvent.click(await screen.findByRole('button', { name: 'Delete Lahore bakery' }));
    expect(screen.getByText(/keep everything it added/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Delete template' }));
    await waitFor(() => expect(deleted).toHaveBeenCalledTimes(1));
  });
});

describe('Create a store from a saved template', () => {
  beforeEach(() => setPage({ ...owner, is_owner: false }, '/super-admin/stores'));

  it('sends the chosen template with the new store', async () => {
    let body: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/stores': () => json(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } }),
      '/super-admin/business-categories': () => json(200, categories),
      '/super-admin/packages': () => json(200, { data: [{ code: 'basic', name: 'Basic', is_active: true }] }),
      '/super-admin/starter-templates': () => json(200, { data: [builtIn, saved, { ...saved, key: 'off_one', name: 'Switched off', is_active: false }] }),
      'POST /super-admin/stores': (_url, init) => {
        body = JSON.parse(String(init.body));

        return json(201, { data: { id: 12 } });
      },
    }));
    render(<Stores />);

    fireEvent.click(await screen.findByRole('button', { name: 'Create store for a customer' }));
    fireEvent.change(screen.getByLabelText('Store name'), { target: { value: 'Karachi Bakes' } });
    fireEvent.change(screen.getByLabelText('What it sells'), { target: { value: 'food' } });
    await screen.findByRole('option', { name: 'Basic' });
    fireEvent.change(screen.getByLabelText('Package'), { target: { value: 'basic' } });
    await screen.findByRole('option', { name: 'Lahore bakery (saved from a store) — staff only' });
    expect(screen.queryByRole('option', { name: /Switched off/ })).toBeNull();
    fireEvent.change(screen.getByLabelText(/^Template/), { target: { value: 'store_lahore_bakery_ab12' } });
    fireEvent.change(screen.getByLabelText(/Owner’s email/), { target: { value: 'bakes@example.com' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create store' }));

    await waitFor(() => expect(routerMock.visit).toHaveBeenCalledWith('/super-admin/stores/12'));
    expect(body).toMatchObject({ starter_template: true, starter_template_key: 'store_lahore_bakery_ab12' });
  });
});
