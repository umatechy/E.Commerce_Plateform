import { describe, expect, it } from 'vitest';
import { formatAddress, loginHref } from './account';
import type { Shell } from './types';

const shell = { base_path: '/shop/acme', store: { slug: 'acme' } } as unknown as Shell;

describe('formatAddress', () => {
  it('joins the filled parts in postal order', () => {
    expect(formatAddress({ line1: '12 Mall Road', line2: '', city: 'Lahore', province: 'Punjab', postal_code: '54000', country: 'PK' })).toBe(
      '12 Mall Road, Lahore, Punjab, 54000, PK',
    );
  });

  it('is empty for a missing address', () => {
    expect(formatAddress(null)).toBe('');
  });
});

describe('loginHref', () => {
  it('sends the visitor back to where they were', () => {
    expect(loginHref(shell, '/shop/acme/account/orders')).toBe('/shop/acme/account/login?redirect=%2Fshop%2Facme%2Faccount%2Forders');
  });
});
