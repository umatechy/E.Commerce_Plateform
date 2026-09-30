import { Link } from '@inertiajs/react';
import Price from './Price';
import type { ProductCard as Card } from '@/Storefront/types';

export default function ProductCard({ product, basePath }: { product: Card; basePath: string }) {
  return (
    <Link
      href={`${basePath}/products/${product.slug}`}
      className="group flex flex-col overflow-hidden rounded-sf border border-sf-border bg-sf-bg transition hover:shadow-md"
    >
      <div className="relative aspect-square bg-sf-surface">
        {product.image ? (
          <img
            src={product.image.url}
            alt={product.image.alt ?? product.name}
            width={product.image.width}
            height={product.image.height}
            loading="lazy"
            className="h-full w-full object-cover transition group-hover:scale-105"
          />
        ) : (
          <div className="flex h-full items-center justify-center text-sm text-sf-muted">No image</div>
        )}
        {product.price.on_sale && (
          <span className="absolute left-2 top-2 rounded bg-sf-error px-2 py-0.5 text-xs font-semibold text-white">Sale</span>
        )}
        {!product.in_stock && (
          <span className="absolute right-2 top-2 rounded bg-gray-900/80 px-2 py-0.5 text-xs text-white">Sold out</span>
        )}
      </div>
      <div className="flex flex-1 flex-col gap-1 p-3">
        {product.brand && <span className="text-xs uppercase tracking-wide text-sf-muted">{product.brand.name}</span>}
        <span className="font-medium text-sf-text">{product.name}</span>
        <Price price={product.price} className="mt-auto pt-2" />
      </div>
    </Link>
  );
}
