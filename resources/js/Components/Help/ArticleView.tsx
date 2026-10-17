import { useState } from 'react';
import { Link } from '@inertiajs/react';
import Badge from '@/Components/ui/Badge';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import { SelectField, TextAreaField } from '@/Components/ui/Form';
import { adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { formatDate } from '@/lib/datetime';
import { articleHref, FEEDBACK_REASONS, type HelpArticle, type HelpSummary } from '@/lib/help';

/**
 * Phase B54 — one help article in the admin: its text (HTML the server
 * built from Markdown and sanitized; the only HTML this view inserts),
 * when it was updated and for which version, a notice when it is out of
 * date, related articles, and "Was this helpful?".
 */
export default function ArticleView({ article, compact = false }: { article: HelpArticle; compact?: boolean }) {
  return (
    <article aria-labelledby={`help-${article.key}`}>
      {!compact && (
        <header className="mb-4">
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
            {article.category_label} · {article.type_label}
            {article.audience === 'platform' && (
              <>
                {' '}
                <Badge tone="amber">Internal</Badge>
              </>
            )}
          </p>
          <h1 id={`help-${article.key}`} className="mt-1 text-2xl font-semibold text-slate-900">
            {article.title}
          </h1>
          <p className="mt-2 text-slate-600">{article.summary}</p>
        </header>
      )}
      {compact && (
        <h2 id={`help-${article.key}`} className="text-lg font-semibold text-slate-900">
          {article.title}
        </h2>
      )}

      {article.status === 'deprecated' && (
        <div role="note" className="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          This article is out of date.{' '}
          {article.replaced_by ? (
            <>
              Read{' '}
              <Link href={articleHref(article.replaced_by.key)} className={`font-semibold underline ${FOCUS_RING}`}>
                {article.replaced_by.title}
              </Link>{' '}
              instead.
            </>
          ) : (
            'It is kept for reference.'
          )}
        </div>
      )}
      {!article.current_version && article.status !== 'deprecated' && (
        <p role="note" className="mb-4 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
          Written for version {article.applies_from}. Some details may differ in the version you use.
        </p>
      )}

      <div className="help-article text-sm text-slate-800 [&_a]:text-brand-700" dangerouslySetInnerHTML={{ __html: article.html }} />

      <p className="mt-6 text-xs text-slate-500">
        {article.updated_at ? `Updated ${formatDate(article.updated_at)} · ` : ''}For Umar Techy {article.applies_from}
        {article.applies_until ? `–${article.applies_until}` : ' and later'}
      </p>

      <Feedback articleKey={article.key} />

      {article.related.length > 0 && (
        <section aria-labelledby={`related-${article.key}`} className="mt-6 border-t border-slate-200 pt-4">
          <h2 id={`related-${article.key}`} className="mb-2 text-sm font-semibold text-slate-900">
            Related articles
          </h2>
          <ArticleList articles={article.related} />
        </section>
      )}
    </article>
  );
}

export function ArticleList({ articles, onOpen }: { articles: HelpSummary[]; onOpen?: () => void }) {
  return (
    <ul className="divide-y divide-slate-100">
      {articles.map((item) => (
        <li key={item.key} className="py-2">
          <Link href={articleHref(item.key)} onClick={onOpen} className={`block rounded ${FOCUS_RING}`}>
            <span className="font-medium text-brand-700 hover:underline">{item.title}</span>
            {item.status === 'deprecated' && (
              <>
                {' '}
                <Badge tone="amber">Out of date</Badge>
              </>
            )}
            {item.audience === 'platform' && (
              <>
                {' '}
                <Badge tone="amber">Internal</Badge>
              </>
            )}
            <span className="mt-0.5 block text-sm text-slate-600">{item.summary}</span>
          </Link>
        </li>
      ))}
    </ul>
  );
}

/** "Was this helpful?" (Module 34 §28). One answer per article view; nothing about the reader is stored. */
function Feedback({ articleKey }: { articleKey: string }) {
  const [state, setState] = useState<'ask' | 'why' | 'sending' | 'done'>('ask');
  const [reason, setReason] = useState('');
  const [comment, setComment] = useState('');
  const [error, setError] = useState<string | null>(null);

  function send(helpful: boolean) {
    setState('sending');
    setError(null);
    adminFetch(`/help/articles/${articleKey}/feedback`, { method: 'POST', body: { helpful, reason: helpful ? null : reason || null, comment: comment.trim() || null } })
      .then(() => setState('done'))
      .catch((e) => {
        setError(adminErrorMessage(e, 'Your feedback could not be sent. Please try again.'));
        setState(helpful ? 'ask' : 'why');
      });
  }

  if (state === 'done') {
    return (
      <p role="status" className="mt-6 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
        Thank you. Your feedback helps us improve this article.
      </p>
    );
  }

  return (
    <section aria-label="Feedback on this article" className="mt-6 rounded-md border border-slate-200 bg-slate-50 p-3">
      {state !== 'why' ? (
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className="font-medium text-slate-800">Was this helpful?</span>
          <Button size="sm" busy={state === 'sending'} onClick={() => send(true)}>
            Yes
          </Button>
          <Button size="sm" disabled={state === 'sending'} onClick={() => setState('why')}>
            No
          </Button>
        </div>
      ) : (
        <div className="space-y-3">
          <SelectField label="What was wrong?" value={reason} onChange={setReason} options={FEEDBACK_REASONS} placeholder="Choose one" />
          <TextAreaField label="Tell us more" optional value={comment} onChange={setComment} rows={3} hint="Please do not include passwords or personal details." maxLength={500} />
          <div className="flex gap-2">
            <Button size="sm" variant="primary" onClick={() => send(false)}>
              Send
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setState('ask')}>
              Cancel
            </Button>
          </div>
        </div>
      )}
      {error && (
        <p role="alert" className="mt-2 text-sm text-red-700">
          {error}
        </p>
      )}
    </section>
  );
}
