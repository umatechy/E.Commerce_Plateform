import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import ProductGrid from '@/Components/Storefront/ProductGrid';
import Price from '@/Components/Storefront/Price';
import { errorMessage, StorefrontApiError, storefrontFetch } from '@/Storefront/api';
import { loginHref } from '@/Storefront/account';
import { findVariant, initialSelection, isSelectable, type Selection } from '@/Storefront/variants';
import type { Availability, ProductCard, ProductDetail, StorefrontPageProps } from '@/Storefront/types';
import { useT } from '@/Storefront/i18n';

const AVAILABILITY: Record<Availability, string> = { in_stock: 'text-sf-success', low_stock: 'text-sf-warning', backorder: 'text-sf-warning', out_of_stock: 'text-sf-error' };

export default function Product({
  storefront,
  seo,
  product,
  related,
  cross_sell = [],
  up_sell = [],
}: StorefrontPageProps & { product: ProductDetail; related: ProductCard[]; cross_sell?: ProductCard[]; up_sell?: ProductCard[] }) {
  const base = storefront.base_path;
  const t = useT();
  const availabilityLabel: Record<Availability, string> = { in_stock: t('In stock'), low_stock: t('Only a few left'), backorder: t('Available on backorder'), out_of_stock: t('Sold out') };
  const axes = product.options.map((option) => option.name);
  const [selection, setSelection] = useState<Selection>(() => initialSelection(product.variants));
  const [quantity, setQuantity] = useState(1);
  const [status, setStatus] = useState<{ type: 'idle' | 'busy' | 'added' | 'saved' | 'error'; message?: string }>({ type: 'idle' });
  const variant = useMemo(() => findVariant(product.variants, selection, axes), [product.variants, selection, axes]);
  const [activeImageId, setActiveImageId] = useState<string | null>(product.images[0]?.id ?? null);
  const hasVariants = product.variants.length > 0;

  const availability = hasVariants ? variant?.availability ?? null : product.availability;
  const purchasable = hasVariants ? Boolean(variant?.purchasable) : product.purchasable;
  const price = hasVariants && variant
    ? { ...product.price, amount_minor: variant.price_minor, max_amount_minor: null, compare_at_minor: variant.compare_at_minor, on_sale: variant.compare_at_minor !== null }
    : product.price;
  const shownImage = product.images.find((image) => image.id === (variant?.image_id ?? activeImageId)) ?? product.images[0];

  function choose(axis: string, value: string) {
    setSelection((current) => ({ ...current, [axis]: value }));
    setStatus({ type: 'idle' });
  }

  async function addToCart() {
    setStatus({ type: 'busy' });
    try {
      await storefrontFetch(storefront, '/cart/items', {
        method: 'POST',
        body: hasVariants ? { variant: variant?.id, quantity } : { product: product.id, quantity },
      });
      setStatus({ type: 'added' });
    } catch (error) {
      setStatus({ type: 'error', message: errorMessage(error) });
    }
  }

  async function saveToWishlist() {
    setStatus({ type: 'busy' });
    try {
      await storefrontFetch(storefront, '/wishlist', { method: 'POST', body: hasVariants && variant ? { variant: variant.id } : { product: product.id } });
      setStatus({ type: 'saved' });
    } catch (error) {
      if (error instanceof StorefrontApiError && error.status === 401) {
        router.visit(loginHref(storefront));
        return;
      }
      setStatus({ type: 'error', message: errorMessage(error) });
    }
  }

  return (
    <StoreLayout shell={storefront} seo={seo}>
      {product.breadcrumbs.length > 0 && (
        <nav aria-label={t('Breadcrumb')} className="mb-4 text-sm text-sf-muted">
          <Link href={base || '/'}>{t('Home')}</Link>
          {product.breadcrumbs.map((crumb) => (
            <span key={crumb.slug}>
              {' / '}
              <Link href={`${base}/categories/${crumb.slug}`}>{crumb.name}</Link>
            </span>
          ))}
        </nav>
      )}

      <div className="grid gap-10 md:grid-cols-2">
        <div>
          <div className="aspect-square overflow-hidden rounded-sf bg-sf-surface">
            {shownImage ? (
              <img src={shownImage.url} alt={shownImage.alt ?? product.name} width={shownImage.width} height={shownImage.height} className="h-full w-full object-cover" />
            ) : (
              <div className="flex h-full items-center justify-center text-sf-muted">{t('No image')}</div>
            )}
          </div>
          {product.images.length > 1 && (
            <div className="mt-3 flex gap-2 overflow-x-auto">
              {product.images.map((image) => (
                <button
                  key={image.id}
                  type="button"
                  onClick={() => setActiveImageId(image.id)}
                  className={`h-16 w-16 flex-none overflow-hidden rounded-sf border ${image.id === shownImage?.id ? 'border-sf-primary' : 'border-sf-border'}`}
                  aria-label={`${t('Show image')} ${image.alt ?? ''}`}
                >
                  <img src={image.url} alt="" className="h-full w-full object-cover" loading="lazy" />
                </button>
              ))}
            </div>
          )}
        </div>

        <div>
          {product.brand && (
            <Link href={`${base}/brands/${product.brand.slug}`} className="text-sm uppercase tracking-wide text-sf-muted">
              {product.brand.name}
            </Link>
          )}
          <h1 className="mt-1 text-3xl font-bold">{product.name}</h1>
          <Price price={price} className="mt-3 text-xl" />
          {product.summary && <p className="mt-4 text-sf-muted">{product.summary}</p>}

          {product.options.map((option) => (
            <fieldset key={option.name} className="mt-6">
              <legend className="mb-2 text-sm font-medium capitalize">{option.name}</legend>
              <div className="flex flex-wrap gap-2">
                {option.values.map((value) => {
                  const selectable = isSelectable(product.variants, selection, option.name, value);
                  const selected = selection[option.name] === value;

                  return (
                    <button
                      key={value}
                      type="button"
                      onClick={() => choose(option.name, value)}
                      aria-pressed={selected}
                      className={`rounded-sf border px-3 py-1 text-sm ${selected ? 'border-sf-primary bg-sf-primary text-white' : 'border-sf-border'} ${selectable ? '' : 'opacity-40 line-through'}`}
                    >
                      {value}
                    </button>
                  );
                })}
              </div>
            </fieldset>
          ))}

          {availability && <p className={`mt-4 text-sm font-medium ${AVAILABILITY[availability]}`}>{availabilityLabel[availability]}</p>}
          {hasVariants && !variant && <p className="mt-4 text-sm text-sf-muted">{t('This combination is not available.')}</p>}

          <div className="mt-6 flex items-center gap-3">
            <label className="sr-only" htmlFor="quantity">
              {t('Quantity')}
            </label>
            <input
              id="quantity"
              type="number"
              min={1}
              max={99}
              value={quantity}
              onChange={(e) => setQuantity(Math.max(1, Math.min(99, Number(e.target.value) || 1)))}
              className="w-20 rounded-sf border border-sf-border px-2 py-2"
            />
            <button
              type="button"
              onClick={addToCart}
              disabled={!purchasable || status.type === 'busy'}
              className="flex-1 rounded-sf bg-sf-primary px-5 py-3 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
            >
              {status.type === 'busy' ? t('Adding…') : purchasable ? t('Add to cart') : t('Unavailable')}
            </button>
          </div>
          <button type="button" onClick={saveToWishlist} disabled={status.type === 'busy'} className="mt-3 text-sm text-sf-accent disabled:opacity-50">
            ♡ {t('Save to wishlist')}
          </button>
          {status.type === 'saved' && (
            <p className="mt-3 text-sm text-sf-success" role="status">
              {t('Saved to your wishlist.')} <Link href={`${base}/account/wishlist`} className="underline">{t('View wishlist')}</Link>
            </p>
          )}
          {status.type === 'added' && (
            <p className="mt-3 text-sm text-sf-success" role="status">
              {t('Added to your cart.')} <Link href={`${base}/cart`} className="underline">{t('View cart')}</Link>
            </p>
          )}
          {status.type === 'error' && (
            <p className="mt-3 text-sm text-sf-error" role="alert">
              {status.message}
            </p>
          )}

          {product.description_html && (
            <div className="mt-8 space-y-3 border-t border-sf-border pt-6 leading-relaxed" dangerouslySetInnerHTML={{ __html: product.description_html }} />
          )}
        </div>
      </div>

      {/* Phase B39 (Module 06 §38): the products the store chose to show with this one. */}
      {cross_sell.length > 0 && (
        <section className="mt-16">
          <h2 className="mb-4 text-2xl font-semibold">{t('Goes well with')}</h2>
          <ProductGrid products={cross_sell} basePath={base} />
        </section>
      )}
      {up_sell.length > 0 && (
        <section className="mt-16">
          <h2 className="mb-4 text-2xl font-semibold">{t('You might prefer')}</h2>
          <ProductGrid products={up_sell} basePath={base} />
        </section>
      )}
      {related.length > 0 && (
        <section className="mt-16">
          <h2 className="mb-4 text-2xl font-semibold">{t('You may also like')}</h2>
          <ProductGrid products={related} basePath={base} />
        </section>
      )}
    </StoreLayout>
  );
}
