import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import { formatMoney } from '@/lib/money';
import { errorMessage, storefrontFetch } from '@/Storefront/api';
import type { StorefrontPageProps } from '@/Storefront/types';

export type CartLine = {
  cart_item_id: number;
  product_name: string | null;
  product_slug: string | null;
  variant_options: Record<string, string> | null;
  image_url: string | null;
  quantity: number;
  current_price_minor: number | null;
  line_total_minor: number | null;
  issue: 'unavailable' | 'price_changed' | 'insufficient_stock' | null;
};

export type CartData = {
  currency: string | null;
  items: CartLine[];
  subtotal_minor: number;
  has_issues: boolean;
  coupon_code: string | null;
  promotion: { applied: boolean; discount_amount_minor: number; free_shipping: boolean; error: string | null } | null;
};

const ISSUES: Record<NonNullable<CartLine['issue']>, string> = {
  unavailable: 'No longer available — please remove it.',
  price_changed: 'The price has changed since you added it.',
  insufficient_stock: 'Not enough stock for this quantity.',
};

/** The cart is always read live from the cart API (prices re-checked on every load, Module 11 §13). */
export default function Cart({ storefront, seo }: StorefrontPageProps) {
  const [cart, setCart] = useState<CartData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [coupon, setCoupon] = useState('');
  const base = storefront.base_path;
  const currency = cart?.currency ?? storefront.store.currency;

  const run = useCallback(
    async (path: string, init: { method?: string; body?: unknown } = {}) => {
      setError(null);
      try {
        const res = await storefrontFetch<{ data: CartData }>(storefront, path, init);
        setCart(res.data);
      } catch (e) {
        setError(errorMessage(e));
      }
    },
    [storefront],
  );

  useEffect(() => {
    run('/cart');
  }, [run]);

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <h1 className="mb-6 text-3xl font-bold">Your cart</h1>
      {error && (
        <p className="mb-4 rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">
          {error}
        </p>
      )}
      {!cart && !error && <LoadingState />}
      {cart && cart.items.length === 0 && (
        <EmptyState title="Your cart is empty" description="Find something you like in the catalog." />
      )}
      {cart && cart.items.length > 0 && (
        <div className="grid gap-8 md:grid-cols-[1fr_300px]">
          <ul className="divide-y divide-sf-border">
            {cart.items.map((line) => (
              <li key={line.cart_item_id} className="flex gap-4 py-4">
                <div className="h-20 w-20 flex-none overflow-hidden rounded-sf bg-sf-surface">
                  {line.image_url && <img src={line.image_url} alt="" className="h-full w-full object-cover" />}
                </div>
                <div className="flex-1">
                  {line.product_slug ? (
                    <Link href={`${base}/products/${line.product_slug}`} className="font-medium">
                      {line.product_name}
                    </Link>
                  ) : (
                    <span className="font-medium">{line.product_name ?? 'Unavailable item'}</span>
                  )}
                  {line.variant_options && (
                    <p className="text-sm text-sf-muted">
                      {Object.entries(line.variant_options)
                        .map(([k, v]) => `${k}: ${v}`)
                        .join(' · ')}
                    </p>
                  )}
                  {line.issue && <p className="text-sm text-sf-error">{ISSUES[line.issue]}</p>}
                  <div className="mt-2 flex items-center gap-3 text-sm">
                    <input
                      type="number"
                      min={1}
                      max={999}
                      defaultValue={line.quantity}
                      aria-label="Quantity"
                      onBlur={(e) => {
                        const quantity = Number(e.target.value);
                        if (quantity >= 1 && quantity !== line.quantity) run(`/cart/items/${line.cart_item_id}`, { method: 'PUT', body: { quantity } });
                      }}
                      className="w-16 rounded-sf border border-sf-border px-2 py-1"
                    />
                    <button type="button" onClick={() => run(`/cart/items/${line.cart_item_id}`, { method: 'DELETE' })} className="text-sf-muted underline">
                      Remove
                    </button>
                  </div>
                </div>
                <div className="text-right font-medium">
                  {line.line_total_minor !== null && formatMoney(line.line_total_minor, currency)}
                </div>
              </li>
            ))}
          </ul>

          <aside className="h-fit space-y-4 rounded-sf border border-sf-border p-4">
            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (coupon.trim()) run('/cart/coupon', { method: 'POST', body: { code: coupon.trim() } });
              }}
              className="flex gap-2"
            >
              <input value={coupon} onChange={(e) => setCoupon(e.target.value)} placeholder="Coupon code" aria-label="Coupon code" className="flex-1 rounded-sf border border-sf-border px-2 py-1 text-sm" />
              <button type="submit" className="rounded-sf border border-sf-border px-3 py-1 text-sm">
                Apply
              </button>
            </form>
            {cart.coupon_code && (
              <p className="flex justify-between text-sm">
                <span>Coupon {cart.coupon_code}</span>
                <button type="button" onClick={() => run('/cart/coupon', { method: 'DELETE' })} className="text-sf-muted underline">
                  Remove
                </button>
              </p>
            )}
            <p className="flex justify-between font-semibold">
              <span>Subtotal</span>
              <span>{formatMoney(cart.subtotal_minor, currency)}</span>
            </p>
            {cart.promotion?.applied && cart.promotion.discount_amount_minor > 0 ? (
              <p className="flex justify-between text-sm text-sf-success">
                <span>Discount</span>
                <span>−{formatMoney(cart.promotion.discount_amount_minor, currency)}</span>
              </p>
            ) : null}
            {cart.promotion?.free_shipping && <p className="text-sm text-sf-success">Free shipping applied.</p>}
            {cart.promotion?.error === 'coupon_not_eligible' && <p className="text-sm text-sf-warning">This coupon does not apply to your cart.</p>}
            <p className="text-xs text-sf-muted">Shipping and taxes are calculated at checkout.</p>
            {cart.has_issues ? (
              <p className="text-sm text-sf-error">Resolve the items marked above before checking out.</p>
            ) : (
              <Link href={`${base}/checkout`} className="block rounded-sf bg-sf-primary px-4 py-3 text-center font-semibold text-white">
                Checkout
              </Link>
            )}
          </aside>
        </div>
      )}
    </StoreLayout>
  );
}
