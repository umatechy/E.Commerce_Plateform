import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Stars from '@/Components/Storefront/Stars';
import ProductReviews from '@/Components/Storefront/ProductReviews';
import Reviews from '@/Pages/Catalog/Reviews';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';
import type { Shell } from '@/Storefront/types';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Owner decision 15 (Module 05 §27): ratings and reviews. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const shell = { base_path: '/shop/acme', store: { slug: 'acme', currency: 'PKR' } } as unknown as Shell;
const summary = { average: 4.5, count: 2, distribution: { 5: 1, 4: 1, 3: 0, 2: 0, 1: 0 } };

describe('Storefront reviews', () => {
  beforeEach(() => setPage(owner, '/shop/acme/products/rose', { storefront: { language: { current: 'en' } } }));

  it('reads a rating out in words', () => {
    render(<Stars average={4.5} count={2} />);
    expect(screen.getByRole('img', { name: 'Rated 4.5 out of 5' })).toBeTruthy();
    expect(screen.getByText('(2)')).toBeTruthy();
  });

  it('shows published reviews with the store reply and lets a buyer write one', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/storefront/products/rose/reviews': () => json(200, {
        data: [{ id: 1, rating: 5, title: 'Lovely', body: 'Lasts all day.', author: 'Sana K.', verified_purchase: true, created_at: '2026-10-07T10:00:00Z', reply: 'Thank you!' }],
        meta: { current_page: 1, last_page: 1, total: 1 }, summary, can_review: true, reason: null, own: null,
      }),
      'POST /storefront/products/rose/reviews': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(201, { data: { id: 2, status: 'pending' } });
      },
    }));
    render(<ProductReviews shell={shell} slug="rose" summary={summary} />);

    expect(await screen.findByText('Lasts all day.')).toBeTruthy();
    expect(screen.getByText('Thank you!')).toBeTruthy();
    expect(screen.getByText(/Verified purchase/)).toBeTruthy();
    expect(screen.getByText('4.5')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Write a review' }));
    fireEvent.change(screen.getByLabelText('Your review'), { target: { value: 'Very good, will buy again.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send review' }));
    expect(await screen.findByText('Choose from 1 to 5 stars.')).toBeTruthy();
    expect(sent).toBeNull();
    fireEvent.click(screen.getByRole('radio', { name: '4 stars' }));
    fireEvent.click(screen.getByRole('button', { name: 'Send review' }));
    await waitFor(() => expect(sent).toEqual({ rating: 4, title: null, body: 'Very good, will buy again.' }));
  });

  it('asks a guest to sign in', async () => {
    vi.stubGlobal('fetch', routeFetch({ '/storefront/products/rose/reviews': () => json(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0 }, summary: null, can_review: false, reason: 'sign_in', own: null }) }));
    render(<ProductReviews shell={shell} slug="rose" summary={null} />);
    expect(await screen.findByText(/to review a product you bought/)).toBeTruthy();
    expect(screen.getByText('No reviews yet.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Write a review' })).toBeNull();
  });
});

describe('Admin reviews', () => {
  beforeEach(() => setPage(owner, '/reviews'));

  it('lists waiting reviews and publishes one', async () => {
    let published = false;
    vi.stubGlobal('fetch', routeFetch({
      '/reviews': () => json(200, {
        data: [{ id: 7, rating: 2, title: null, body: 'Smell faded quickly.', author: 'Bilal A.', status: 'pending', verified_purchase: true, reply: null, created_at: '2026-10-07T10:00:00Z', product: { id: 'p1', name: 'Rose Attar', slug: 'rose' } }],
        meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 }, counts: { pending: 1 },
      }),
      'PUT /reviews/7/status': (_url, init) => {
        published = JSON.parse(String(init.body)).status === 'approved';

        return json(200, { data: { id: 7, status: 'approved' } });
      },
    }));
    render(<Reviews />);

    expect((await screen.findAllByText('Smell faded quickly.')).length).toBeGreaterThan(0);
    expect(screen.getByRole('option', { name: 'Waiting (1)' })).toBeTruthy();
    fireEvent.click(screen.getAllByRole('button', { name: 'Publish review 7' })[0]);
    await waitFor(() => expect(published).toBe(true));
  });
});
