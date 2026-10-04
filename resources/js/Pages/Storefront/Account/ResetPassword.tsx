import { Link } from '@inertiajs/react';
import { useT } from '@/Storefront/i18n';
import { useState } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import { errorMessage, storefrontFetch, validationErrors } from '@/Storefront/api';
import type { StorefrontPageProps } from '@/Storefront/types';

export default function ResetPassword({ storefront, seo, token, email }: StorefrontPageProps & { token: string; email: string }) {
  const t = useT();
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [done, setDone] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const base = storefront.base_path;

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      await storefrontFetch(storefront, '/storefront/password/reset', {
        method: 'POST',
        body: { token, email, password, password_confirmation: confirmation },
      });
      setDone(true);
    } catch (e) {
      setError(validationErrors(e).password?.[0] ?? errorMessage(e));
    }
  }

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="mx-auto max-w-sm">
        <h1 className="mb-6 text-3xl font-bold">{t('Choose a new password')}</h1>
        {done ? (
          <p className="rounded-sf bg-sf-surface p-4" role="status">
            {t('Your password has been changed and you have been signed out everywhere.')}{' '}
            <Link href={`${base}/account/login`} className="text-sf-accent">
              {t('Sign in')}
            </Link>
          </p>
        ) : token === '' ? (
          <p className="text-sf-error">{t('This link is incomplete. Please use the link from your email.')}</p>
        ) : (
          <form onSubmit={submit} className="space-y-4">
            {error && (
              <p className="rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">
                {error}
              </p>
            )}
            <p className="text-sm text-sf-muted">{t('For {email}', { email })}</p>
            <label className="block text-sm">
              <span className="mb-1 block text-sf-muted">{t('New password (at least 10 characters)')}</span>
              <input type="password" required autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} className="w-full rounded-sf border border-sf-border px-3 py-2" />
            </label>
            <label className="block text-sm">
              <span className="mb-1 block text-sf-muted">{t('Confirm new password')}</span>
              <input type="password" required autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} className="w-full rounded-sf border border-sf-border px-3 py-2" />
            </label>
            <button type="submit" className="w-full rounded-sf bg-sf-primary px-4 py-3 font-semibold text-white">
              {t('Set new password')}
            </button>
          </form>
        )}
      </div>
    </StoreLayout>
  );
}
