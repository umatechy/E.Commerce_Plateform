import { useEffect, useMemo, useState, type PropsWithChildren } from 'react';
import { Head, Link } from '@inertiajs/react';
import SearchBox from './SearchBox';
import { loadFont } from '@/Storefront/fonts';
import { CONTAINER, layoutOf, motionOf, StorefrontThemeContext, themeStyle, type ThemeLayout } from '@/Storefront/theme';
import type { Seo, Shell } from '@/Storefront/types';

/**
 * The storefront shell: announcement, header with navigation, search and
 * cart, and a footer with the store's published pages. Theme tokens
 * (validated hex colors, radius, fonts, shadow, density — Module 17) become
 * CSS variables and the motion profile (Module 18) data attributes
 * (Storefront/theme.ts); the store's custom CSS was sanitized when it was
 * saved (B15) and is inserted as text, never as markup.
 *
 * Phase B36: the theme's layout chooses the header (classic, minimal,
 * centred, split), a sticky header, the page width and the footer (simple
 * or columns). One component renders all of them.
 */
export default function StoreLayout({ shell, seo, children }: PropsWithChildren<{ shell: Shell; seo: Seo }>) {
  const layout = layoutOf(shell);
  const motion = motionOf(shell);
  const { style, attributes } = themeStyle(shell);
  const container = CONTAINER[layout.container];
  const context = useMemo(() => ({ shell, layout, motion, reveal: motion.reveal_on_scroll && motion.profile !== 'none' }), [shell, layout, motion]);

  useEffect(() => {
    loadFont(shell.theme.tokens?.font_family);
    loadFont(shell.theme.tokens?.heading_font);
  }, [shell.theme.tokens?.font_family, shell.theme.tokens?.heading_font]);

  return (
    <StorefrontThemeContext.Provider value={context}>
      <div className="sf-root flex min-h-screen flex-col bg-sf-bg text-sf-text" style={style} {...attributes}>
        <Head>
          <title>{seo.title}</title>
          {seo.description ? <meta head-key="description" name="description" content={seo.description} /> : null}
          <link head-key="canonical" rel="canonical" href={seo.canonical} />
          <meta head-key="robots" name="robots" content={seo.robots} />
          {shell.store.favicon_url ? <link head-key="icon" rel="icon" href={shell.store.favicon_url} /> : null}
        </Head>
        {shell.theme.custom_css ? <style>{shell.theme.custom_css}</style> : null}

        {shell.preview && (
          <div className="bg-amber-500 px-4 py-2 text-center text-sm font-medium text-white">
            Preview — this store is not launched yet. Only your team can see it.
          </div>
        )}
        {shell.theme_preview && (
          <div role="status" className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-indigo-700 px-4 py-2 text-center text-sm font-medium text-white">
            <span>Theme preview — you are seeing the unpublished draft. Customers see the published theme.</span>
            {/* A full page load, so the server ends the preview (it forgets the preview cookie). */}
            <a href={`${shell.base_path}/?theme_preview=exit`} className="rounded underline focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
              End preview
            </a>
          </div>
        )}
        {shell.announcement && <div className="bg-sf-primary px-4 py-2 text-center text-sm text-white">{shell.announcement}</div>}

        <Header shell={shell} layout={layout} container={container} />

        <main className={`sf-page mx-auto w-full ${container} flex-1 px-4`} style={{ paddingBlock: 'calc(2rem * var(--sf-density, 1))' }}>
          {children}
        </main>

        {layout.footer_style === 'columns' ? <ColumnsFooter shell={shell} container={container} /> : <SimpleFooter shell={shell} container={container} />}
      </div>
    </StorefrontThemeContext.Provider>
  );
}

function Logo({ shell, size = 'text-xl' }: { shell: Shell; size?: string }) {
  return (
    <Link href={shell.base_path || '/'} className={`flex items-center gap-2 font-sf-heading font-bold ${size}`}>
      {shell.store.logo_url ? <img src={shell.store.logo_url} alt={shell.store.name} className="h-8 w-auto" /> : shell.store.name}
    </Link>
  );
}

function Actions({ shell, inverted = false }: { shell: Shell; inverted?: boolean }) {
  const base = shell.base_path;
  const hover = inverted ? 'hover:bg-white/10' : 'hover:bg-sf-surface';

  return (
    <div className="flex items-center gap-2">
      <Link href={`${base}/account`} className={`sf-btn rounded-sf px-3 py-2 text-sm font-medium ${hover}`}>
        Account
      </Link>
      <Link href={`${base}/cart`} className={`sf-btn rounded-sf border px-3 py-2 text-sm font-medium ${inverted ? 'border-white/40' : 'border-sf-border'}`}>
        Cart
      </Link>
    </div>
  );
}

/** Category links; on small screens they scroll sideways instead of wrapping onto many lines. */
function Navigation({ shell, className = '', linkClass = 'hover:text-sf-accent' }: { shell: Shell; className?: string; linkClass?: string }) {
  const base = shell.base_path;

  return (
    <nav aria-label="Categories" className={className}>
      <ul className="flex gap-x-5 gap-y-2 overflow-x-auto whitespace-nowrap text-sm sm:flex-wrap sm:overflow-visible">
        <li>
          <Link href={`${base}/products`} className={linkClass}>
            All products
          </Link>
        </li>
        {shell.navigation.categories.map((category) => (
          <li key={category.id}>
            <Link href={`${base}/categories/${category.slug}`} className={linkClass}>
              {category.name}
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}

function Header({ shell, layout, container }: { shell: Shell; layout: ThemeLayout; container: string }) {
  const sticky = layout.sticky_header ? 'sticky top-0 z-30' : '';
  const [scrolled, setScrolled] = useState(false);
  useEffect(() => {
    if (!layout.sticky_header) return;
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    return () => window.removeEventListener('scroll', onScroll);
  }, [layout.sticky_header]);
  const shadow = layout.sticky_header && scrolled ? 'shadow-sf' : '';

  switch (layout.header_style) {
    case 'minimal':
      return (
        <header className={`${sticky} ${shadow} border-b border-sf-border bg-sf-bg`} data-header="minimal">
          <div className={`mx-auto flex ${container} flex-wrap items-center gap-x-6 gap-y-3 px-4 py-4`}>
            <Logo shell={shell} size="text-lg" />
            <Navigation shell={shell} className="order-last w-full md:order-none md:w-auto md:flex-1" linkClass="text-sf-muted hover:text-sf-text" />
            <div className="ml-auto flex items-center gap-2">
              <div className="hidden w-56 lg:block">
                <SearchBox shell={shell} />
              </div>
              <Actions shell={shell} />
            </div>
          </div>
        </header>
      );
    case 'centered':
      return (
        <header className={`${sticky} ${shadow} border-b border-sf-border bg-sf-bg`} data-header="centered">
          <div className={`mx-auto grid ${container} grid-cols-1 items-center gap-3 px-4 pt-5 md:grid-cols-3`}>
            <div className="order-last md:order-none md:max-w-xs">
              <SearchBox shell={shell} />
            </div>
            <div className="flex justify-center">
              <Logo shell={shell} size="text-3xl tracking-wide" />
            </div>
            <div className="flex justify-center md:justify-end">
              <Actions shell={shell} />
            </div>
          </div>
          {shell.store.tagline && <p className="mt-1 text-center text-sm italic text-sf-muted">{shell.store.tagline}</p>}
          <Navigation shell={shell} className={`mx-auto ${container} px-4 pb-4 pt-4 [&_ul]:justify-start sm:[&_ul]:justify-center`} linkClass="uppercase tracking-[0.18em] text-xs text-sf-text hover:text-sf-accent" />
        </header>
      );
    case 'split':
      return (
        <header className={`${sticky} ${shadow} bg-sf-primary text-white`} data-header="split">
          <div className={`mx-auto flex ${container} flex-wrap items-center gap-x-8 gap-y-3 px-4 py-4`}>
            <Logo shell={shell} size="text-2xl uppercase tracking-tight" />
            <Navigation shell={shell} className="order-last w-full font-semibold lg:order-none lg:w-auto lg:flex-1" linkClass="text-white/85 hover:text-white" />
            <div className="ml-auto flex items-center gap-3">
              <div className="hidden w-60 text-sf-text md:block">
                <SearchBox shell={shell} />
              </div>
              <Actions shell={shell} inverted />
            </div>
          </div>
        </header>
      );
    default:
      return (
        <header className={`${sticky} ${shadow} border-b border-sf-border bg-sf-bg`} data-header="classic">
          <div className={`mx-auto flex ${container} flex-wrap items-center gap-4 px-4 py-4`}>
            <Logo shell={shell} />
            <div className="order-last w-full sm:order-none sm:w-auto sm:flex-1">
              <SearchBox shell={shell} />
            </div>
            <div className="ml-auto">
              <Actions shell={shell} />
            </div>
          </div>
          <Navigation shell={shell} className={`mx-auto ${container} px-4 pb-3`} />
        </header>
      );
  }
}

function HelpLinks({ shell }: { shell: Shell }) {
  const base = shell.base_path;

  return (
    <>
      {shell.navigation.pages.map((page) => (
        <li key={page.slug}>
          <Link href={`${base}/pages/${page.slug}`} className="hover:text-sf-text">
            {page.title}
          </Link>
        </li>
      ))}
      <li>
        <Link href={`${base}/returns`} className="hover:text-sf-text">
          Returns
        </Link>
      </li>
      <li>
        <Link href={`${base}/contact`} className="hover:text-sf-text">
          Contact us
        </Link>
      </li>
    </>
  );
}

function Social({ shell }: { shell: Shell }) {
  const links = Object.entries(shell.store.social_links ?? {});

  return links.length === 0 ? null : (
    <ul className="flex gap-3">
      {links.map(([network, url]) => (
        <li key={network}>
          <a href={url} rel="noopener noreferrer" target="_blank" className="capitalize hover:text-sf-text">
            {network}
          </a>
        </li>
      ))}
    </ul>
  );
}

function SimpleFooter({ shell, container }: { shell: Shell; container: string }) {
  return (
    <footer className="border-t border-sf-border bg-sf-surface">
      <div className={`mx-auto flex ${container} flex-wrap justify-between gap-6 px-4 py-8 text-sm text-sf-muted`}>
        <div>
          <p className="font-semibold text-sf-text">{shell.store.name}</p>
          {shell.store.tagline && <p className="mt-1">{shell.store.tagline}</p>}
        </div>
        <ul className="space-y-1">
          <HelpLinks shell={shell} />
        </ul>
        <Social shell={shell} />
      </div>
    </footer>
  );
}

function ColumnsFooter({ shell, container }: { shell: Shell; container: string }) {
  const base = shell.base_path;

  return (
    <footer className="border-t border-sf-border bg-sf-surface text-sm text-sf-muted" data-footer="columns">
      <div className={`mx-auto grid ${container} gap-8 px-4 py-12 sm:grid-cols-2 lg:grid-cols-4`}>
        <div>
          <p className="font-sf-heading text-lg font-bold text-sf-text">{shell.store.name}</p>
          {shell.store.tagline && <p className="mt-2 max-w-xs">{shell.store.tagline}</p>}
          <div className="mt-4">
            <Social shell={shell} />
          </div>
        </div>
        <div>
          <p className="mb-3 font-semibold uppercase tracking-wider text-sf-text">Shop</p>
          <ul className="space-y-2">
            <li>
              <Link href={`${base}/products`} className="hover:text-sf-text">
                All products
              </Link>
            </li>
            {shell.navigation.categories.slice(0, 6).map((category) => (
              <li key={category.id}>
                <Link href={`${base}/categories/${category.slug}`} className="hover:text-sf-text">
                  {category.name}
                </Link>
              </li>
            ))}
          </ul>
        </div>
        <div>
          <p className="mb-3 font-semibold uppercase tracking-wider text-sf-text">Help</p>
          <ul className="space-y-2">
            <HelpLinks shell={shell} />
          </ul>
        </div>
        <div>
          <p className="mb-3 font-semibold uppercase tracking-wider text-sf-text">Your account</p>
          <ul className="space-y-2">
            <li>
              <Link href={`${base}/account`} className="hover:text-sf-text">
                Sign in or register
              </Link>
            </li>
            <li>
              <Link href={`${base}/account/orders`} className="hover:text-sf-text">
                Your orders
              </Link>
            </li>
            <li>
              <Link href={`${base}/cart`} className="hover:text-sf-text">
                Cart
              </Link>
            </li>
          </ul>
        </div>
      </div>
      <div className="border-t border-sf-border">
        <p className={`mx-auto ${container} px-4 py-4 text-xs`}>
          © {new Date().getFullYear()} {shell.store.name}
        </p>
      </div>
    </footer>
  );
}
