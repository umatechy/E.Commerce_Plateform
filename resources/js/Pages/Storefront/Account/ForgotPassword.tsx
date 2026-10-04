import { Link } from '@inertiajs/react';
import { useT } from '@/Storefront/i18n';
import { useState } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import { errorMessage, storefrontFetch } from '@/Storefront/api';
import type { StorefrontPageProps } from '@/Storefront/types';

/** The answer is the same whether or not an account exists (no email enumeration). */
export default function ForgotPassword({ storefront, seo }: StorefrontPageProps) {
  const t = useT();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      await storefrontFetch(storefront, '/storefront/password/forgot', { method: 'POST', body: { email } });
      setSent(true);
    } catch (e) {
      setError(errorMessage(e));
    }
  }

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="mx-auto max-w-sm">
        <h1 className="mb-6 text-3xl font-bold">{t('Reset your password')}</h1>
        {sent ? (
          <p className="rounded-sf bg-sf-surface p-4" role="status">
            {t('If an account exists for {email}, we have sent it a link to choose a new password. The link works for 60 minutes.', { email })}
          </p>
        ) : (
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
            <button type="submit" className="w-full rounded-sf bg-sf-primary px-4 py-3 font-semibold text-white">
              {t('Send reset link')}
            </button>
          </form>
        )}
        <p className="mt-6 text-center text-sm">
          <Link href={`${storefront.base_path}/account/login`} className="text-sf-accent">
            {t('Back to sign in')}
          </Link>
        </p>
      </div>
    </StoreLayout>
  );
}
