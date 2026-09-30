import StoreLayout from '@/Components/Storefront/StoreLayout';
import type { StorefrontPageProps } from '@/Storefront/types';

/**
 * A published content page. body_html was rebuilt by the server's
 * parser-based HtmlSanitizer (element/attribute allow-list, safe URLs
 * only) — the only HTML this storefront ever inserts.
 */
export default function Page({ storefront, seo, page }: StorefrontPageProps & { page: { title: string; body_html: string } }) {
  return (
    <StoreLayout shell={storefront} seo={seo}>
      <article className="prose mx-auto max-w-3xl">
        <h1 className="mb-6 text-3xl font-bold">{page.title}</h1>
        <div className="space-y-4 leading-relaxed" dangerouslySetInnerHTML={{ __html: page.body_html }} />
      </article>
    </StoreLayout>
  );
}
