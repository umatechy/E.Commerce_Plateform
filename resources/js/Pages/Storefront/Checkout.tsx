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
import { useT } from '@/Storefront/i18n';
import PaymentChoice, { outOfRange, walletName, type Payer, type PaymentOption } from '@/Components/Storefront/PaymentChoice';
import WalletPayment, { type PaymentView } from '@/Components/Storefront/WalletPayment';
import BankDetails from '@/Components/Storefront/BankDetails';
import { normalizeMobile, validCnicLast6 } from '@/lib/payments';
import { useApp } from '@/Storefront/pwa';
import { referralToken } from '@/Storefront/referral';
import LoyaltyCheckout from '@/Components/Storefront/LoyaltyCheckout';

type ShippingOption = { id: number; name: string; type: string; cost_minor: number };
/** Phase B46: the server's price of the order before it is placed (POST /checkout/summary). */
type Summary = {
  currency: string;
  subtotal_minor: number;
  discount_minor: number;
  shipping_minor: number | null;
  needs_shipping_method: boolean;
  tax: { enabled: boolean; label: string; prices_include_tax: boolean; total_minor: number; exempt: boolean; breakdown: { name: string; rate_bps: number; tax_minor: number }[] };
  grand_total_minor: number;
};
type PlacedOrder = { order_number: string; grand_total_minor: number; currency: string; store_credit_minor?: number; payable_minor?: number };

/** Phase B48: what checkout says about the online payment it started (POST /checkout). */
type OnlinePayment = { token: string; attempt: PaymentView['attempt']; error: { code: string; message: string } | null };

/** A saved address as checkout form fields. */
function addressFields(address: Address, phone: string) {
  return {
    name: address.name,
    phone: address.phone ?? phone,
    line1: [address.line1, address.line2].filter(Boolean).join(', '),
    city: address.city,
    province: address.province ?? '',
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
export default function Checkout({ storefront, seo, payment_methods, payment_options, whatsapp_updates = false }: StorefrontPageProps & { payment_methods: string[]; payment_options?: PaymentOption[]; whatsapp_updates?: boolean }) {
  const t = useT();
  // Phase B55 (Module 25 §17): an order is only ever placed online, checked by the server.
  const { online: connected } = useApp();
  // Phase B48: the ways to pay this store offers now (package, settings, working accounts) —
  // the server checks the same again when the order is placed.
  const methods: PaymentOption[] = payment_options ?? payment_methods.map((method) => ({ method, kind: method === 'cod' ? 'cod' : 'bank_transfer' }));
  const [cart, setCart] = useState<CartData | null>(null);
  const [form, setForm] = useState({ name: '', email: '', phone: '', line1: '', city: '', province: '', postal_code: '', country: '', notes: '' });
  // Phase B50: WhatsApp order updates only with the shopper's own yes (unticked by default).
  const [whatsapp, setWhatsapp] = useState(false);
  const [shipping, setShipping] = useState<ShippingOption[] | null>(null);
  const [shippingId, setShippingId] = useState<number | null>(null);
  const [payment, setPayment] = useState<string>(() => methods[0]?.method ?? '');
  const [payer, setPayer] = useState<Payer>({ mobile: '', cnic_last6: '' });
  const [payerErrors, setPayerErrors] = useState<Partial<Record<keyof Payer, string>>>({});
  const [online, setOnline] = useState<{ paymentId: string; data: OnlinePayment } | null>(null);
  const [paidOnline, setPaidOnline] = useState(false);
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
    loadCredit();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customer, storefront]);

  function loadCredit() {
    storefrontFetch<{ data: { balance_minor: number; currency: string } }>(storefront, '/customer/store-credit')
      .then((res) => setCredit(res.data))
      .catch(() => setCredit(null));
  }
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
      storefrontFetch<{ data: ShippingOption[] }>(storefront, '/shipping/quote', { query: { country: form.country.trim(), province: form.province.trim(), city: form.city.trim() } })
        .then((res) => {
          setShipping(res.data);
          setShippingId((current) => (res.data.some((o) => o.id === current) ? current : (res.data[0]?.id ?? null)));
        })
        .catch(() => setShipping([]));
    }, 400);

    return () => window.clearTimeout(timer);
  }, [form.country, form.province, form.city, storefront]);

  // Phase B46: discount, delivery and tax as the server will charge them, before the order is placed.
  const [summary, setSummary] = useState<Summary | null>(null);
  const cartVersion = cart && cart.items.length > 0 ? `${cart.subtotal_minor}:${cart.items.length}` : ''; // an empty cart has nothing to price
  useEffect(() => {
    if (!cartVersion) return;
    // An answer to an older request (e.g. before a delivery option was chosen) must not replace a newer one.
    let current = true;
    const timer = window.setTimeout(() => {
      const country = form.country.trim().toUpperCase();
      storefrontFetch<{ data: Summary }>(storefront, '/checkout/summary', {
        method: 'POST',
        body: {
          shipping_method_id: shippingId,
          shipping_address: country.length === 2 ? { country, province: form.province.trim() || null, city: form.city.trim() || null, postal_code: form.postal_code.trim() || null } : null,
        },
      })
        .then((res) => current && setSummary(res.data))
        .catch(() => current && setSummary(null));
    }, 400);

    return () => {
      current = false;
      window.clearTimeout(timer);
    };
  }, [cartVersion, shippingId, form.country, form.province, form.city, form.postal_code, storefront]);

  const field = (key: keyof typeof form) => ({
    value: form[key],
    onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setForm({ ...form, [key]: e.target.value }),
  });
  const shippingCost = shipping?.find((o) => o.id === shippingId)?.cost_minor ?? 0;
  // Credit in another currency than the cart's cannot pay for it.
  const hasCredit = credit !== null && credit.balance_minor > 0 && credit.currency === currency;

  const chosenOption = methods.find((m) => m.method === payment && outOfRange(m, summary?.grand_total_minor ?? null) === null);
  const isWallet = chosenOption?.kind === 'wallet';

  async function placeOrder(event: React.FormEvent) {
    event.preventDefault();
    if (!chosenOption) {
      setError(t('Choose how you want to pay.'));

      return;
    }
    if (isWallet) {
      const next: typeof payerErrors = {};
      if (normalizeMobile(payer.mobile) === null) next.mobile = t('Enter the mobile number of the wallet, such as 03001234567.');
      if (chosenOption.payer_fields?.some((f) => f.key === 'cnic_last6') && !validCnicLast6(payer.cnic_last6)) next.cnic_last6 = t('Enter the last 6 digits of your CNIC.');
      setPayerErrors(next);
      if (Object.keys(next).length > 0) return;
    }
    setBusy(true);
    setError(null);
    const address = { name: form.name, line1: form.line1, city: form.city, province: form.province.trim() || null, postal_code: form.postal_code, country: form.country.trim().toUpperCase() };
    try {
      const res = await storefrontFetch<{ data: { order: PlacedOrder; payment: { id: string }; online_payment: OnlinePayment | null } }>(storefront, '/checkout', {
        method: 'POST',
        body: {
          payment_method: chosenOption.method,
          // Phase B48: the wallet to ask; checked by the server before the order is created.
          ...(isWallet ? { payer: { mobile: payer.mobile, ...(chosenOption.payer_fields?.some((f) => f.key === 'cnic_last6') ? { cnic_last6: payer.cnic_last6 } : {}) } } : {}),
          shipping_method_id: shippingId,
          guest_name: form.name,
          guest_email: form.email,
          guest_phone: form.phone || null,
          shipping_address: address,
          billing_address: address,
          notes: form.notes || null,
          ...(whatsapp_updates ? { whatsapp_updates: whatsapp && form.phone.trim() !== '' } : {}),
          // A wish only: the server decides how much credit there is and takes it.
          use_store_credit: hasCredit && useCredit,
          idempotency_key: idempotencyKey,
          // Phase B57: an affiliate's referral, if this device came through one; the server checks it again.
          referral_token: referralToken(storefront),
        },
      });
      forgetCart(storefront);
      if (res.data.online_payment) setOnline({ paymentId: res.data.payment.id, data: res.data.online_payment });
      setOrder(res.data.order);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  // Phase B48: a wallet order is placed; now the shopper approves the payment on the phone.
  if (order && online && !paidOnline) {
    return (
      <StoreLayout shell={storefront} seo={seo}>
        <div className="mx-auto max-w-lg">
          <p className="text-center text-sm text-sf-muted">{t('Order {number} is placed — one last step', { number: order.order_number })}</p>
          <h1 className="mb-5 mt-1 text-center text-3xl font-bold">{t('Complete your payment')}</h1>
          <WalletPayment storefront={storefront} paymentId={online.paymentId} token={online.data.token} initial={online.data} defaultPayer={payer} onPaid={() => setPaidOnline(true)} />
          <p className="mt-4 text-center text-xs text-sf-muted">{t('Prefer to pay another way? Contact the store with your order number.')}</p>
        </div>
      </StoreLayout>
    );
  }

  if (order) {
    return (
      <StoreLayout shell={storefront} seo={seo}>
        <div className="mx-auto max-w-lg text-center">
          <h1 className="text-3xl font-bold">{t('Thank you!')}</h1>
          <p className="mt-4">
            {t('Your order {number} has been placed. Total: {total}.', { number: order.order_number, total: formatMoney(order.grand_total_minor, order.currency) })}
          </p>
          {(order.store_credit_minor ?? 0) > 0 && (
            <p className="mt-2">
              {t('{amount} was paid with your store credit.', { amount: formatMoney(order.store_credit_minor ?? 0, order.currency) })}{' '}
              {(order.payable_minor ?? 0) > 0 ? t('Left to pay: {amount}.', { amount: formatMoney(order.payable_minor ?? 0, order.currency) }) : t('Nothing is left to pay.')}
            </p>
          )}
          {paidOnline && chosenOption && <p className="mt-2 font-medium text-emerald-700">{t('Paid with {provider}.', { provider: walletName(chosenOption, t) })}</p>}
          <p className="mt-2 text-sf-muted">{t('A confirmation has been sent to {email}.', { email: form.email })}</p>
          {customer && (
            <Link href={`${base}/account/orders`} className="mt-2 block text-sf-accent">
              {t('View your orders')}
            </Link>
          )}
          {chosenOption?.kind === 'bank_transfer' && (
            <div className="mt-6 text-start">
              <p className="mb-2 font-semibold">{t('Transfer {amount} to', { amount: formatMoney(order.payable_minor ?? order.grand_total_minor, order.currency) })}</p>
              {chosenOption.bank ? <BankDetails bank={chosenOption.bank} reference={order.order_number} /> : <p className="text-sf-muted">{t('Please use your order number as the transfer reference.')}</p>}
            </div>
          )}
          <Link href={base || '/'} className="sf-btn mt-8 inline-block rounded-sf bg-sf-primary px-5 py-2 font-medium text-white">
            {t('Continue shopping')}
          </Link>
        </div>
      </StoreLayout>
    );
  }

  const input = 'w-full rounded-sf border border-sf-border px-3 py-2';

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <h1 className="mb-6 text-3xl font-bold">{t('Checkout')}</h1>
      {!cart && !error && <LoadingState />}
      {cart && cart.items.length === 0 && <EmptyState title={t('Your cart is empty')} description={t('Add something to your cart before checking out.')} />}
      {error && (
        <p className="mb-4 rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">
          {error}
        </p>
      )}
      {cart && cart.items.length > 0 && (
        <form onSubmit={placeOrder} className="grid gap-8 md:grid-cols-[1fr_320px]">
          <div className="space-y-6">
            <fieldset className="grid gap-3 sm:grid-cols-2">
              <legend className="mb-2 font-semibold">{t('Contact')}</legend>
              <input {...field('name')} required placeholder={t('Full name')} aria-label={t('Full name')} autoComplete="name" className={input} />
              <input {...field('email')} required type="email" placeholder={t('Email')} aria-label={t('Email')} autoComplete="email" className={input} />
              <input {...field('phone')} placeholder={t('Phone (optional)')} aria-label={t('Phone')} autoComplete="tel" className={input} />
              {whatsapp_updates && (
                <label className="flex items-start gap-2 text-sm sm:col-span-2">
                  <input type="checkbox" checked={whatsapp} onChange={(e) => setWhatsapp(e.target.checked)} className="mt-1" aria-describedby="whatsapp-updates-hint" />
                  <span>
                    {t('Send my order updates on WhatsApp')}
                    <span id="whatsapp-updates-hint" className="block text-xs text-sf-muted">
                      {form.phone.trim() === '' ? t('Add your phone number above to get them.') : t('Only news about this order, to the number above. Reply STOP to end them.')}
                    </span>
                  </span>
                </label>
              )}
            </fieldset>
            <fieldset className="grid gap-3 sm:grid-cols-2">
              <legend className="mb-2 font-semibold">{t('Delivery address')}</legend>
              {addresses.length > 0 && (
                <select
                  aria-label={t('Saved addresses')}
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
              <input {...field('line1')} required placeholder={t('Street address')} aria-label={t('Street address')} autoComplete="address-line1" className={`${input} sm:col-span-2`} />
              <input {...field('city')} required placeholder={t('City')} aria-label={t('City')} autoComplete="address-level2" className={input} />
              <input {...field('province')} placeholder={t('Province or region (optional)')} aria-label={t('Province or region')} autoComplete="address-level1" className={input} />
              <input {...field('postal_code')} placeholder={t('Postal code')} aria-label={t('Postal code')} autoComplete="postal-code" className={input} />
              <input {...field('country')} required maxLength={2} placeholder={t('Country code (e.g. PK)')} aria-label={t('Country code')} autoComplete="country" className={input} />
            </fieldset>
            <fieldset>
              <legend className="mb-2 font-semibold">{t('Shipping')}</legend>
              {shipping === null && <p className="text-sm text-sf-muted">{t('Enter your country to see delivery options.')}</p>}
              {shipping !== null && shipping.length === 0 && <p className="text-sm text-sf-error">{t('We do not deliver to this address yet.')}</p>}
              {shipping?.map((option) => (
                <label key={option.id} className="flex items-center justify-between gap-3 py-1">
                  <span className="flex items-center gap-2">
                    <input type="radio" name="shipping" checked={shippingId === option.id} onChange={() => setShippingId(option.id)} />
                    {option.name}
                  </span>
                  <span>{option.cost_minor === 0 ? t('Free') : formatMoney(option.cost_minor, currency)}</span>
                </label>
              ))}
            </fieldset>
            <fieldset>
              <legend className="mb-2 font-semibold">{t('Payment')}</legend>
              {methods.length === 0 && <p className="text-sm text-sf-error">{t('This store is not accepting orders online right now.')}</p>}
              <PaymentChoice
                options={methods}
                value={payment}
                onChange={(method) => { setPayment(method); setPayerErrors({}); }}
                payer={payer}
                onPayer={(next) => { setPayer(next); setPayerErrors({}); }}
                payerErrors={payerErrors}
                totalMinor={summary?.grand_total_minor ?? null}
                currency={currency}
              />
            </fieldset>
            <textarea {...field('notes')} placeholder={t('Order notes (optional)')} aria-label={t('Order notes')} maxLength={1000} className={input} />
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
              <span>{t('Subtotal')}</span>
              <span>{formatMoney(cart.subtotal_minor, currency)}</span>
            </p>
            {summary !== null && summary.discount_minor > 0 && (
              <p className="flex justify-between">
                <span>{t('Discount')}</span>
                <span>− {formatMoney(summary.discount_minor, currency)}</span>
              </p>
            )}
            <p className="flex justify-between">
              <span>{t('Shipping')}</span>
              <span>{summary !== null && summary.shipping_minor !== null ? formatMoney(summary.shipping_minor, currency) : shippingId ? formatMoney(shippingCost, currency) : '—'}</span>
            </p>
            {summary !== null && summary.tax.enabled && (
              summary.tax.exempt ? (
                <p className="flex justify-between"><span>{summary.tax.label}</span><span>{t('Exempt')}</span></p>
              ) : summary.tax.prices_include_tax ? (
                <p className="flex justify-between text-sf-muted"><span>{t('Includes {label}', { label: summary.tax.label })}</span><span>{formatMoney(summary.tax.total_minor, currency)}</span></p>
              ) : (
                <p className="flex justify-between"><span>{summary.tax.label}</span><span>{formatMoney(summary.tax.total_minor, currency)}</span></p>
              )
            )}
            {summary !== null && (
              <p className="flex justify-between border-t border-sf-border pt-3 font-semibold">
                <span>{summary.needs_shipping_method ? t('Total before delivery') : t('Total')}</span>
                <span>{formatMoney(summary.grand_total_minor, currency)}</span>
              </p>
            )}
            {hasCredit && credit && (
              <label className="flex items-start gap-2 rounded-sf border border-sf-border p-3">
                <input type="checkbox" checked={useCredit} onChange={(e) => setUseCredit(e.target.checked)} className="mt-1" />
                <span>
                  {t('Use my store credit ({amount})', { amount: formatMoney(credit.balance_minor, credit.currency) })}
                  <span className="block text-xs text-sf-muted">{t('It pays as much of the total as it covers; you pay the rest by the method above.')}</span>
                </span>
              </label>
            )}
            {summary !== null && (
              <LoyaltyCheckout
                storefront={storefront}
                signedIn={customer !== null}
                // Points are for products after discounts, not for what store credit pays (as the server counts them).
                baseMinor={summary.subtotal_minor - summary.discount_minor - (hasCredit && useCredit && credit ? Math.min(credit.balance_minor, summary.grand_total_minor) : 0)}
                onCredited={() => { loadCredit(); setUseCredit(true); }}
              />
            )}
            <p className="text-xs text-sf-muted">{t('The final total, including any discount and tax, is confirmed when you place the order.')}</p>
            {!connected && (
              <p role="alert" className="rounded-sf bg-sf-surface p-2 text-center text-sm">
                {t('You are offline. Your cart is kept; connect to the internet to place the order.')}
              </p>
            )}
            <button type="submit" disabled={busy || !connected || cart.has_issues || methods.length === 0} className="sf-btn w-full rounded-sf bg-sf-primary px-4 py-3 font-semibold text-white disabled:opacity-50">
              {busy ? t('Placing order…') : isWallet && summary && !summary.needs_shipping_method ? t('Place order and pay {amount}', { amount: formatMoney(summary.grand_total_minor, currency) }) : t('Place order')}
            </button>
            {isWallet && <p className="text-center text-xs text-sf-muted">{t('After you place the order, approve the payment on your phone.')}</p>}
          </aside>
        </form>
      )}
    </StoreLayout>
  );
}
