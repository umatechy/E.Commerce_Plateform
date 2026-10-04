import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import OrderReturns, { guestReturnsApi } from '@/Components/Storefront/OrderReturns';
import { errorMessage, storefrontFetch } from '@/Storefront/api';
import type { StorefrontPageProps } from '@/Storefront/types';
import { useT } from '@/Storefront/i18n';

/**
 * Module 09 §8–9, §45 (Phase B34): returns for someone who ordered
 * without an account.
 *
 * 1. They enter the order number and the email of the order. The server
 *    always answers the same and, if the two belong together, sends a
 *    link to that email address.
 * 2. The link opens this page with a token. The server takes it out of
 *    the address at once (it keeps it in the visitor's session and sends
 *    the browser on without it), so it does not stay in the history or
 *    travel on in a Referer header. This page only passes it to the API.
 *
 * A customer with an account uses their account instead.
 */
export default function Returns({ storefront, seo, token: fromLink }: StorefrontPageProps & { token: string }) {
  const base = storefront.base_path;
  const t = useT();
  const [token, setToken] = useState<string | null>(fromLink !== '' ? fromLink : null);
  const [invalid, setInvalid] = useState<string | null>(null);
  const [orderNumber, setOrderNumber] = useState('');
  const [email, setEmail] = useState('');
  const [website, setWebsite] = useState('');
  const [state, setState] = useState<'idle' | 'sending' | 'sent'>('idle');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => setToken(fromLink !== '' ? fromLink : null), [fromLink]);

  const api = useMemo(() => (token ? guestReturnsApi(storefront, token) : null), [storefront, token]);

  function forget(message: string) {
    setToken(null);
    setInvalid(message);
    // The server forgets the token too, so a reload does not bring it back.
    void fetch(`${base}/returns?forget=1`, { credentials: 'same-origin', redirect: 'manual' }).catch(() => undefined);
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setState('sending');
    setError(null);
    try {
      await storefrontFetch(storefront, '/storefront/returns/lookup', { method: 'POST', body: { order_number: orderNumber.trim(), email: email.trim(), website } });
      setState('sent');
    } catch (e) {
      setError(errorMessage(e));
      setState('idle');
    }
  }

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="mx-auto max-w-2xl">
        <h1 className="mb-6 text-3xl font-bold">{t('Returns')}</h1>

        {api ? (
          <>
            <OrderReturns shell={storefront} api={api} onInvalid={forget} />
            <p className="mt-8 text-sm text-sf-muted">
              {t('This page was opened with the link from your email.')}{' '}
              <button type="button" onClick={() => forget('')} className="text-sf-accent underline">
                {t('Close it on this device')}
              </button>
            </p>
          </>
        ) : (
          <>
            {invalid !== null && invalid !== '' && <p className="mb-4 rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">{invalid}</p>}
            <p className="mb-4 text-sf-muted">
              {t('Ordered without an account? Enter your order number and the email you used. We send a link to that email address, with which you can ask for a return and follow it.')}
            </p>
            <p className="mb-6 text-sm text-sf-muted">
              {t('Have an account?')}{' '}
              <Link href={`${base}/account/orders`} className="text-sf-accent">
                {t('Open your orders')}
              </Link>{' '}
              {t('and choose the order.')}
            </p>
            {state === 'sent' ? (
              <p className="rounded-sf bg-sf-surface p-4" role="status">
                {t('If the order number and email belong together, a link is on its way to that email address. It works for 48 hours. Please also check your spam folder.')}
              </p>
            ) : (
              <form onSubmit={submit} className="space-y-4">
                {error && <p className="rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">{error}</p>}
                <label className="block text-sm">
                  <span className="mb-1 block text-sf-muted">{t('Order number')}</span>
                  <input required value={orderNumber} onChange={(e) => setOrderNumber(e.target.value)} maxLength={40} autoComplete="off" className="w-full rounded-sf border border-sf-border px-3 py-2" />
                </label>
                <label className="block text-sm">
                  <span className="mb-1 block text-sf-muted">{t('Email used for the order')}</span>
                  <input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} maxLength={255} autoComplete="email" className="w-full rounded-sf border border-sf-border px-3 py-2" />
                </label>
                {/* Honeypot: hidden from people and from assistive technology; bots fill it. */}
                <div className="hidden" aria-hidden="true">
                  <label>
                    Website
                    <input tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
                  </label>
                </div>
                <button type="submit" disabled={state === 'sending'} className="rounded-sf bg-sf-primary px-4 py-3 font-semibold text-white disabled:opacity-60">
                  {state === 'sending' ? t('Sending…') : t('Email me the link')}
                </button>
              </form>
            )}
          </>
        )}
      </div>
    </StoreLayout>
  );
}
