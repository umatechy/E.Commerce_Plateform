import { ReactNode } from 'react';
import Button from './Button';
import type { PageMeta } from '@/lib/useApi';

/**
 * The admin's table (Phase B31 design system). One component so every
 * list has the same four states (loading, error with "Try again", empty,
 * rows) and the same mobile behaviour.
 *
 * Mobile strategy: the first column and any column marked `priority`
 * stay; the others are hidden below the `sm` breakpoint, and the table
 * scrolls sideways inside its own box instead of pushing the page wide.
 * Rows come from the server one page at a time; the table never sorts
 * or filters a list it only has one page of.
 */
export type Column<T> = {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  /** Kept on small screens. The first column always is. */
  priority?: boolean;
  align?: 'left' | 'right';
  /** Header shown to screen readers only (e.g. an actions column). */
  srOnlyHeader?: boolean;
};

type Props<T> = {
  caption: string;
  columns: Column<T>[];
  rows: T[] | null;
  rowKey: (row: T) => string | number;
  loading?: boolean;
  error?: string | null;
  onRetry?: () => void;
  empty: ReactNode;
  onRowClick?: (row: T) => void;
};

function cellVisibility<T>(column: Column<T>, index: number): string {
  return index === 0 || column.priority ? '' : 'hidden sm:table-cell';
}

export default function DataTable<T>({ caption, columns, rows, rowKey, loading = false, error, onRetry, empty, onRowClick }: Props<T>) {
  if (error) {
    return (
      <div role="alert" className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        <p>{error}</p>
        {onRetry && (
          <Button size="sm" className="mt-3" onClick={onRetry}>
            Try again
          </Button>
        )}
      </div>
    );
  }

  if (rows !== null && rows.length === 0 && !loading) return <>{empty}</>;

  return (
    <div className="overflow-x-auto rounded-md border border-slate-200 bg-white" aria-busy={loading || undefined}>
      <table className="w-full text-left text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
          <tr>
            {columns.map((column, index) => (
              <th key={column.key} scope="col" className={`px-3 py-2 font-medium ${column.align === 'right' ? 'text-right' : ''} ${cellVisibility(column, index)}`}>
                {column.srOnlyHeader ? <span className="sr-only">{column.header}</span> : column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className={loading && rows !== null ? 'opacity-60' : ''}>
          {rows === null
            ? [0, 1, 2, 3, 4].map((line) => (
                <tr key={line} className="border-b border-slate-100 last:border-0">
                  {columns.map((column, index) => (
                    <td key={column.key} className={`px-3 py-3 ${cellVisibility(column, index)}`}>
                      <span className="block h-4 w-3/4 animate-pulse rounded bg-slate-200 motion-reduce:animate-none" />
                    </td>
                  ))}
                </tr>
              ))
            : rows.map((row) => (
                <tr
                  key={rowKey(row)}
                  className={`border-b border-slate-100 last:border-0 ${onRowClick ? 'cursor-pointer hover:bg-slate-50' : ''}`}
                  onClick={onRowClick ? () => onRowClick(row) : undefined}
                >
                  {columns.map((column, index) => (
                    <td key={column.key} className={`px-3 py-2.5 align-middle ${column.align === 'right' ? 'text-right' : ''} ${cellVisibility(column, index)}`}>
                      {column.render(row)}
                    </td>
                  ))}
                </tr>
              ))}
        </tbody>
      </table>
      {rows === null && <p className="sr-only" role="status">Loading…</p>}
    </div>
  );
}

/** Previous / next over a server-paged list. Hidden when there is a single page. */
export function Pagination({ meta, onPage, disabled = false }: { meta: PageMeta | null; onPage: (page: number) => void; disabled?: boolean }) {
  if (meta === null || meta.lastPage <= 1) {
    return meta !== null && meta.total > 0 ? <p className="mt-3 text-sm text-slate-600">{meta.total} in total</p> : null;
  }

  return (
    <nav aria-label="Pagination" className="mt-3 flex items-center justify-between gap-3 text-sm text-slate-600">
      <p>
        Page {meta.page} of {meta.lastPage} · {meta.total} in total
      </p>
      <div className="flex gap-2">
        <Button size="sm" disabled={disabled || meta.page <= 1} onClick={() => onPage(meta.page - 1)}>
          Previous
        </Button>
        <Button size="sm" disabled={disabled || meta.page >= meta.lastPage} onClick={() => onPage(meta.page + 1)}>
          Next
        </Button>
      </div>
    </nav>
  );
}
