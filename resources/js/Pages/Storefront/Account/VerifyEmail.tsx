import { Link } from '@inertiajs/react';
import { useT } from '@/Storefront/i18n';
import { useEffect, useRef, useState } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import { errorMessage, storefrontFetch } from '@/Storefront/api';
import type { StorefrontPageProps } from '@/Storefront/types';

/**
 * Module 10 §9/§13 (Phase B32): the link from the "confirm your email"
 * message. The token and address are only passed back to the API, which
 * checks them (one use, 24 hours, this store). Once confirmed, the
 * customer's earlier guest orders with that address join their account.
 */
export default function VerifyEmail({ storefront, seo, token, email }: StorefrontPageProps & { token: string; email: string }) {
  const t = useT();
  const [state, setState] = useState<'working' | 'done' | 'failed'>(token === '' || email === '' ? 'failed' : 'working');
  const [error, setError] = useState<string | null>(token === '' || email === '' ? t('This link is incomplete. Please use the link from your email.') : null);
  const sent = useRef(false);
  const base = storefront.base_path;

  useEffect(() => {
    // Once, also under React's development double effects: the link works one time.
    if (sent.current || token === '' || email === '') return;
    sent.current = true;
    storefrontFetch(storefront, '/customer/email/verify', { method: 'POST', body: { token, email } })
      .then(() => setState('done'))
      .catch((e) => {
        setError(errorMessage(e));
        setState('failed');
      });
  }, [storefront, token, email]);

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="mx-auto max-w-md" aria-live="polite">
        <h1 className="mb-6 text-3xl font-bold">{t('Confirm your email')}</h1>
        {state === 'working' && <p className="text-sf-muted">{t('Confirming…')}</p>}
        {state === 'done' && (
          <div className="rounded-sf bg-sf-surface p-4" role="status">
            <p>{t('Thank you — {email} is confirmed. Orders you placed earlier as a guest with this address are now in your account.', { email })}</p>
            <Link href={`${base}/account`} className="mt-3 inline-block text-sf-accent">
              {t('Go to your account')}
            </Link>
          </div>
        )}
        {state === 'failed' && (
          <div className="rounded-sf bg-red-50 p-4 text-sf-error" role="alert">
            <p>{error}</p>
            <p className="mt-2 text-sm">
              {t('A link works once and for 24 hours. You can ask for a new one in')}{' '}
              <Link href={`${base}/account`} className="underline">
                {t('your account')}
              </Link>
              .
            </p>
          </div>
        )}
      </div>
    </StoreLayout>
  );
}
