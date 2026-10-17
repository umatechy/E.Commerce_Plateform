import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import { Card, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { SearchField } from '@/Components/ui/Filters';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import { ArticleList } from '@/Components/Help/ArticleView';
import { useApi } from '@/lib/useApi';
import { useAccess } from '@/lib/access';
import { formatDate } from '@/lib/datetime';
import type { HelpHome, HelpSummary } from '@/lib/help';

/**
 * Phase B54 (Module 34 §5–6, §11, §44) — the admin help center: search,
 * browse by topic, start here, most helpful, and the latest release.
 * Only articles this reader may open are ever sent by the server.
 */
export default function HelpIndex() {
  const access = useAccess();
  const home = useApi<{ data: HelpHome }>('/help');
  const [query, setQuery] = useState('');
  const [category, setCategory] = useState<string | null>(null);
  const searching = query.trim().length >= 2;
  const results = useApi<{ data: HelpSummary[] }>(searching ? '/help/search' : null, { q: query.trim() });
  const inCategory = useApi<{ data: { key: string; label: string; articles: HelpSummary[] } }>(category && !searching ? `/help/categories/${category}` : null);

  return (
    <AdminPage title="Help center" description="Guides, answers and fixes for running your store on Umar Techy." trail={[{ label: 'Help center' }]}>
      <div className="space-y-6">
        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <SearchField label="Search the help center" value={query} onChange={setQuery} placeholder="For example: launch, cash on delivery, refund, store_closed" />
          <p className="mt-2 text-xs text-slate-500">You can also search for an error code you saw, such as store_closed.</p>
        </div>

        {searching ? (
          <Card title={`Results for “${query.trim()}”`}>
            {results.error ? (
              <ErrorPanel message={results.error} onRetry={results.reload} />
            ) : results.data === null || results.loading ? (
              <Skeleton lines={4} />
            ) : results.data.data.length === 0 ? (
              <NothingFound canAsk={access.hasStore} />
            ) : (
              <ArticleList articles={results.data.data} />
            )}
          </Card>
        ) : home.error ? (
          <ErrorPanel message={home.error} onRetry={home.reload} />
        ) : home.data === null ? (
          <Skeleton lines={6} />
        ) : (
          <>
            {category ? (
              <Card
                title={inCategory.data?.data.label ?? 'Topic'}
                actions={
                  <Button size="sm" variant="ghost" onClick={() => setCategory(null)}>
                    All topics
                  </Button>
                }
              >
                {inCategory.error ? <ErrorPanel message={inCategory.error} onRetry={inCategory.reload} /> : inCategory.data === null ? <Skeleton lines={4} /> : <ArticleList articles={inCategory.data.data.articles} />}
              </Card>
            ) : (
              <section aria-labelledby="topics">
                <h2 id="topics" className="mb-3 text-sm font-semibold text-slate-900">
                  Browse by topic
                </h2>
                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {home.data.data.categories.map((c) => (
                    <li key={c.key}>
                      <button
                        type="button"
                        onClick={() => setCategory(c.key)}
                        className={`flex w-full items-center justify-between rounded-lg border border-slate-200 bg-white p-4 text-left hover:border-slate-400 ${FOCUS_RING}`}
                      >
                        <span className="font-medium text-slate-900">{c.label}</span>
                        <span className="text-xs text-slate-500">
                          {c.count} article{c.count === 1 ? '' : 's'}
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              </section>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
              {home.data.data.getting_started.length > 0 && (
                <Card title="Start here">
                  <ArticleList articles={home.data.data.getting_started} />
                </Card>
              )}
              <div className="space-y-4">
                {home.data.data.latest_release && (
                  <Card
                    title={`What's new in ${home.data.data.latest_release.version}`}
                    description={home.data.data.latest_release.released_on ? `Released ${formatDate(home.data.data.latest_release.released_on)}` : undefined}
                  >
                    <p className="text-sm text-slate-700">{home.data.data.latest_release.summary}</p>
                    <Link href="/help/whats-new" className={`mt-2 inline-block rounded text-sm font-medium text-brand-700 hover:underline ${FOCUS_RING}`}>
                      Read the release notes
                    </Link>
                  </Card>
                )}
                {home.data.data.popular.length > 0 && (
                  <Card title="Most helpful">
                    <ArticleList articles={home.data.data.popular} />
                  </Card>
                )}
                {access.hasStore && (
                  <Card title="Still need help?">
                    <p className="text-sm text-slate-700">Our team answers questions about your plan, billing and technical problems.</p>
                    <Link href="/support/platform" className={`mt-2 inline-block rounded text-sm font-medium text-brand-700 hover:underline ${FOCUS_RING}`}>
                      Ask Umar Techy support
                    </Link>
                  </Card>
                )}
              </div>
            </div>
            <p className="text-xs text-slate-500">Help for Umar Techy {home.data.data.product_version}.</p>
          </>
        )}
      </div>
    </AdminPage>
  );
}

function NothingFound({ canAsk }: { canAsk: boolean }) {
  return (
    <div className="text-sm text-slate-700">
      <p>No article matches. Try fewer or other words, or an error code you saw.</p>
      {canAsk && (
        <p className="mt-2">
          Still stuck?{' '}
          <Link href="/support/platform" className="text-brand-700 underline">
            Ask Umar Techy support
          </Link>
          . We note what people search for and could not find, and write the missing articles.
        </p>
      )}
    </div>
  );
}
