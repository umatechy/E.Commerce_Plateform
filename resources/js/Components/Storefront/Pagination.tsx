import { Link } from '@inertiajs/react';

export default function Pagination({ page, lastPage, hrefFor }: { page: number; lastPage: number; hrefFor: (page: number) => string }) {
  if (lastPage <= 1) return null;

  return (
    <nav aria-label="Pages" className="mt-8 flex items-center justify-center gap-4 text-sm">
      {page > 1 ? (
        <Link href={hrefFor(page - 1)} preserveScroll={false} className="rounded-sf border border-sf-border px-3 py-1">
          Previous
        </Link>
      ) : (
        <span className="px-3 py-1 text-sf-muted">Previous</span>
      )}
      <span>
        Page {page} of {lastPage}
      </span>
      {page < lastPage ? (
        <Link href={hrefFor(page + 1)} className="rounded-sf border border-sf-border px-3 py-1">
          Next
        </Link>
      ) : (
        <span className="px-3 py-1 text-sf-muted">Next</span>
      )}
    </nav>
  );
}
