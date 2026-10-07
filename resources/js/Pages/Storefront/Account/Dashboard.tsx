import { formatDate } from '@/lib/datetime';
import { useT } from '@/Storefront/i18n';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import { formatMoney } from '@/lib/money';
import { errorMessage, storefrontFetch } from '@/Storefront/api';
import { useCustomer } from '@/Storefront/account';
import { statusLabel, type OrderSummary } from '@/Storefront/orders';
import type { StorefrontPageProps } from '@/Storefront/types';

const CREDIT_LABELS: Record<string, string> = {
  return_refund: 'Refund of a return',
  adjustment: 'Added or changed by the store',
  spent: 'Used on an order',
  order_cancelled: 'Order cancelled',
  expired: 'Expired',
};

/**
 * Phase B32 (Module 10 §9/§13): an unconfirmed address. Confirming it
 * also brings the customer's earlier guest orders into the account.
 */
function ConfirmEmail({ storefront, email }: { storefront: StorefrontPageProps['storefront']; email: string }) {
  const t = useT();
  const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'recent' | 'failed'>('idle');
  const [error, setError] = useState<string | null>(null);

  function send() {
    setState('sending');
    storefrontFetch<{ data: { sent?: boolean; email_verified: boolean } }>(storefront, '/customer/email/verification', { method: 'POST' })
      .then((res) => setState(res.data.email_verified || res.data.sent !== false ? 'sent' : 'recent'))
      .catch((e) => {
        setError(errorMessage(e));
        setState('failed');
      });
  }

  return (
    <div className="mb-6 rounded-sf border border-sf-border bg-sf-surface p-4 text-sm" aria-live="polite">
      <p>
        {t('Please confirm your email address, {email}. Orders you placed earlier as a guest with this address then appear here.', { email })}
      </p>
      {state === 'sent' ? (
        <p className="mt-2 font-medium">{t('We sent you a link. It works for 24 hours.')}</p>
      ) : state === 'recent' ? (
        <p className="mt-2 font-medium">{t('A link was sent less than a minute ago. Please check your inbox and spam folder.')}</p>
      ) : (
        <>
          {state === 'failed' && error && (
            <p className="mt-2 text-sf-error" role="alert">
              {error}
            </p>
          )}
          <button type="button" onClick={send} disabled={state === 'sending'} className="sf-btn mt-3 rounded-sf bg-sf-primary px-4 py-2 font-semibold text-white disabled:opacity-60">
            {state === 'sending' ? t('Sending…') : t('Send me the link')}
          </button>
        </>
      )}
    </div>
  );
}

export default function Dashboard({ storefront, seo }: StorefrontPageProps) {
  const t = useT();
  const { customer } = useCustomer(storefront, { required: true });
  const [orders, setOrders] = useState<OrderSummary[] | null>(null);
  // Module 09 §52 (Phase B34): store credit, shown only when there is some or has been.
  const [credit, setCredit] = useState<{ balance_minor: number; currency: string; next_expiry?: { amount_minor: number; expires_at: string } | null; entries: { id: string; type: string; amount_minor: number; currency: string; created_at: string }[] } | null>(null);
  const base = storefront.base_path;

  useEffect(() => {
    if (!customer) return;
    storefrontFetch<{ data: { orders: OrderSummary[] } }>(storefront, '/customer/orders', { query: { per_page: '3' } })
      .then((res) => setOrders(res.data.orders))
      .catch(() => setOrders([]));
    storefrontFetch<{ data: NonNullable<typeof credit> }>(storefront, '/customer/store-credit')
      .then((res) => setCredit(res.data))
      .catch(() => setCredit(null));
  }, [customer, storefront]);

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account" title={t('Hello, {name}', { name: customer?.name.split(' ')[0] ?? '' })}>
      {customer && !customer.email_verified && <ConfirmEmail storefront={storefront} email={customer.email} />}
      <div className="grid gap-4 sm:grid-cols-3">
        {[
          { href: '/account/orders', title: t('Orders'), text: t('Track and review your orders') },
          { href: '/account/addresses', title: t('Addresses'), text: t('Manage delivery addresses') },
          { href: '/account/profile', title: t('Profile & security'), text: t('Details, password and privacy') },
        ].map((card) => (
          <Link key={card.href} href={`${base}${card.href}`} className="rounded-sf border border-sf-border p-4 hover:border-sf-accent">
            <span className="font-semibold">{card.title}</span>
            <span className="mt-1 block text-sm text-sf-muted">{card.text}</span>
          </Link>
        ))}
      </div>

      {credit && (credit.balance_minor > 0 || credit.entries.length > 0) && (
        <section className="mt-8 rounded-sf border border-sf-border p-4" aria-label={t('Store credit')}>
          <h2 className="font-semibold">{t('Store credit')}</h2>
          <p className="mt-1 text-2xl font-bold">{formatMoney(credit.balance_minor, credit.currency)}</p>
          <p className="text-sm text-sf-muted">{t('You can use it at checkout.')}</p>
          {credit.next_expiry && (
            <p className="text-sm font-medium">{t('{amount} expires on {date}.', { amount: formatMoney(credit.next_expiry.amount_minor, credit.currency), date: formatDate(credit.next_expiry.expires_at) })}</p>
          )}
          <ul className="mt-3 space-y-1 text-sm">
            {credit.entries.slice(0, 5).map((entry) => (
              <li key={entry.id} className="flex justify-between gap-3">
                <span className="text-sf-muted">{formatDate(entry.created_at)} · {t(CREDIT_LABELS[entry.type] ?? 'Changed')}</span>
                <span>{entry.amount_minor < 0 ? '−' : '+'}{formatMoney(Math.abs(entry.amount_minor), entry.currency)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <h2 className="mb-3 mt-10 text-xl font-semibold">{t('Recent orders')}</h2>
      {orders === null && <p className="text-sm text-sf-muted">{t('Loading…')}</p>}
      {orders?.length === 0 && (
        <p className="text-sm text-sf-muted">
          {t('No orders yet.')}{' '}
          <Link href={`${base}/products`} className="text-sf-accent">
            {t('Start shopping')}
          </Link>
        </p>
      )}
      <ul className="divide-y divide-sf-border">
        {orders?.map((order) => (
          <li key={order.id}>
            <Link href={`${base}/account/orders/${order.id}`} className="flex justify-between gap-4 py-3 hover:text-sf-accent">
              <span>
                <span className="font-medium">{order.number}</span>
                <span className="ms-3 text-sm text-sf-muted">{formatDate(order.placed_at)}</span>
              </span>
              <span className="text-sm">
                {t(statusLabel(order.status))} · {formatMoney(order.grand_total_minor, order.currency)}
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </AccountLayout>
  );
}
