import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import Price from './Price';

// Phase B38: Price reads the page language through Inertia.
vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

afterEach(cleanup);

const money = { currency: 'USD', amount_minor: 1999, max_amount_minor: null, compare_at_minor: null, on_sale: false };

describe('Price', () => {
  it('shows a single price', () => {
    render(<Price price={money} />);
    expect(screen.getByText(/19\.99/)).toBeTruthy();
  });

  it('prefixes a price range with "From"', () => {
    render(<Price price={{ ...money, max_amount_minor: 2999 }} />);
    expect(screen.getByText(/From/)).toBeTruthy();
  });

  it('strikes through the regular price when on sale', () => {
    render(<Price price={{ ...money, on_sale: true, compare_at_minor: 2499 }} />);
    expect(screen.getByLabelText('Regular price').textContent).toContain('24.99');
  });

  it('never shows a made-up price when none is set', () => {
    render(<Price price={{ ...money, amount_minor: null }} />);
    expect(screen.getByText('Price on request')).toBeTruthy();
  });
});
