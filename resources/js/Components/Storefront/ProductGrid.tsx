import ProductCard from './ProductCard';
import Reveal from './Reveal';
import { useStorefrontTheme } from '@/Storefront/theme';
import type { ProductCard as Card } from '@/Storefront/types';

/** The theme sets the columns on large screens (Module 17 §21); cards reveal in a capped stagger (Module 18 §23). */
export default function ProductGrid({ products, basePath }: { products: Card[]; basePath: string }) {
  const columns = useStorefrontTheme()?.layout.grid_columns ?? 4;

  return (
    <div className={`grid grid-cols-2 gap-4 md:grid-cols-3 ${columns === 3 ? '' : 'lg:grid-cols-4'}`} style={{ gap: 'calc(1rem * var(--sf-density, 1))' }}>
      {products.map((product, index) => (
        <Reveal key={product.id} index={index} className="flex">
          <div className="flex w-full flex-col [&>article]:flex-1">
            <ProductCard product={product} basePath={basePath} />
          </div>
        </Reveal>
      ))}
    </div>
  );
}
