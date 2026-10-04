import { Link } from '@inertiajs/react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import ProductGrid from '@/Components/Storefront/ProductGrid';
import Reveal from '@/Components/Storefront/Reveal';
import { layoutOf } from '@/Storefront/theme';
import { useT, type Translate } from '@/Storefront/i18n';
import type { CategoryNode, ProductCard, Shell, StorefrontPageProps } from '@/Storefront/types';

type Banner = { type: 'hero' | 'promotional_banner'; heading?: string; subheading?: string; image_url?: string; cta_url?: string; cta_label?: string };
type Section =
  | Banner
  | { type: 'featured_products' | 'best_sellers' | 'sale_products'; heading: string | null; products: ProductCard[] }
  | { type: 'featured_categories'; heading: string | null; categories: CategoryNode[] }
  | { type: 'featured_brands'; heading: string | null; brands: { name: string; slug: string }[] }
  | { type: 'testimonials'; heading?: string; items?: { quote: string; name: string; detail?: string }[] }
  | { type: 'faq'; heading?: string; items?: { question: string; answer: string }[] }
  | { type: 'rich_text'; heading?: string; text?: string }
  | { type: 'trust_badges'; items?: { icon: string; title: string; text?: string }[] };

/**
 * Module 05 home page: the store's published theme sections, in order
 * (Module 17 §25). Phase B36: the hero follows the theme's hero style, and
 * the advanced sections render here. Every text is the store's own, shown
 * as text (never HTML).
 */
export default function Home({ storefront, seo, sections }: StorefrontPageProps & { sections: Section[] }) {
  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="space-y-14" style={{ rowGap: 'calc(3.5rem * var(--sf-density, 1))' }}>
        {sections.map((section, index) => (
          <HomeSection key={index} section={section} shell={storefront} />
        ))}
      </div>
    </StoreLayout>
  );
}

/** The storefront's own heading for a section the owner did not name (Phase B38: in the visitor's language). */
function defaultHeading(t: Translate, type: string): string {
  return {
    featured_products: t('New arrivals'),
    best_sellers: t('Best sellers'),
    sale_products: t('On sale'),
    featured_categories: t('Shop by category'),
    featured_brands: t('Shop by brand'),
  }[type] ?? '';
}

function HomeSection({ section, shell }: { section: Section; shell: Shell }) {
  const base = shell.base_path;
  const t = useT();

  switch (section.type) {
    case 'hero':
      return <Hero section={section} shell={shell} />;
    case 'promotional_banner':
      return (
        <Reveal as="section" className="relative overflow-hidden rounded-sf-lg bg-sf-primary px-6 py-10 text-white sm:px-10">
          {section.image_url && <img src={section.image_url} alt="" className="absolute inset-0 h-full w-full object-cover opacity-25" />}
          <div className="relative flex flex-wrap items-center justify-between gap-4">
            <div className="max-w-xl">
              {section.heading && <h2 className="text-2xl font-bold sm:text-3xl">{section.heading}</h2>}
              {section.subheading && <p className="mt-2 text-white/85">{section.subheading}</p>}
            </div>
            <Link href={section.cta_url ?? `${base}/products`} className="sf-btn rounded-sf bg-white px-5 py-2.5 font-semibold text-sf-primary">
              {section.cta_label || t('Shop now')}
            </Link>
          </div>
        </Reveal>
      );
    case 'featured_categories':
      return section.categories.length === 0 ? null : (
        <section>
          <SectionHeading title={section.heading ?? defaultHeading(t, section.type)} />
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
            {section.categories.map((category, i) => (
              <Reveal key={category.id} index={i} className="flex">
                <Link
                  href={`${base}/categories/${category.slug}`}
                  className="sf-card flex w-full flex-col justify-between rounded-sf-lg border border-sf-border bg-sf-surface p-5 hover:border-sf-accent"
                  style={{ minHeight: '7rem' }}
                >
                  <span className="font-sf-heading text-lg font-semibold">{category.name}</span>
                  <span className="mt-3 flex items-center justify-between text-sm text-sf-muted">
                    {t('{count} products', { count: category.product_count ?? 0 })} <span aria-hidden="true" className="inline-block rtl:rotate-180">→</span>
                  </span>
                </Link>
              </Reveal>
            ))}
          </div>
        </section>
      );
    case 'featured_products':
    case 'best_sellers':
    case 'sale_products':
      return section.products.length === 0 ? null : (
        <section>
          <SectionHeading title={section.heading ?? defaultHeading(t, section.type)} link={{ href: `${base}/products`, label: t('View all') }} />
          <ProductGrid products={section.products} basePath={base} />
        </section>
      );
    case 'featured_brands':
      return section.brands.length === 0 ? null : (
        <section>
          <SectionHeading title={section.heading ?? defaultHeading(t, section.type)} />
          <ul className="flex flex-wrap gap-3">
            {section.brands.map((brand) => (
              <li key={brand.slug}>
                <Link href={`${base}/brands/${brand.slug}`} className="sf-card inline-block rounded-sf border border-sf-border px-5 py-3 font-medium hover:border-sf-accent">
                  {brand.name}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      );
    case 'testimonials':
      return !section.items?.length ? null : (
        <section>
          <SectionHeading title={section.heading || t('What our customers say')} />
          <div className="grid gap-4 md:grid-cols-3">
            {section.items.map((item, i) => (
              <Reveal key={i} index={i} as="figure" className="rounded-sf-lg border border-sf-border bg-sf-surface p-6">
                <span aria-hidden="true" className="font-sf-heading text-4xl leading-none text-sf-accent">
                  “
                </span>
                <blockquote className="mt-1 text-sf-text">{item.quote}</blockquote>
                <figcaption className="mt-4 text-sm">
                  <span className="font-semibold">{item.name}</span>
                  {item.detail && <span className="text-sf-muted"> · {item.detail}</span>}
                </figcaption>
              </Reveal>
            ))}
          </div>
        </section>
      );
    case 'faq':
      return !section.items?.length ? null : (
        <section className="mx-auto max-w-3xl">
          <SectionHeading title={section.heading || t('Questions and answers')} />
          <div className="divide-y divide-sf-border rounded-sf-lg border border-sf-border">
            {section.items.map((item, i) => (
              <details key={i} className="group p-4 [&_summary::-webkit-details-marker]:hidden">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium focus:outline-none focus-visible:underline">
                  {item.question}
                  <span aria-hidden="true" className="text-sf-muted transition-transform group-open:rotate-45">
                    +
                  </span>
                </summary>
                <p className="mt-3 whitespace-pre-line text-sf-muted">{item.answer}</p>
              </details>
            ))}
          </div>
        </section>
      );
    case 'rich_text':
      return !section.text && !section.heading ? null : (
        <Reveal as="section" className="mx-auto max-w-3xl text-center">
          {section.heading && <h2 className="text-2xl font-bold sm:text-3xl">{section.heading}</h2>}
          {section.text && <p className="mt-4 whitespace-pre-line text-lg leading-relaxed text-sf-muted">{section.text}</p>}
        </Reveal>
      );
    case 'trust_badges':
      return !section.items?.length ? null : (
        <section aria-label={t('Why shop with us')}>
          <ul className={`grid gap-4 sm:grid-cols-2 ${section.items.length >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3'}`}>
            {section.items.map((item, i) => (
              <Reveal key={i} index={i} as="li" className="flex items-start gap-3 rounded-sf-lg bg-sf-surface p-4">
                <BadgeIcon name={item.icon} />
                <span>
                  <span className="block font-semibold">{item.title}</span>
                  {item.text && <span className="block text-sm text-sf-muted">{item.text}</span>}
                </span>
              </Reveal>
            ))}
          </ul>
        </section>
      );
    default:
      return null;
  }
}

function SectionHeading({ title, link }: { title: string; link?: { href: string; label: string } }) {
  return (
    <div className="mb-5 flex items-baseline justify-between gap-4">
      <h2 className="text-2xl font-bold sm:text-3xl">{title}</h2>
      {link && (
        <Link href={link.href} className="text-sm font-medium text-sf-accent hover:underline">
          {link.label}
        </Link>
      )}
    </div>
  );
}

/** Module 17 §22 hero styles: simple, centred, split (text beside a panel), full-bleed (colour across the page). */
function Hero({ section, shell }: { section: Banner; shell: Shell }) {
  const style = layoutOf(shell).hero_style;
  const t = useT();
  const base = shell.base_path;
  const cta = (
    <Link href={section.cta_url ?? `${base}/products`} className="sf-btn mt-7 inline-block rounded-sf bg-sf-primary px-6 py-3 font-semibold text-white hover:opacity-90">
      {section.cta_label || t('Shop now')}
    </Link>
  );
  const text = (inverted: boolean) => (
    <>
      {section.heading && <h1 className={`font-bold leading-tight ${style === 'fullbleed' ? 'text-4xl sm:text-6xl' : 'text-4xl sm:text-5xl'}`}>{section.heading}</h1>}
      {section.subheading && <p className={`mt-4 max-w-xl text-lg ${inverted ? 'text-white/85' : 'text-sf-muted'}`}>{section.subheading}</p>}
    </>
  );

  if (style === 'split') {
    return (
      <section className="grid items-stretch overflow-hidden rounded-sf-lg bg-sf-surface md:grid-cols-2" data-hero="split">
        <Reveal className="flex flex-col justify-center px-8 py-14 sm:px-12">
          {shell.store.tagline && <p className="mb-3 text-sm uppercase tracking-[0.2em] text-sf-accent">{shell.store.tagline}</p>}
          {text(false)}
          <div>{cta}</div>
        </Reveal>
        <div className="relative min-h-[16rem] bg-sf-primary">
          {section.image_url ? (
            <img src={section.image_url} alt="" className="absolute inset-0 h-full w-full object-cover" />
          ) : (
            <div aria-hidden="true" className="absolute inset-0" style={{ background: 'radial-gradient(circle at 30% 30%, var(--sf-accent), transparent 60%), linear-gradient(135deg, var(--sf-primary), var(--sf-secondary))' }} />
          )}
        </div>
      </section>
    );
  }

  if (style === 'fullbleed') {
    return (
      <section className="relative -mx-4 overflow-hidden px-4 py-20 text-white sm:py-28" data-hero="fullbleed" style={{ background: 'linear-gradient(120deg, var(--sf-primary), var(--sf-accent))', marginTop: 'calc(-2rem * var(--sf-density, 1))' }}>
        {section.image_url && <img src={section.image_url} alt="" className="absolute inset-0 h-full w-full object-cover opacity-30" />}
        <Reveal className="relative mx-auto max-w-4xl text-center [&_a]:bg-white [&_a]:text-sf-primary">
          {text(true)}
          {cta}
        </Reveal>
      </section>
    );
  }

  const centered = style === 'centered';

  return (
    <section className={`relative overflow-hidden rounded-sf-lg bg-sf-surface px-8 ${centered ? 'py-20 text-center' : 'py-16'}`} data-hero={style}>
      {section.image_url && <img src={section.image_url} alt="" className="absolute inset-0 h-full w-full object-cover opacity-30" />}
      <Reveal className={`relative ${centered ? 'mx-auto max-w-2xl [&_p]:mx-auto' : 'max-w-xl'}`}>
        {text(false)}
        {cta}
      </Reveal>
    </section>
  );
}

/** Trust badge icons — a fixed set drawn here (ThemeOptions::BADGE_ICONS); nothing is uploaded. */
function BadgeIcon({ name }: { name: string }) {
  const paths: Record<string, string> = {
    delivery: 'M3 7h11v8H3zM14 10h4l3 3v2h-7zM7 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm10 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
    cod: 'M3 7h18v10H3zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 10v4M18 10v4',
    returns: 'M4 10a8 8 0 1 1 2 6M4 10V5m0 5h5',
    secure: 'M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6zM9 12l2 2 4-4',
    support: 'M4 13v-1a8 8 0 0 1 16 0v1M4 13h3v5H4zM17 13h3v5h-3zM20 18a3 3 0 0 1-3 3h-3',
    quality: 'M12 3l2.5 5 5.5.8-4 3.9.9 5.5L12 15.6 7.1 18.2 8 12.7 4 8.8 9.5 8z',
  };

  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" className="h-8 w-8 flex-none text-sf-accent" fill="none" stroke="currentColor" strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round">
      <path d={paths[name] ?? paths.quality} />
    </svg>
  );
}
