import { useState } from 'react';
import { Link } from '@inertiajs/react';
import Price from './Price';
import ProductBadges from './ProductBadges';
import { errorMessage, storefrontFetch } from '@/Storefront/api';
import { useT } from '@/Storefront/i18n';
import { useStorefrontTheme, type ThemeLayout } from '@/Storefront/theme';
import type { ProductCard as Card } from '@/Storefront/types';

/**
 * Module 17 §26 "Product Card System", Module 18 §15–16 (Phase B36).
 *
 * The theme chooses the style: standard, minimal, elevated or overlay. Every
 * style shows the name, price, sale and stock state — no animation hides
 * them (Module 18 §15). The second image fades in on hover on a desktop
 * pointer; on touch the first image stays (§13: hover is never the only way).
 *
 * "Add to cart" is on the card for a product without variants that can be
 * bought; it says "Added" only after the cart API answered yes (§16). A
 * product with variants links to its page to choose. The image and name are
 * links; the button is a separate control (no button inside a link).
 */
export default function ProductCard({ product, basePath }: { product: Card; basePath: string }) {
  const theme = useStorefrontTheme();
  const style: ThemeLayout['product_card'] = theme?.layout.product_card ?? 'standard';
  const href = `${basePath}/products/${product.slug}`;

  const frame = {
    standard: 'rounded-sf border border-sf-border bg-sf-bg',
    minimal: 'bg-transparent',
    elevated: 'rounded-sf-lg bg-sf-bg shadow-sf',
    overlay: 'rounded-sf-lg bg-sf-bg',
  }[style];

  return (
    <article className={`sf-card group relative flex flex-col overflow-hidden ${frame}`} data-card={style}>
      <Link href={href} className={`relative block aspect-square overflow-hidden bg-sf-surface ${style === 'minimal' ? 'rounded-sf' : ''}`} tabIndex={-1} aria-hidden="true">
        <CardImage product={product} />
        <Badges product={product} />
        {style === 'overlay' && (
          <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 via-black/50 to-transparent p-3 pt-10 text-white">
            <span className="block font-sf-heading font-semibold leading-snug drop-shadow">{product.name}</span>
            <Price price={product.price} className="mt-1 [&_*]:!text-white [&_s]:!text-white/70" />
          </div>
        )}
      </Link>
      <div className={`flex flex-1 flex-col gap-1 ${style === 'minimal' ? 'px-0 pt-3' : 'p-3'} ${style === 'overlay' ? 'pt-2' : ''}`}>
        {product.brand && <span className="text-xs uppercase tracking-wide text-sf-muted">{product.brand.name}</span>}
        <Link href={href} className={style === 'overlay' ? 'sr-only' : 'font-medium text-sf-text hover:text-sf-accent focus:outline-none focus-visible:underline'}>
          {product.name}
        </Link>
        {style !== 'overlay' && <Price price={product.price} className="mt-auto pt-1" />}
        <QuickAdd product={product} href={href} />
      </div>
    </article>
  );
}

function CardImage({ product }: { product: Card }) {
  const t = useT();
  if (!product.image) {
    return <div className="flex h-full items-center justify-center text-sm text-sf-muted">{t('No image')}</div>;
  }

  return (
    <>
      <img
        src={product.image.url}
        alt={product.image.alt ?? product.name}
        width={product.image.width}
        height={product.image.height}
        loading="lazy"
        className="sf-card-img h-full w-full object-cover"
      />
      {product.hover_image && (
        <img
          src={product.hover_image.url}
          alt=""
          width={product.hover_image.width}
          height={product.hover_image.height}
          loading="lazy"
          className="sf-card-img sf-card-img-alt absolute inset-0 h-full w-full object-cover opacity-0"
        />
      )}
    </>
  );
}

function Badges({ product }: { product: Card }) {
  const t = useT();
  // Phase B43: the store's badge settings decide; the server sends the list.
  if (product.badges) {
    return <ProductBadges badges={product.badges} className="pointer-events-none absolute start-2 top-2 max-w-[calc(100%-1rem)]" />;
  }

  return (
    <>
      {product.price.on_sale && <span className="absolute start-2 top-2 rounded-sf bg-sf-error px-2 py-0.5 text-xs font-semibold text-white">{t('Sale')}</span>}
      {!product.in_stock && <span className="absolute end-2 top-2 rounded-sf bg-gray-900/85 px-2 py-0.5 text-xs text-white">{t('Sold out')}</span>}
    </>
  );
}

function QuickAdd({ product, href }: { product: Card; href: string }) {
  const theme = useStorefrontTheme();
  const t = useT();
  const [state, setState] = useState<{ type: 'idle' | 'busy' | 'added' } | { type: 'error'; message: string }>({ type: 'idle' });

  if (!theme || product.price.amount_minor === null) return null;
  if (product.has_variants) {
    return (
      <Link href={href} className="sf-btn mt-2 inline-flex justify-center rounded-sf border border-sf-border px-3 py-1.5 text-sm font-medium hover:border-sf-accent">
        {t('Choose options')}<span className="sr-only"> {product.name}</span>
      </Link>
    );
  }
  if (!product.in_stock) {
    return (
      <button type="button" disabled className="mt-2 rounded-sf border border-sf-border px-3 py-1.5 text-sm text-sf-muted">
        {t('Sold out')}
      </button>
    );
  }

  async function add() {
    if (!theme) return;
    setState({ type: 'busy' });
    try {
      await storefrontFetch(theme.shell, '/cart/items', { method: 'POST', body: { product: product.id, quantity: 1 } });
      setState({ type: 'added' });
    } catch (error) {
      setState({ type: 'error', message: errorMessage(error) });
    }
  }

  return (
    <div className="mt-2">
      <button
        type="button"
        onClick={add}
        disabled={state.type === 'busy'}
        className={`sf-btn w-full rounded-sf bg-sf-primary px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-60 ${state.type === 'added' ? 'sf-added' : ''}`}
      >
        {state.type === 'busy' ? t('Adding…') : state.type === 'added' ? t('Added ✓') : t('Add to cart')}
        <span className="sr-only"> {product.name}</span>
      </button>
      <p role="status" aria-live="polite" className="sr-only">
        {state.type === 'added' ? t('{name} was added to your cart.', { name: product.name }) : ''}
      </p>
      {state.type === 'error' && (
        <p role="alert" className="mt-1 text-xs text-sf-error">
          {state.message}
        </p>
      )}
    </div>
  );
}
