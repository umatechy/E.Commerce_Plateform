import ProductCard from './ProductCard';
import type { ProductCard as Card } from '@/Storefront/types';

export default function ProductGrid({ products, basePath }: { products: Card[]; basePath: string }) {
  return (
    <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
      {products.map((product) => (
        <ProductCard key={product.id} product={product} basePath={basePath} />
      ))}
    </div>
  );
}
