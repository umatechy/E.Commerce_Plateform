import { useState } from 'react';
import { Link } from '@inertiajs/react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import { storefrontFetch } from '@/Storefront/api';
import type { StorefrontPageProps } from '@/Storefront/types';
import { useLocale, useT } from '@/Storefront/i18n';
import type { HelpArticle as Article } from '@/lib/help';

/**
 * Phase B54 (Module 34 §43, §28, §60.3) — one help article on a store.
 * html was built by the server from Markdown and sanitized (allow-list):
 * the only HTML this page inserts. A translation made from an older
 * English version says so and links the English one.
 */
export default function HelpArticle({ storefront, seo, article }: StorefrontPageProps & { article: Article }) {
  const t = useT();
  const base = storefront.base_path;

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <article className="mx-auto max-w-3xl">
        <nav aria-label={t('Breadcrumb')} className="mb-4 text-sm text-sf-muted">
          <Link href={`${base}/help`} className="underline">
            {t('Help')}
          </Link>{' '}
          / {article.category_label}
        </nav>
        <h1 className="font-sf-heading text-3xl font-bold">{article.title}</h1>
        <p className="mt-2 text-sf-muted">{article.summary}</p>

        {article.translation_outdated && (
          <p role="note" className="mt-4 rounded-sf border border-sf-border bg-sf-surface p-3 text-sm">
            {t('This translation may be out of date.')}{' '}
            <a href={`${base}/help/${article.key}?lang=en`} className="underline">
              {t('Read it in English')}
            </a>
          </p>
        )}
        {article.status === 'deprecated' && article.replaced_by && (
          <p role="note" className="mt-4 rounded-sf border border-sf-border bg-sf-surface p-3 text-sm">
            {t('There is a newer article:')}{' '}
            <Link href={`${base}/help/${article.replaced_by.key}`} className="underline">
              {article.replaced_by.title}
            </Link>
          </p>
        )}

        <div className="help-article mt-6" dangerouslySetInnerHTML={{ __html: article.html }} />

        <Feedback storefront={storefront} articleKey={article.key} />

        {article.related.length > 0 && (
          <section aria-labelledby="related" className="mt-8 border-t border-sf-border pt-4">
            <h2 id="related" className="font-semibold">
              {t('Related questions')}
            </h2>
            <ul className="mt-2 space-y-1">
              {article.related.map((r) => (
                <li key={r.key}>
                  <Link href={`${base}/help/${r.key}`} className="underline">
                    {r.title}
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        )}
        <p className="mt-8 text-sm">
          {t('Still need help?')}{' '}
          <Link href={`${base}/contact`} className="font-medium underline">
            {t('Contact us')}
          </Link>
        </p>
      </article>
    </StoreLayout>
  );
}

function Feedback({ storefront, articleKey }: { storefront: StorefrontPageProps['storefront']; articleKey: string }) {
  const t = useT();
  const locale = useLocale();
  const [state, setState] = useState<'ask' | 'sending' | 'done' | 'failed'>('ask');

  function send(helpful: boolean) {
    setState('sending');
    storefrontFetch(storefront, `/storefront/help/${articleKey}/feedback`, { method: 'POST', body: { helpful, locale } })
      .then(() => setState('done'))
      .catch(() => setState('failed'));
  }

  if (state === 'done') {
    return (
      <p role="status" className="mt-8 rounded-sf bg-sf-surface p-3 text-sm">
        {t('Thank you for your feedback.')}
      </p>
    );
  }

  return (
    <div className="mt-8 flex flex-wrap items-center gap-2 rounded-sf border border-sf-border p-3 text-sm">
      <span className="font-medium">{t('Was this helpful?')}</span>
      <button type="button" disabled={state === 'sending'} onClick={() => send(true)} className="rounded-sf border border-sf-border px-3 py-1">
        {t('Yes')}
      </button>
      <button type="button" disabled={state === 'sending'} onClick={() => send(false)} className="rounded-sf border border-sf-border px-3 py-1">
        {t('No')}
      </button>
      {state === 'failed' && (
        <span role="alert" className="text-red-700">
          {t('Could not send. Please try again.')}
        </span>
      )}
    </div>
  );
}
