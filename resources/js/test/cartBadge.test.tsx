import { afterEach, describe, expect, it, vi } from 'vitest';
import { cartCount, forgetCart, onCartCount, storefrontFetch } from '@/Storefront/api';
import { json } from '@/test/inertiaMock';
import type { Shell } from '@/Storefront/types';

/** UI-4: the header's cart badge counts the items of the last cart the API sent back. */
const shell = { base_path: '/shop/acme', store: { slug: 'acme', name: 'Acme' } } as unknown as Shell;

afterEach(() => {
  vi.unstubAllGlobals();
  window.localStorage.clear();
});

describe('cart badge', () => {
  it('counts the quantities of a returned cart, tells the header, and empties when the cart is forgotten', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => json(201, { data: { items: [{ quantity: 2 }, { quantity: 1 }], subtotal_minor: 9000, guest_token: 'tok' } })));
    const told = vi.fn();
    const stop = onCartCount(told);

    await storefrontFetch(shell, '/cart/items', { method: 'POST', body: { product: 'p1', quantity: 2 } });
    expect(cartCount(shell)).toBe(3);
    expect(told).toHaveBeenCalled();

    forgetCart(shell);
    expect(cartCount(shell)).toBe(0);
    stop();
  });

  it('leaves the count alone for answers that are not a cart', async () => {
    window.localStorage.setItem('storefront:acme:cart-count', '4');
    vi.stubGlobal('fetch', vi.fn(async () => json(200, { data: { id: 'x', name: 'Not a cart' } })));
    await storefrontFetch(shell, '/storefront/products/x');
    expect(cartCount(shell)).toBe(4);
  });
});
