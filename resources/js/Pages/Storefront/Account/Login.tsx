import { Link, router } from '@inertiajs/react';
import { useT } from '@/Storefront/i18n';
import { useState } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import { errorMessage, forgetCart, storefrontFetch } from '@/Storefront/api';
import type { StorefrontPageProps } from '@/Storefront/types';

/** Sign in. The guest cart is merged into the account (Module 11 §22). */
export default function Login({ storefront, seo, redirect }: StorefrontPageProps & { redirect: string | null }) {
  const t = useT();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const base = storefront.base_path;

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await storefrontFetch(storefront, '/storefront/session', { method: 'POST', body: { email, password } });
      forgetCart(storefront); // the guest cart now belongs to the account
      router.visit(redirect ?? `${base}/account`);
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  }

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="mx-auto max-w-sm">
        <h1 className="mb-6 text-3xl font-bold">{t('Sign in')}</h1>
        <form onSubmit={submit} className="space-y-4">
          {error && (
            <p className="rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">
              {error}
            </p>
          )}
          <label className="block text-sm">
            <span className="mb-1 block text-sf-muted">{t('Email')}</span>
            <input type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} className="w-full rounded-sf border border-sf-border px-3 py-2" />
          </label>
          <label className="block text-sm">
            <span className="mb-1 block text-sf-muted">{t('Password')}</span>
            <input type="password" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} className="w-full rounded-sf border border-sf-border px-3 py-2" />
          </label>
          <button type="submit" disabled={busy} className="w-full rounded-sf bg-sf-primary px-4 py-3 font-semibold text-white disabled:opacity-50">
            {busy ? t('Signing in…') : t('Sign in')}
          </button>
        </form>
        <div className="mt-6 flex justify-between text-sm">
          <Link href={`${base}/account/forgot-password`} className="text-sf-accent">
            {t('Forgot your password?')}
          </Link>
          <Link href={`${base}/account/register`} className="text-sf-accent">
            {t('Create an account')}
          </Link>
        </div>
      </div>
    </StoreLayout>
  );
}
