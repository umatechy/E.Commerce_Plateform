import { useState, type FormEvent } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import { errorMessage, storefrontFetch, validationErrors } from '@/Storefront/api';
import type { StorefrontPageProps } from '@/Storefront/types';
import { useT } from '@/Storefront/i18n';

const input = 'w-full rounded-sf border border-sf-border bg-sf-bg px-3 py-2';

type Program = { name: string; summary: string[]; terms: string };

/**
 * Phase B57 (Module 28 §47, §91) — "Become an affiliate": what an
 * affiliate earns, in plain words, the store's terms, and the application.
 * The store approves each application; the answer is the same whether or
 * not the address was known. The hidden `website` field is a honeypot.
 */
export default function AffiliateJoin({ storefront, seo, program }: StorefrontPageProps & { program: Program }) {
  const t = useT();
  const [form, setForm] = useState({ name: '', email: '', phone: '', note: '', website: '' });
  const [accept, setAccept] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      await storefrontFetch(storefront, '/storefront/affiliates/apply', { method: 'POST', body: { ...form, phone: form.phone || null, note: form.note || null, accept_terms: accept } });
      setSent(true);
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
      <div className="mx-auto max-w-3xl">
        <p className="text-sm font-medium uppercase tracking-wider text-sf-muted">{program.name}</p>
        <h1 className="mt-1 text-3xl font-bold">{t('Become an affiliate')}</h1>
        <p className="mt-2 text-sf-muted">{t('Recommend {store} to your friends and followers and earn a commission on the orders they place.', { store: storefront.store.name })}</p>

        <section aria-labelledby="how" className="mt-8 grid gap-4 sm:grid-cols-3">
          <h2 id="how" className="sr-only">{t('How it works')}</h2>
          {[
            [t('Apply'), t('Tell us a little about yourself. We review every application.')],
            [t('Share your link'), t('Once approved, you get your own link and a private page to follow your results.')],
            [t('Earn'), t('Orders placed through your link earn you a commission, paid once the order is delivered and settled.')],
          ].map(([title, text], i) => (
            <div key={title} className="rounded-sf border border-sf-border bg-sf-surface p-4">
              <span aria-hidden="true" className="flex h-8 w-8 items-center justify-center rounded-full bg-sf-primary text-sm font-bold text-white">{i + 1}</span>
              <p className="mt-3 font-semibold">{title}</p>
              <p className="mt-1 text-sm text-sf-muted">{text}</p>
            </div>
          ))}
        </section>

        <section aria-labelledby="earn" className="mt-8 rounded-sf border border-sf-border p-5">
          <h2 id="earn" className="text-lg font-semibold">{t('What you earn')}</h2>
          <ul className="mt-3 list-disc space-y-1 ps-5 text-sm">
            {program.summary.map((line) => <li key={line}>{line}</li>)}
          </ul>
        </section>

        {sent ? (
          <div className="mt-8 rounded-sf border border-sf-border bg-sf-surface p-6" role="status">
            <p className="text-lg font-semibold">{t('Thank you for applying.')}</p>
            <p className="mt-2 text-sm text-sf-muted">{t('The store reviews each application. If you are approved, we email you the link to your private page.')}</p>
          </div>
        ) : (
          <form onSubmit={submit} className="mt-8 space-y-4" noValidate={false}>
            <h2 className="text-lg font-semibold">{t('Apply')}</h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <label className="block text-sm">
                <span className="mb-1 block text-sf-muted">{t('Your name')}</span>
                <input required minLength={2} maxLength={120} value={form.name} onChange={set('name')} className={input} name="name" autoComplete="name" />
                {fieldError('name')}
              </label>
              <label className="block text-sm">
                <span className="mb-1 block text-sf-muted">{t('Email')}</span>
                <input required type="email" maxLength={190} value={form.email} onChange={set('email')} className={input} name="email" autoComplete="email" />
                {fieldError('email')}
              </label>
            </div>
            <label className="block text-sm">
              <span className="mb-1 block text-sf-muted">{t('Phone (optional)')}</span>
              <input type="tel" maxLength={30} value={form.phone} onChange={set('phone')} className={input} name="phone" autoComplete="tel" />
              {fieldError('phone')}
            </label>
            <label className="block text-sm">
              <span className="mb-1 block text-sf-muted">{t('Where will you share your link? (optional)')}</span>
              <textarea rows={3} maxLength={1000} value={form.note} onChange={set('note')} className={input} name="note" placeholder={t('For example: my Instagram page, my blog, my WhatsApp groups')} />
              {fieldError('note')}
            </label>
            {program.terms.trim() !== '' && (
              <details className="rounded-sf border border-sf-border p-4 text-sm">
                <summary className="cursor-pointer font-medium">{t('Program terms')}</summary>
                <p className="mt-3 whitespace-pre-line text-sf-muted">{program.terms}</p>
              </details>
            )}
            <label className="flex items-start gap-2 text-sm">
              <input type="checkbox" required checked={accept} onChange={(e) => setAccept(e.target.checked)} className="mt-1" name="accept_terms" />
              <span>{program.terms.trim() !== '' ? t('I accept the program terms.') : t('I agree to promote the store honestly.')}</span>
            </label>
            {fieldError('accept_terms')}
            {/* Honeypot: hidden from people and screen readers; bots that fill it are refused. */}
            <div aria-hidden="true" className="absolute -start-[9999px] h-px w-px overflow-hidden">
              <label>
                Website
                <input tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} name="website" />
              </label>
            </div>
            {error && Object.keys(errors).length === 0 && (
              <p role="alert" className="text-sm text-sf-error">{error}</p>
            )}
            <button type="submit" disabled={busy} className="sf-btn rounded-sf bg-sf-primary px-5 py-2.5 font-medium text-white disabled:opacity-60">
              {busy ? t('Sending…') : t('Send application')}
            </button>
          </form>
        )}
      </div>
    </StoreLayout>
  );
}
