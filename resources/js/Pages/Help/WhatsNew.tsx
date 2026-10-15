import { useEffect } from 'react';
import { router } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import { Card, EmptyPanel, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { adminFetch } from '@/lib/adminApi';
import { formatDate } from '@/lib/datetime';
import { useApi } from '@/lib/useApi';
import type { ReleaseNote } from '@/lib/help';

/**
 * Phase B54 (SRS SUP-006; Module 34 §31–32, §52) — release notes, newest
 * first, sectioned like a changelog. Opening the page marks the newest
 * release as seen, so the "New" mark on Help goes away.
 */
export default function WhatsNew() {
  const state = useApi<{ data: ReleaseNote[]; meta: { unseen_release: string | null } }>('/help/releases');
  const unseen = state.data?.meta.unseen_release ?? null;

  useEffect(() => {
    if (unseen === null) return;
    adminFetch('/help/releases/seen', { method: 'POST' })
      .then(() => router.reload({ only: ['auth'] }))
      .catch(() => undefined);
  }, [unseen]);

  return (
    <AdminPage title="What's new" description="What changed in each version of Umar Techy." trail={[{ label: 'Help center', href: '/help' }, { label: "What's new" }]}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : state.data === null ? (
        <Skeleton lines={8} />
      ) : state.data.data.length === 0 ? (
        <EmptyPanel title="No release notes yet" description="They appear here when a new version is released." />
      ) : (
        <div className="mx-auto max-w-3xl space-y-6">
          {state.data.data.map((note) => (
            <Card key={note.version} title={`${note.title}`} description={note.released_on ? `Version ${note.version} · released ${formatDate(note.released_on)}` : `Version ${note.version}`}>
              <p className="text-sm text-slate-700">{note.summary}</p>
              {note.sections.map((section) => (
                <section key={section.key} className="mt-5" aria-labelledby={`${note.version}-${section.key}`}>
                  <h3 id={`${note.version}-${section.key}`} className={`text-sm font-semibold ${section.key === 'breaking' || section.key === 'migration' ? 'text-amber-800' : 'text-slate-900'}`}>
                    {section.label}
                  </h3>
                  <ul className="mt-2 list-disc space-y-1 ps-5 text-sm text-slate-800 [&_code]:rounded [&_code]:bg-slate-100 [&_code]:px-1">
                    {section.items.map((item, i) => (
                      // Built by the server from Markdown and sanitized.
                      <li key={i} dangerouslySetInnerHTML={{ __html: item }} />
                    ))}
                  </ul>
                </section>
              ))}
            </Card>
          ))}
        </div>
      )}
    </AdminPage>
  );
}
