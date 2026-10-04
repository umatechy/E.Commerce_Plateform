import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import LoadingState from '@/Components/LoadingState';
import EmptyState from '@/Components/EmptyState';
import { formatMoney } from '@/lib/money';
import { errorMessage, forgetCart, storefrontFetch } from '@/Storefront/api';
import { formatAddress, useCustomer, type Address } from '@/Storefront/account';
import type { StorefrontPageProps } from '@/Storefront/types';
import type { CartData } from './Cart';

type ShippingOption = { id: number; name: string; type: string; cost_minor: number };
type PlacedOrder = { order_number: string; grand_total_minor: number; currency: string; store_credit_minor?: number; payable_minor?: number };

const PAYMENT_METHODS = [
  { value: 'cod', label: 'Cash on delivery' },
  { value: 'bank_transfer', label: 'Bank transfer' },
] as const;

/** A saved address as checkout form fields. */
function addressFields(address: Address, phone: string) {
  return {
    name: address.name,
    phone: address.phone ?? phone,
    line1: [address.line1, address.line2].filter(Boolean).join(', '),
    city: address.city,
    postal_code: address.postal_code ?? '',
    country: address.country,
  };
}

function newIdempotencyKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/**
 * Guest checkout against the existing checkout API (Module 11): totals,
 * shipping and stock are decided by the server; the page only collects
 * the shopper's details. One idempotency key per visit, so a double
 * click or a retry can never place two orders.
 */
export default function Checkout({ storefront, seo, payment_methods }: StorefrontPageProps & { payment_methods: string[] }) {
  // Only methods the store's package includes (the server enforces the same rule at checkout).
  const methods = PAYMENT_METHODS.filter((method) => payment_methods.includes(method.value));
  const [cart, setCart] = useState<CartData | null>(null);
  const [form, setForm] = useState({ name: '', email: '', phone: '', line1: '', city: '', postal_code: '', country: '', notes: '' });
  const [shipping, setShipping] = useState<ShippingOption[] | null>(null);
  const [shippingId, setShippingId] = useState<number | null>(null);
  const [payment, setPayment] = useState<string>(() => methods[0]?.value ?? '');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [order, setOrder] = useState<PlacedOrder | null>(null);
  const idempotencyKey = useMemo(newIdempotencyKey, []);
  // Signed-in shoppers: contact details and saved addresses fill the form.
  const { customer } = useCustomer(storefront);
  const [addresses, setAddresses] = useState<Address[]>([]);
  // Module 09 §52 (Phase B34): the signed-in customer's store credit, and whether to use it.
  const [credit, setCredit] = useState<{ balance_minor: number; currency: string } | null>(null);
  const [useCredit, setUseCredit] = useState(true);

  useEffect(() => {
    if (!customer) return;
    setForm((current) => ({ ...current, name: current.name || customer.name, email: current.email || customer.email, phone: current.phone || (customer.phone ?? '') }));
    storefrontFetch<{ data: Address[] }>(storefront, '/customer/addresses')
      .then((res) => {
        setAddresses(res.data);
        const preferred = res.data.find((a) => a.is_default) ?? res.data[0];
        if (preferred) setForm((current) => ({ ...current, ...addressFields(preferred, current.phone) }));
      })
      .catch(() => setAddresses([]));
    storefrontFetch<{ data: { balance_minor: number; currency: string } }>(storefront, '/customer/store-credit')
      .then((res) => setCredit(res.data))
      .catch(() => setCredit(null));
  }, [customer, storefront]);
  const base = storefront.base_path;
  const currency = cart?.currency ?? storefront.store.currency;

  useEffect(() => {
    storefrontFetch<{ data: CartData }>(storefront, '/cart')
      .then((res) => setCart(res.data))
      .catch((e) => setError(errorMessage(e)));
  }, [storefront]);

  useEffect(() => {
    if (form.country.trim().length < 2) return;
    const timer = window.setTimeout(() => {
      storefrontFetch<{ data: ShippingOption[] }>(storefront, '/shipping/quote', { query: { country: form.country.trim(), city: form.city.trim() } })
        .then((res) => {
          setShipping(res.data);
          setShippingId((current) => (res.data.some((o) => o.id === current) ? current : (res.data[0]?.id ?? null)));
        })
        .catch(() => setShipping([]));
    }, 400);

    return () => window.clearTimeout(timer);
  }, [form.country, form.city, storefront]);

  const field = (key: keyof typeof form) => ({
    value: form[key],
    onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setForm({ ...form, [key]: e.target.value }),
  });
  const shippingCost = shipping?.find((o) => o.id === shippingId)?.cost_minor ?? 0;
  // Credit in another currency than the cart's cannot pay for it.
  const hasCredit = credit !== null && credit.balance_minor > 0 && credit.currency === currency;

  async function placeOrder(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    const address = { name: form.name, line1: form.line1, city: form.city, postal_code: form.postal_code, country: form.country.trim().toUpperCase() };
    try {
      const res = await storefrontFetch<{ data: { order: PlacedOrder } }>(storefront, '/checkout', {
        method: 'POST',
        body: {
          payment_method: payment,
          shipping_method_id: shippingId,
          guest_name: form.name,
          guest_email: form.email,
          guest_phone: form.phone || null,
          shipping_address: address,
          billing_address: address,
          notes: form.notes || null,
          // A wish only: the server decides how much credit there is and takes it.
          use_store_credit: hasCredit && useCredit,
          idempotency_key: idempotencyKey,
        },
      });
      forgetCart(storefront);
      setOrder(res.data.order);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  if (order) {
    return (
      <StoreLayout shell={storefront} seo={seo}>
        <div className="mx-auto max-w-lg text-center">
          <h1 className="text-3xl font-bold">Thank you!</h1>
          <p className="mt-4">
            Your order <strong>{order.order_number}</strong> has been placed. Total: {formatMoney(order.grand_total_minor, order.currency)}.
          </p>
          {(order.store_credit_minor ?? 0) > 0 && (
            <p className="mt-2">
              {formatMoney(order.store_credit_minor ?? 0, order.currency)} was paid with your store credit.{' '}
              {(order.payable_minor ?? 0) > 0 ? `Left to pay: ${formatMoney(order.payable_minor ?? 0, order.currency)}.` : 'Nothing is left to pay.'}
            </p>
          )}
          <p className="mt-2 text-sf-muted">A confirmation has been sent to {form.email}.</p>
          {customer && (
            <Link href={`${base}/account/orders`} className="mt-2 block text-sf-accent">
              View your orders
            </Link>
          )}
          {payment === 'bank_transfer' && <p className="mt-2 text-sf-muted">Please use your order number as the transfer reference.</p>}
          <Link href={base || '/'} className="mt-8 inline-block rounded-sf bg-sf-primary px-5 py-2 font-medium text-white">
            Continue shopping
          </Link>
        </div>
      </StoreLayout>
    );
  }

  const input = 'w-full rounded-sf border border-sf-border px-3 py-2';

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <h1 className="mb-6 text-3xl font-bold">Checkout</h1>
      {!cart && !error && <LoadingState />}
      {cart && cart.items.length === 0 && <EmptyState title="Your cart is empty" description="Add something to your cart before checking out." />}
      {error && (
        <p className="mb-4 rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">
          {error}
        </p>
      )}
      {cart && cart.items.length > 0 && (
        <form onSubmit={placeOrder} className="grid gap-8 md:grid-cols-[1fr_320px]">
          <div className="space-y-6">
            <fieldset className="grid gap-3 sm:grid-cols-2">
              <legend className="mb-2 font-semibold">Contact</legend>
              <input {...field('name')} required placeholder="Full name" aria-label="Full name" autoComplete="name" className={input} />
              <input {...field('email')} required type="email" placeholder="Email" aria-label="Email" autoComplete="email" className={input} />
              <input {...field('phone')} placeholder="Phone (optional)" aria-label="Phone" autoComplete="tel" className={input} />
            </fieldset>
            <fieldset className="grid gap-3 sm:grid-cols-2">
              <legend className="mb-2 font-semibold">Delivery address</legend>
              {addresses.length > 0 && (
                <select
                  aria-label="Saved addresses"
                  onChange={(e) => {
                    const chosen = addresses.find((a) => a.id === e.target.value);
                    if (chosen) setForm((current) => ({ ...current, ...addressFields(chosen, current.phone) }));
                  }}
                  defaultValue={(addresses.find((a) => a.is_default) ?? addresses[0]).id}
                  className={`${input} sm:col-span-2`}
                >
                  {addresses.map((address) => (
                    <option key={address.id} value={address.id}>
                      {address.label ? `${address.label} — ` : ''}
                      {formatAddress(address)}
                    </option>
                  ))}
                </select>
              )}
              <input {...field('line1')} required placeholder="Street address" aria-label="Street address" autoComplete="address-line1" className={`${input} sm:col-span-2`} />
              <input {...field('city')} required placeholder="City" aria-label="City" autoComplete="address-level2" className={input} />
              <input {...field('postal_code')} placeholder="Postal code" aria-label="Postal code" autoComplete="postal-code" className={input} />
              <input {...field('country')} required maxLength={2} placeholder="Country code (e.g. PK)" aria-label="Country code" autoComplete="country" className={input} />
            </fieldset>
            <fieldset>
              <legend className="mb-2 font-semibold">Shipping</legend>
              {shipping === null && <p className="text-sm text-sf-muted">Enter your country to see delivery options.</p>}
              {shipping !== null && shipping.length === 0 && <p className="text-sm text-sf-error">We do not deliver to this address yet.</p>}
              {shipping?.map((option) => (
                <label key={option.id} className="flex items-center justify-between gap-3 py-1">
                  <span className="flex items-center gap-2">
                    <input type="radio" name="shipping" checked={shippingId === option.id} onChange={() => setShippingId(option.id)} />
                    {option.name}
                  </span>
                  <span>{option.cost_minor === 0 ? 'Free' : formatMoney(option.cost_minor, currency)}</span>
                </label>
              ))}
            </fieldset>
            <fieldset>
              <legend className="mb-2 font-semibold">Payment</legend>
              {methods.length === 0 && <p className="text-sm text-sf-error">This store is not accepting orders online right now.</p>}
              {methods.map((method) => (
                <label key={method.value} className="flex items-center gap-2 py-1">
                  <input type="radio" name="payment" checked={payment === method.value} onChange={() => setPayment(method.value)} />
                  {method.label}
                </label>
              ))}
            </fieldset>
            <textarea {...field('notes')} placeholder="Order notes (optional)" aria-label="Order notes" maxLength={1000} className={input} />
          </div>

          <aside className="h-fit space-y-3 rounded-sf border border-sf-border p-4 text-sm">
            {cart.items.map((line) => (
              <p key={line.cart_item_id} className="flex justify-between gap-2">
                <span>
                  {line.product_name} × {line.quantity}
                </span>
                <span>{line.line_total_minor !== null && formatMoney(line.line_total_minor, currency)}</span>
              </p>
            ))}
            <p className="flex justify-between border-t border-sf-border pt-3">
              <span>Subtotal</span>
              <span>{formatMoney(cart.subtotal_minor, currency)}</span>
            </p>
            <p className="flex justify-between">
              <span>Shipping</span>
              <span>{shippingId ? formatMoney(shippingCost, currency) : '—'}</span>
            </p>
            {hasCredit && credit && (
              <label className="flex items-start gap-2 rounded-sf border border-sf-border p-3">
                <input type="checkbox" checked={useCredit} onChange={(e) => setUseCredit(e.target.checked)} className="mt-1" />
                <span>
                  Use my store credit ({formatMoney(credit.balance_minor, credit.currency)})
                  <span className="block text-xs text-sf-muted">It pays as much of the total as it covers; you pay the rest by the method above.</span>
                </span>
              </label>
            )}
            <p className="text-xs text-sf-muted">The final total, including any discount and tax, is confirmed when you place the order.</p>
            <button type="submit" disabled={busy || cart.has_issues || methods.length === 0} className="w-full rounded-sf bg-sf-primary px-4 py-3 font-semibold text-white disabled:opacity-50">
              {busy ? 'Placing order…' : 'Place order'}
            </button>
          </aside>
        </form>
      )}
    </StoreLayout>
  );
}
