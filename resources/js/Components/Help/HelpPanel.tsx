import { useState } from 'react';
import { Link } from '@inertiajs/react';
import Dialog from '@/Components/ui/Dialog';
import { SearchField } from '@/Components/ui/Filters';
import { ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { FOCUS_RING } from '@/Components/ui/Button';
import { ArticleList } from '@/Components/Help/ArticleView';
import { useApi } from '@/lib/useApi';
import type { HelpSummary } from '@/lib/help';

/**
 * Phase B54 (Module 34 §12 contextual help, §44 in-app support) — the
 * Help button in the admin's top bar opens this panel on any page:
 * articles about the page you are on, search across the help center, and
 * the way to the help center, release notes and Umar Techy support.
 *
 * The server decides which articles the reader may see; a search answers
 * only with those (Module 34 §62).
 */
export function HelpButton({ path, unseenRelease, canAskPlatform }: { path: string; unseenRelease: string | null; canAskPlatform: boolean }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-haspopup="dialog"
        className={`relative flex items-center gap-1 rounded-md px-2 py-1 text-sm text-slate-700 hover:bg-slate-100 ${FOCUS_RING}`}
      >
        <span aria-hidden="true" className="inline-flex h-5 w-5 items-center justify-center rounded-full border border-slate-400 text-xs font-semibold">
          ?
        </span>
        <span className="hidden sm:inline">Help</span>
        {unseenRelease && (
          <>
            <span aria-hidden="true" className="absolute right-1 top-1 h-2 w-2 rounded-full bg-indigo-600" />
            <span className="sr-only">. New: what changed in version {unseenRelease}</span>
          </>
        )}
      </button>
      {open && <HelpPanel path={path} unseenRelease={unseenRelease} canAskPlatform={canAskPlatform} onClose={() => setOpen(false)} />}
    </>
  );
}

function HelpPanel({ path, unseenRelease, canAskPlatform, onClose }: { path: string; unseenRelease: string | null; canAskPlatform: boolean; onClose: () => void }) {
  const [query, setQuery] = useState('');
  const searching = query.trim().length >= 2;
  const page = useApi<{ data: HelpSummary[] }>('/help/page', { path });
  const results = useApi<{ data: HelpSummary[] }>(searching ? '/help/search' : null, { q: query.trim() });

  return (
    <Dialog open side title="Help" description="Answers for the page you are on, or search the help center." onClose={onClose}>
      <div className="space-y-6">
        <SearchField label="Search help" value={query} onChange={setQuery} placeholder="For example: cash on delivery, refund, store_closed" />

        {searching ? (
          <section aria-labelledby="help-results" aria-live="polite">
            <h3 id="help-results" className="mb-1 text-sm font-semibold text-slate-900">
              Results
            </h3>
            {results.error ? (
              <ErrorPanel message={results.error} onRetry={results.reload} />
            ) : results.data === null || results.loading ? (
              <Skeleton lines={3} />
            ) : results.data.data.length === 0 ? (
              <p className="text-sm text-slate-600">
                No article matches “{query.trim()}”. Try other words
                {canAskPlatform ? (
                  <>
                    , or{' '}
                    <Link href="/support/platform" onClick={onClose} className="text-indigo-700 underline">
                      ask Umar Techy
                    </Link>
                  </>
                ) : null}
                .
              </p>
            ) : (
              <ArticleList articles={results.data.data} onOpen={onClose} />
            )}
          </section>
        ) : (
          <section aria-labelledby="help-page">
            <h3 id="help-page" className="mb-1 text-sm font-semibold text-slate-900">
              On this page
            </h3>
            {page.error ? (
              <ErrorPanel message={page.error} onRetry={page.reload} />
            ) : page.data === null ? (
              <Skeleton lines={2} />
            ) : page.data.data.length === 0 ? (
              <p className="text-sm text-slate-600">There is no article about this page yet. Search above, or browse the help center.</p>
            ) : (
              <ArticleList articles={page.data.data} onOpen={onClose} />
            )}
          </section>
        )}

        <nav aria-label="More help" className="space-y-1 border-t border-slate-200 pt-4 text-sm">
          <PanelLink href="/help" onClose={onClose} label="Browse the help center" />
          <PanelLink href="/help/whats-new" onClose={onClose} label={unseenRelease ? `What's new in ${unseenRelease}` : "What's new"} badge={unseenRelease !== null} />
          {canAskPlatform && <PanelLink href="/support/platform" onClose={onClose} label="Ask Umar Techy support" />}
        </nav>
      </div>
    </Dialog>
  );
}

function PanelLink({ href, label, onClose, badge = false }: { href: string; label: string; onClose: () => void; badge?: boolean }) {
  return (
    <Link href={href} onClick={onClose} className={`flex items-center justify-between rounded-md px-2 py-1.5 text-indigo-700 hover:bg-slate-50 ${FOCUS_RING}`}>
      <span>{label}</span>
      {badge && <span className="rounded-full bg-indigo-600 px-2 py-0.5 text-[10px] font-semibold uppercase text-white">New</span>}
    </Link>
  );
}
