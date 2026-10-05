import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import ProductBadges from '@/Components/Storefront/ProductBadges';
import Badges from '@/Pages/Catalog/Badges';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B43 (Module 06 §36): badge labels on the storefront and the admin Badges page. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

describe('ProductBadges', () => {
  beforeEach(() => setPage(owner, '/', { storefront: { language: { current: 'en' } } }));

  it('words automatic badges itself and shows the store badge labels, in the given order', () => {
    render(<ProductBadges badges={[
      { type: 'custom', label: 'Handmade', tone: 'success' },
      { type: 'sale', label: null, tone: 'danger', percent: 25 },
      { type: 'low_stock', label: null, tone: 'warning' },
      { type: 'out_of_stock', label: null, tone: 'neutral' },
    ]} />);

    expect(screen.getAllByRole('listitem').map((li) => li.textContent)).toEqual(['Handmade', '25% off', 'Only a few left', 'Sold out']);
    expect(screen.getByText('25% off').className).toContain('bg-sf-error');
  });

  it('speaks Urdu on an Urdu page', () => {
    setPage(owner, '/', { storefront: { language: { current: 'ur' } } });
    render(<ProductBadges badges={[{ type: 'new', label: null, tone: 'accent' }, { type: 'sale', label: null, tone: 'danger', percent: 10 }]} />);

    expect(screen.getAllByRole('listitem').map((li) => li.textContent)).toEqual(['نیا', '10٪ رعایت']);
  });

  it('renders nothing without badges', () => {
    const { container } = render(<ProductBadges badges={[]} />);
    expect(container.innerHTML).toBe('');
  });
});

describe('Badges page', () => {
  beforeEach(() => setPage(owner, '/badges'));

  it('creates a badge with its colour and priority', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/badges': () => json(200, { data: [] }),
      'POST /badges': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(201, { data: {} });
      },
    }));
    render(<Badges />);

    fireEvent.click((await screen.findAllByRole('button', { name: 'Add badge' }))[0]);
    fireEvent.change(screen.getByLabelText('Label'), { target: { value: 'Handmade' } });
    fireEvent.change(screen.getByLabelText('Colour'), { target: { value: 'success' } });
    fireEvent.change(screen.getByLabelText('Priority'), { target: { value: '95' } });
    expect(screen.getAllByText('Handmade').length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('button', { name: 'Save badge' }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toEqual({ label: 'Handmade', tone: 'success', priority: 95, is_active: true });
    expect(screen.getByText(/Sold out/)).toBeTruthy();
  });
});
