import { Link } from '@inertiajs/react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import ProductGrid from '@/Components/Storefront/ProductGrid';
import type { CategoryNode, ProductCard, StorefrontPageProps } from '@/Storefront/types';

type Section =
  | { type: 'hero' | 'promotional_banner'; heading?: string; subheading?: string; image_url?: string; cta_url?: string }
  | { type: 'featured_products'; heading: string; products: ProductCard[] }
  | { type: 'featured_categories'; heading: string; categories: CategoryNode[] };

/** Module 05 home page: the store's published theme sections, in order (Module 17). */
export default function Home({ storefront, seo, sections }: StorefrontPageProps & { sections: Section[] }) {
  const base = storefront.base_path;

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="space-y-12">
        {sections.map((section, index) => {
          switch (section.type) {
            case 'hero':
            case 'promotional_banner':
              return (
                <section
                  key={index}
                  className={`relative overflow-hidden rounded-sf bg-sf-surface ${section.type === 'hero' ? 'px-8 py-16' : 'px-6 py-10'}`}
                >
                  {section.image_url && (
                    <img src={section.image_url} alt="" className="absolute inset-0 h-full w-full object-cover opacity-30" />
                  )}
                  <div className="relative max-w-xl">
                    {section.heading && (
                      <h2 className={section.type === 'hero' ? 'text-4xl font-bold' : 'text-2xl font-semibold'}>{section.heading}</h2>
                    )}
                    {section.subheading && <p className="mt-3 text-lg text-sf-muted">{section.subheading}</p>}
                    <Link
                      href={section.cta_url ?? `${base}/products`}
                      className="mt-6 inline-block rounded-sf bg-sf-primary px-5 py-2 font-medium text-white"
                    >
                      Shop now
                    </Link>
                  </div>
                </section>
              );
            case 'featured_categories':
              return section.categories.length === 0 ? null : (
                <section key={index}>
                  <h2 className="mb-4 text-2xl font-semibold">{section.heading}</h2>
                  <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
                    {section.categories.map((category) => (
                      <Link
                        key={category.id}
                        href={`${base}/categories/${category.slug}`}
                        className="rounded-sf border border-sf-border p-4 hover:border-sf-accent"
                      >
                        <span className="font-medium">{category.name}</span>
                        <span className="block text-sm text-sf-muted">{category.product_count} products</span>
                      </Link>
                    ))}
                  </div>
                </section>
              );
            case 'featured_products':
              return section.products.length === 0 ? null : (
                <section key={index}>
                  <div className="mb-4 flex items-baseline justify-between">
                    <h2 className="text-2xl font-semibold">{section.heading}</h2>
                    <Link href={`${base}/products`} className="text-sm text-sf-accent">
                      View all
                    </Link>
                  </div>
                  <ProductGrid products={section.products} basePath={base} />
                </section>
              );
            default:
              return null;
          }
        })}
      </div>
    </StoreLayout>
  );
}
