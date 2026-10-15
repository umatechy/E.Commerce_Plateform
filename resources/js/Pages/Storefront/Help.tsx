import { useState, type FormEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import type { StorefrontPageProps } from '@/Storefront/types';
import { useT } from '@/Storefront/i18n';
import type { HelpSummary } from '@/lib/help';

/**
 * Phase B54 (Module 34 §43) — the store's help center for shoppers:
 * search, answers by topic, the store's own policy pages, and the way to
 * the contact form. Only shopper articles exist here, in the visitor's
 * language where a translation exists.
 */
type Props = StorefrontPageProps & {
  categories: { key: string; label: string; articles: HelpSummary[] }[];
  query: string;
  results: HelpSummary[] | null;
};

export default function Help({ storefront, seo, categories, query, results }: Props) {
  const t = useT();
  const base = storefront.base_path;
  const [text, setText] = useState(query);

  function search(event: FormEvent) {
    event.preventDefault();
    const q = text.trim();
    router.get(`${base}/help`, q === '' ? {} : { q }, { preserveScroll: true });
  }

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="mx-auto max-w-4xl">
        <h1 className="font-sf-heading text-3xl font-bold">{t('How can we help?')}</h1>
        <form role="search" onSubmit={search} className="mt-4 flex gap-2">
          <label htmlFor="help-q" className="sr-only">
            {t('Search help')}
          </label>
          <input
            id="help-q"
            type="search"
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder={t('For example: return, delivery, payment')}
            className="w-full rounded-sf border border-sf-border bg-sf-bg px-3 py-2"
          />
          <button type="submit" className="sf-btn rounded-sf bg-sf-primary px-4 py-2 font-medium text-white">
            {t('Search')}
          </button>
        </form>

        {results !== null ? (
          <section aria-labelledby="results" className="mt-8">
            <h2 id="results" className="text-lg font-semibold">
              {t('Results for “{query}”', { query })}
            </h2>
            {results.length === 0 ? (
              <p className="mt-2 text-sf-muted">
                {t('Nothing matches. Try other words, or')}{' '}
                <Link href={`${base}/contact`} className="underline">
                  {t('contact us')}
                </Link>
                .
              </p>
            ) : (
              <Articles base={base} articles={results} />
            )}
            <Link href={`${base}/help`} className="mt-4 inline-block text-sm underline">
              {t('All help topics')}
            </Link>
          </section>
        ) : (
          <div className="mt-8 grid gap-8 md:grid-cols-2">
            {categories.map((category) => (
              <section key={category.key} aria-labelledby={`c-${category.key}`}>
                <h2 id={`c-${category.key}`} className="text-lg font-semibold">
                  {category.label}
                </h2>
                <Articles base={base} articles={category.articles} />
              </section>
            ))}
          </div>
        )}

        <div className="mt-10 grid gap-4 md:grid-cols-2">
          {storefront.navigation.pages.length > 0 && (
            <section aria-labelledby="policies" className="rounded-sf border border-sf-border bg-sf-surface p-4">
              <h2 id="policies" className="font-semibold">
                {t('Store policies')}
              </h2>
              <ul className="mt-2 space-y-1">
                {storefront.navigation.pages.map((page) => (
                  <li key={page.slug}>
                    <Link href={`${base}/pages/${page.slug}`} className="underline">
                      {page.title}
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          )}
          <section aria-labelledby="contact" className="rounded-sf border border-sf-border bg-sf-surface p-4">
            <h2 id="contact" className="font-semibold">
              {t('Still need help?')}
            </h2>
            <p className="mt-1 text-sf-muted">{t('Send us a message and we will reply by email.')}</p>
            <Link href={`${base}/contact`} className="mt-2 inline-block font-medium underline">
              {t('Contact us')}
            </Link>
          </section>
        </div>
      </div>
    </StoreLayout>
  );
}

function Articles({ base, articles }: { base: string; articles: HelpSummary[] }) {
  return (
    <ul className="mt-3 space-y-3">
      {articles.map((article) => (
        <li key={article.key}>
          <Link href={`${base}/help/${article.key}`} className="font-medium underline-offset-2 hover:underline">
            {article.title}
          </Link>
          <p className="text-sm text-sf-muted">{article.summary}</p>
        </li>
      ))}
    </ul>
  );
}
