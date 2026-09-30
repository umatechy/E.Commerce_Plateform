import type { CSSProperties, PropsWithChildren } from 'react';
import { Head, Link } from '@inertiajs/react';
import SearchBox from './SearchBox';
import type { Seo, Shell } from '@/Storefront/types';

const RADIUS: Record<string, string> = { sm: '0.25rem', md: '0.5rem', lg: '1rem' };

/**
 * The storefront shell: announcement, header with navigation, search and
 * cart, and a footer with the store's published pages. Theme tokens
 * (validated hex colors, radius, font — Module 17) become CSS variables;
 * the store's custom CSS was sanitized when it was saved (B15) and is
 * inserted as text, never as markup.
 */
export default function StoreLayout({ shell, seo, children }: PropsWithChildren<{ shell: Shell; seo: Seo }>) {
  const tokens = shell.theme.tokens ?? {};
  const style = {
    ...Object.fromEntries(
      Object.entries(tokens)
        .filter(([key]) => key !== 'radius' && key !== 'font_family')
        .map(([key, value]) => [`--sf-${key}`, value]),
    ),
    '--sf-radius': RADIUS[tokens.radius ?? 'md'] ?? RADIUS.md,
    fontFamily: tokens.font_family ? `${tokens.font_family}, system-ui, sans-serif` : undefined,
  } as CSSProperties;
  const base = shell.base_path;

  return (
    <div className="flex min-h-screen flex-col bg-sf-bg text-sf-text" style={style}>
      <Head>
        <title>{seo.title}</title>
        {seo.description ? <meta head-key="description" name="description" content={seo.description} /> : null}
        <link head-key="canonical" rel="canonical" href={seo.canonical} />
        <meta head-key="robots" name="robots" content={seo.robots} />
      </Head>
      {shell.theme.custom_css ? <style>{shell.theme.custom_css}</style> : null}

      {shell.preview && (
        <div className="bg-amber-500 px-4 py-2 text-center text-sm font-medium text-white">
          Preview — this store is not launched yet. Only your team can see it.
        </div>
      )}
      {shell.announcement && <div className="bg-sf-primary px-4 py-2 text-center text-sm text-white">{shell.announcement}</div>}

      <header className="border-b border-sf-border">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-4">
          <Link href={base || '/'} className="flex items-center gap-2 text-xl font-bold">
            {shell.store.logo_url ? <img src={shell.store.logo_url} alt={shell.store.name} className="h-8 w-auto" /> : shell.store.name}
          </Link>
          <div className="order-last w-full sm:order-none sm:w-auto sm:flex-1">
            <SearchBox shell={shell} />
          </div>
          <div className="ml-auto flex items-center gap-2">
            <Link href={`${base}/account`} className="rounded-sf px-3 py-2 text-sm font-medium hover:bg-sf-surface">
              Account
            </Link>
            <Link href={`${base}/cart`} className="rounded-sf border border-sf-border px-3 py-2 text-sm font-medium">
              Cart
            </Link>
          </div>
        </div>
        <nav aria-label="Categories" className="mx-auto max-w-6xl px-4 pb-3">
          <ul className="flex flex-wrap gap-4 text-sm">
            <li>
              <Link href={`${base}/products`} className="hover:text-sf-accent">
                All products
              </Link>
            </li>
            {shell.navigation.categories.map((category) => (
              <li key={category.id}>
                <Link href={`${base}/categories/${category.slug}`} className="hover:text-sf-accent">
                  {category.name}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      </header>

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8">{children}</main>

      <footer className="border-t border-sf-border bg-sf-surface">
        <div className="mx-auto flex max-w-6xl flex-wrap justify-between gap-6 px-4 py-8 text-sm text-sf-muted">
          <div>
            <p className="font-semibold text-sf-text">{shell.store.name}</p>
            {shell.store.tagline && <p className="mt-1">{shell.store.tagline}</p>}
          </div>
          <ul className="space-y-1">
            {shell.navigation.pages.map((page) => (
              <li key={page.slug}>
                <Link href={`${base}/pages/${page.slug}`} className="hover:text-sf-text">
                  {page.title}
                </Link>
              </li>
            ))}
            <li>
              <Link href={`${base}/contact`} className="hover:text-sf-text">
                Contact us
              </Link>
            </li>
          </ul>
          {Object.keys(shell.store.social_links ?? {}).length > 0 && (
            <ul className="flex gap-3">
              {Object.entries(shell.store.social_links).map(([network, url]) => (
                <li key={network}>
                  <a href={url} rel="noopener noreferrer" target="_blank" className="capitalize hover:text-sf-text">
                    {network}
                  </a>
                </li>
              ))}
            </ul>
          )}
        </div>
      </footer>
    </div>
  );
}
