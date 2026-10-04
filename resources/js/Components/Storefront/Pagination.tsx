import { Link } from '@inertiajs/react';
import { useT } from '@/Storefront/i18n';

export default function Pagination({ page, lastPage, hrefFor }: { page: number; lastPage: number; hrefFor: (page: number) => string }) {
  const t = useT();
  if (lastPage <= 1) return null;

  return (
    <nav aria-label={t('Pages')} className="mt-8 flex items-center justify-center gap-4 text-sm">
      {page > 1 ? (
        <Link href={hrefFor(page - 1)} preserveScroll={false} className="rounded-sf border border-sf-border px-3 py-1">
          {t('Previous')}
        </Link>
      ) : (
        <span className="px-3 py-1 text-sf-muted">{t('Previous')}</span>
      )}
      <span>{t('Page {page} of {last}', { page, last: lastPage })}</span>
      {page < lastPage ? (
        <Link href={hrefFor(page + 1)} className="rounded-sf border border-sf-border px-3 py-1">
          {t('Next')}
        </Link>
      ) : (
        <span className="px-3 py-1 text-sf-muted">{t('Next')}</span>
      )}
    </nav>
  );
}
