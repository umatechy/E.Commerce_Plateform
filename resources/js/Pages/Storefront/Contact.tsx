import { Link, router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import { errorMessage, storefrontFetch, validationErrors } from '@/Storefront/api';
import { useCustomer } from '@/Storefront/account';
import { categoryLabel, type SupportCategory } from '@/lib/support';
import type { StorefrontPageProps } from '@/Storefront/types';
import { useT } from '@/Storefront/i18n';

const input = 'w-full rounded-sf border border-sf-border bg-sf-bg px-3 py-2 disabled:bg-sf-surface';

type Created = { id: string; number: string; access_token: string | null };

/**
 * Phase B26 — the store's contact form. A guest gets a private link to
 * their request (also emailed); a signed-in shopper's request goes to
 * their account. The hidden `website` field is a honeypot for bots.
 */
export default function Contact({ storefront, seo, categories }: StorefrontPageProps & { categories: SupportCategory[] }) {
  const t = useT();
  const { customer, status } = useCustomer(storefront);
  const [form, setForm] = useState({ name: '', email: '', order_number: '', category: categories[0] ?? 'other', subject: '', message: '', website: '' });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [created, setCreated] = useState<Created | null>(null);
  const base = storefront.base_path;

  useEffect(() => {
    if (customer) setForm((f) => ({ ...f, name: customer.name, email: customer.email }));
  }, [customer]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      const res = await storefrontFetch<{ data: Created }>(storefront, '/storefront/support/contact', {
        method: 'POST',
        body: { ...form, order_number: form.order_number || null },
      });
      if (res.data.access_token === null) {
        router.visit(`${base}/account/support/${res.data.id}`);

        return;
      }
      setCreated(res.data);
    } catch (e) {
      setErrors(validationErrors(e));
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const fieldError = (field: string) => errors[field]?.[0] && <span className="mt-1 block text-sf-error">{errors[field][0]}</span>;
  const set = (field: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [field]: e.target.value });

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="mx-auto max-w-2xl">
        <h1 className="text-3xl font-bold">{t('Contact us')}</h1>
        {created ? (
          <div className="mt-6 space-y-4 rounded-sf border border-sf-border bg-sf-surface p-6" role="status">
            <p className="text-lg font-semibold">{t('Thanks — we have your request {number}.', { number: created.number })}</p>
            <p className="text-sm text-sf-muted">
              {t('We have emailed you a private link to follow the conversation. Keep it to yourself: anyone with the link can read and reply to this request.')}
            </p>
            {/* A plain link: the token stays in the #fragment, which is never sent to the server. */}
            <a href={`${base}/support/tickets/${created.id}#token=${encodeURIComponent(created.access_token ?? '')}`} className="inline-block rounded-sf bg-sf-primary px-4 py-2 font-medium text-white">
              {t('View your request')}
            </a>
          </div>
        ) : (
          <>
            <p className="mt-2 text-sf-muted">
              {t('Questions about an order, a product or anything else? Send us a message and we will reply by email.')}
              {status === 'guest' && (
                <>
                  {' '}
                  {t('Have an account?')}{' '}
                  <Link href={`${base}/account/login?redirect=${encodeURIComponent(`${base}/contact`)}`} className="text-sf-accent">
                    {t('Sign in')}
                  </Link>{' '}
                  {t('to keep all your requests in one place.')}
                </>
              )}
            </p>
            <form onSubmit={submit} className="mt-6 space-y-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="block text-sm">
                  <span className="mb-1 block text-sf-muted">{t('Your name')}</span>
                  <input required maxLength={120} value={form.name} onChange={set('name')} disabled={!!customer} className={input} name="name" autoComplete="name" />
                  {fieldError('name')}
                </label>
                <label className="block text-sm">
                  <span className="mb-1 block text-sf-muted">{t('Email')}</span>
                  <input required type="email" maxLength={190} value={form.email} onChange={set('email')} disabled={!!customer} className={input} name="email" autoComplete="email" />
                  {fieldError('email')}
                </label>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="block text-sm">
                  <span className="mb-1 block text-sf-muted">{t('Topic')}</span>
                  <select value={form.category} onChange={set('category')} className={input} name="category">
                    {categories.map((c) => (
                      <option key={c} value={c}>
                        {t(categoryLabel(c))}
                      </option>
                    ))}
                  </select>
                  {fieldError('category')}
                </label>
                <label className="block text-sm">
                  <span className="mb-1 block text-sf-muted">{t('Order number (optional)')}</span>
                  <input maxLength={40} value={form.order_number} onChange={set('order_number')} placeholder="ORD-000123" className={input} name="order_number" />
                  {fieldError('order_number')}
                </label>
              </div>
              <label className="block text-sm">
                <span className="mb-1 block text-sf-muted">{t('Subject')}</span>
                <input required maxLength={200} value={form.subject} onChange={set('subject')} className={input} name="subject" />
                {fieldError('subject')}
              </label>
              <label className="block text-sm">
                <span className="mb-1 block text-sf-muted">{t('Message')}</span>
                <textarea required rows={6} maxLength={10000} value={form.message} onChange={set('message')} className={input} name="message" />
                {fieldError('message')}
              </label>
              {/* Honeypot: hidden from people and screen readers; bots that fill it are refused. */}
              <div aria-hidden="true" className="absolute -start-[9999px] h-px w-px overflow-hidden">
                <label>
                  Website
                  <input tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} name="website" />
                </label>
              </div>
              {error && Object.keys(errors).length === 0 && (
                <p role="alert" className="text-sm text-sf-error">
                  {error}
                </p>
              )}
              <button type="submit" disabled={busy} className="rounded-sf bg-sf-primary px-4 py-2 font-medium text-white disabled:opacity-50">
                {busy ? t('Sending…') : t('Send message')}
              </button>
            </form>
          </>
        )}
      </div>
    </StoreLayout>
  );
}
