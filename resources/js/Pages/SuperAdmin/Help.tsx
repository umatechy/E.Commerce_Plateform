import { useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import { Card, EmptyPanel, ErrorPanel, Skeleton, Tabs } from '@/Components/ui/Page';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import Badge, { StatusBadge } from '@/Components/ui/Badge';
import Button from '@/Components/ui/Button';
import { FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { adminErrorMessage, adminFetch, AdminApiError } from '@/lib/adminApi';
import { useAccess, useAuth } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { AUDIENCE_LABELS, type ReleaseNote } from '@/lib/help';

/**
 * Phase B54 — Super Admin → Help center (Module 34 §6–10, §28–29, §31,
 * §47.1). Every platform role can look; writing, reviewing and publishing
 * need the "help" capability, and the server checks it on every call.
 *
 * - Articles: filter by state, open one to see its live text, its open
 *   version (with what the checker finds), its history and its feedback;
 *   write, send for review, approve (someone else), send back, mark out of
 *   date, archive, put back.
 * - Feedback & searches: what readers said, and what they could not find.
 * - Release notes: publish a draft; that sets its release date.
 */
type Row = {
  id: number; key: string; locale: string; title: string; category: string; category_label: string; audience: string; type: string; status: string; source: string;
  revision: number; published_at: string | null; review_due_on: string | null; review_overdue: boolean; waiting_for_review: boolean; bundle_update_available: boolean;
  helpful: number; not_helpful: number; open_feedback: number;
};
type Meta = {
  categories: { key: string; label: string; audiences: string[] }[];
  audiences: Record<string, string>;
  types: Record<string, string>;
  locales: { code: string; name: string }[];
  troubleshooting_headings: string[];
  product_version: string;
};
type Content = {
  title: string; summary: string; body: string; category: string; type: string; audience: string; module: string | null; tags: string[]; keywords: string[];
  error_codes: string[]; contexts: string[]; related: string[]; applies_from: string | null; applies_until: string | null; owner: string | null; review_due_on: string | null;
};
type FeedbackRow = { id: number; article: { id: number; key: string; locale: string; title: string } | null; revision: number; helpful: boolean; reason_label: string | null; comment: string | null; surface: string; created_at: string; resolved_at: string | null };
type Detail = {
  id: number; key: string; locale: string; status: string; source: string; revision: number; published_at: string | null; reviewed_by: string | null; replaced_by: string | null;
  bundle_update_available: boolean; classification: string | null;
  live: (Content & { html: string }) | null;
  translation: { english_revision: number; source_revision: number | null; outdated: boolean } | null;
  open: { revision: number; state: string; content: Content; change_note: string | null; author: string | null; author_id: number | null; review_note: string | null; submitted_at: string | null; problems: string[] } | null;
  history: { revision: number; state: string; change_note: string | null; author: string | null; reviewer: string | null; review_note: string | null; created_at: string | null; decided_at: string | null }[];
  feedback: FeedbackRow[];
};

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'needs_review', label: 'Waiting for review' },
  { value: 'review_due', label: 'Review date passed' },
  { value: 'bundle_update', label: 'Newer built-in text' },
  { value: 'draft', label: 'Draft' },
  { value: 'published', label: 'Published' },
  { value: 'deprecated', label: 'Out of date' },
  { value: 'archived', label: 'Archived' },
];

export default function SuperAdminHelp() {
  const [tab, setTab] = useState('articles');

  return (
    <AdminPage title="Help center" description="Articles for shoppers, stores, developers and our own team; what readers said and searched for; and release notes.">
      <Tabs
        label="Help center sections"
        active={tab}
        onChange={setTab}
        tabs={[
          { id: 'articles', label: 'Articles' },
          { id: 'insights', label: 'Feedback & searches' },
          { id: 'releases', label: 'Release notes' },
        ]}
      />
      <div className="mt-4">
        {tab === 'articles' && <Articles />}
        {tab === 'insights' && <Insights />}
        {tab === 'releases' && <Releases />}
      </div>
    </AdminPage>
  );
}

function Articles() {
  const access = useAccess();
  const canWrite = access.platformCan('help');
  const [status, setStatus] = useState('');
  const [audience, setAudience] = useState('');
  const [q, setQ] = useState('');
  const list = useApi<{ data: Row[]; meta: Meta }>('/super-admin/help/articles', { status: status || undefined, audience: audience || undefined, q: q || undefined });
  const [openId, setOpenId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);

  const columns: Column<Row>[] = [
    {
      key: 'title', header: 'Article', render: (r) => (
        <span>
          <span className="font-medium text-slate-900">{r.title}</span>
          <span className="block text-xs text-slate-500">{r.key} · {r.locale.toUpperCase()} · {r.category_label}</span>
        </span>
      ),
    },
    { key: 'audience', header: 'For', render: (r) => AUDIENCE_LABELS[r.audience] ?? r.audience },
    {
      key: 'status', header: 'Status', priority: true, render: (r) => (
        <span className="flex flex-wrap gap-1">
          <StatusBadge status={r.status} label={r.status === 'deprecated' ? 'Out of date' : undefined} />
          {r.waiting_for_review && <Badge tone="blue">Review</Badge>}
          {r.review_overdue && <Badge tone="amber">Review due</Badge>}
          {r.bundle_update_available && <Badge tone="amber">Newer built-in</Badge>}
        </span>
      ),
    },
    { key: 'feedback', header: 'Helpful', render: (r) => <span title="Helpful / not helpful">{r.helpful} / {r.not_helpful}{r.open_feedback > 0 ? ` · ${r.open_feedback} to read` : ''}</span> },
    { key: 'updated', header: 'Published', render: (r) => (r.published_at ? formatDate(r.published_at) : '—') },
  ];

  return (
    <>
      <FilterBar>
        <SearchField label="Search" value={q} onChange={setQ} placeholder="Title or key" />
        <div className="w-48"><SelectField label="Status" value={status} onChange={setStatus} options={STATUS_FILTERS} /></div>
        <div className="w-48">
          <SelectField label="For" value={audience} onChange={setAudience} options={[{ value: '', label: 'Everyone' }, ...Object.entries(AUDIENCE_LABELS).map(([value, label]) => ({ value, label }))]} />
        </div>
        {canWrite && <Button variant="primary" onClick={() => setCreating(true)}>New article</Button>}
      </FilterBar>
      <DataTable
        caption="Help articles"
        columns={columns}
        rows={list.data?.data ?? null}
        rowKey={(r) => r.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        onRowClick={(r) => setOpenId(r.id)}
        empty={<EmptyPanel title="No articles match" description="Try another filter." />}
      />
      {openId !== null && list.data && <ArticleDrawer id={openId} meta={list.data.meta} canWrite={canWrite} onClose={() => setOpenId(null)} onChanged={list.reload} />}
      {creating && list.data && (
        <Editor
          meta={list.data.meta}
          onClose={() => setCreating(false)}
          onSaved={(id) => { setCreating(false); list.reload(); setOpenId(id); }}
        />
      )}
    </>
  );
}

function ArticleDrawer({ id, meta, canWrite, onClose, onChanged }: { id: number; meta: Meta; canWrite: boolean; onClose: () => void; onChanged: () => void }) {
  const auth = useAuth();
  const state = useApi<{ data: Detail }>(`/super-admin/help/articles/${id}`);
  const { busy, run } = useAction();
  const [editing, setEditing] = useState(false);
  const [asking, setAsking] = useState<null | 'reject' | 'deprecate' | 'archive'>(null);
  const [note, setNote] = useState('');
  const [replacement, setReplacement] = useState('');
  const d = state.data?.data;

  function act(step: string, body: Record<string, unknown> = {}, success = 'Done.') {
    return run(step, () => adminFetch(`/super-admin/help/articles/${id}/${step}`, { method: 'POST', body }), { success }).then((r) => {
      if (r !== undefined) { setAsking(null); setNote(''); state.reload(); onChanged(); }
    });
  }

  const own = d?.open?.author_id !== null && d?.open?.author_id !== undefined && String(d.open.author_id) === String(auth.user?.id);

  return (
    <Dialog open side wide title={d?.live?.title ?? d?.open?.content.title ?? 'Article'} description={d ? `${d.key} · ${d.locale.toUpperCase()} · ${d.source === 'bundled' ? 'Built in' : 'Written here'}` : undefined} onClose={onClose}>
      {state.error ? <ErrorPanel message={state.error} onRetry={state.reload} /> : !d ? <Skeleton lines={8} /> : (
        <div className="space-y-5 text-sm">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={d.status} label={d.status === 'deprecated' ? 'Out of date' : undefined} />
            {d.classification && <Badge>{d.classification}</Badge>}
            {d.revision > 0 && <span className="text-slate-600">Version {d.revision}{d.published_at ? `, published ${formatDateTime(d.published_at)}` : ''}{d.reviewed_by ? ` · reviewed by ${d.reviewed_by}` : ''}</span>}
          </div>
          {d.translation?.outdated && <p role="note" className="rounded-md bg-amber-50 p-3 text-amber-900">The English article changed after this translation was made. Update the translation.</p>}
          {d.replaced_by && <p className="text-slate-700">Replaced by <code>{d.replaced_by}</code>.</p>}
          {d.bundle_update_available && (
            <div className="rounded-md bg-amber-50 p-3 text-amber-900">
              The repository has a newer built-in text. This article was changed here, so it was not overwritten.
              {canWrite && <Button size="sm" className="ms-2" busy={busy === 'bundle-draft'} onClick={() => act('bundle-draft', {}, 'The newer built-in text is now the draft.')}>Load it as a draft</Button>}
            </div>
          )}

          {d.open && (
            <Card title={`Version ${d.open.revision}: ${d.open.state === 'in_review' ? 'waiting for review' : d.open.state === 'rejected' ? 'sent back' : 'draft'}`} description={d.open.author ? `By ${d.open.author}${d.open.change_note ? ` — ${d.open.change_note}` : ''}` : undefined}>
              {d.open.review_note && <p className="mb-2 rounded bg-slate-50 p-2 text-slate-700">Reviewer’s note: {d.open.review_note}</p>}
              {d.open.problems.length > 0 ? (
                <div role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 p-3 text-red-800">
                  <p className="font-medium">Fix before it can be published:</p>
                  <ul className="ms-5 list-disc">{d.open.problems.map((p) => <li key={p}>{p}</li>)}</ul>
                </div>
              ) : <p className="mb-3 text-emerald-800">The checker finds no problems.</p>}
              {canWrite && (
                <div className="flex flex-wrap gap-2">
                  {d.open.state !== 'in_review' && <Button size="sm" onClick={() => setEditing(true)}>Edit draft</Button>}
                  {d.open.state !== 'in_review' && <Button size="sm" variant="primary" disabled={d.open.problems.length > 0} busy={busy === 'submit'} onClick={() => act('submit', {}, 'Sent for review.')}>Send for review</Button>}
                  {d.open.state === 'in_review' && (
                    <>
                      <Button size="sm" variant="primary" disabled={own || d.open.problems.length > 0} title={own ? 'Someone else must review what you wrote' : undefined} busy={busy === 'approve'} onClick={() => act('approve', {}, 'Published.')}>Approve and publish</Button>
                      <Button size="sm" onClick={() => setAsking('reject')}>Send back</Button>
                    </>
                  )}
                </div>
              )}
              {own && d.open.state === 'in_review' && <p className="mt-2 text-xs text-slate-600">You wrote this version, so someone else with the help role reviews it.</p>}
            </Card>
          )}

          {canWrite && (
            <div className="flex flex-wrap gap-2">
              {!d.open && d.status !== 'archived' && <Button size="sm" onClick={() => setEditing(true)}>Write a new version</Button>}
              {d.status === 'published' && <Button size="sm" onClick={() => setAsking('deprecate')}>Mark out of date</Button>}
              {d.status !== 'archived' && <Button size="sm" variant="ghost" onClick={() => setAsking('archive')}>Archive</Button>}
              {(d.status === 'deprecated' || d.status === 'archived') && d.revision > 0 && <Button size="sm" busy={busy === 'reinstate'} onClick={() => act('reinstate', {}, 'Published again.')}>Publish again</Button>}
            </div>
          )}

          {d.live && (
            <Card title="What readers see now">
              <div className="help-article text-slate-800 [&_a]:text-indigo-700" dangerouslySetInnerHTML={{ __html: d.live.html }} />
            </Card>
          )}

          <Card title="History">
            <ul className="space-y-2">
              {d.history.map((h) => (
                <li key={h.revision} className="flex flex-wrap justify-between gap-2 border-b border-slate-100 pb-2">
                  <span>Version {h.revision} · <StatusBadge status={h.state} />{h.change_note ? ` ${h.change_note}` : ''}</span>
                  <span className="text-xs text-slate-500">{h.author ? `by ${h.author}` : ''}{h.reviewer ? ` · reviewed by ${h.reviewer}` : ''}{h.decided_at ? ` · ${formatDateTime(h.decided_at)}` : ''}</span>
                </li>
              ))}
            </ul>
          </Card>

          {d.feedback.length > 0 && (
            <Card title="Feedback">
              <FeedbackList rows={d.feedback} onChanged={state.reload} />
            </Card>
          )}
        </div>
      )}

      {editing && d && <Editor meta={meta} article={d} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); state.reload(); onChanged(); }} />}
      <ConfirmDialog
        open={asking !== null}
        title={asking === 'reject' ? 'Send this version back?' : asking === 'deprecate' ? 'Mark this article out of date?' : 'Archive this article?'}
        confirmLabel={asking === 'reject' ? 'Send back' : asking === 'deprecate' ? 'Mark out of date' : 'Archive'}
        variant={asking === 'archive' ? 'danger' : 'primary'}
        busy={busy !== null}
        onClose={() => setAsking(null)}
        onConfirm={() => asking && act(asking, asking === 'deprecate' ? { replaced_by: replacement || null, note: note || null } : { note: note || null }, asking === 'reject' ? 'Sent back.' : asking === 'deprecate' ? 'Marked out of date.' : 'Archived.')}
      >
        {asking === 'deprecate' && <TextField label="Replaced by (article key)" optional value={replacement} onChange={setReplacement} hint="Readers are sent there. Leave empty only if there is no replacement, and say why below." />}
        <TextAreaField label={asking === 'reject' ? 'What needs to change' : 'Note'} optional={asking !== 'reject'} value={note} onChange={setNote} rows={3} />
        {asking === 'archive' && <p className="text-sm text-slate-600">Readers no longer see it. You can publish it again later.</p>}
      </ConfirmDialog>
    </Dialog>
  );
}

const EMPTY: Content = { title: '', summary: '', body: '', category: '', type: 'how_to', audience: 'merchant', module: null, tags: [], keywords: [], error_codes: [], contexts: [], related: [], applies_from: null, applies_until: null, owner: '', review_due_on: null };

/** Write or change a version. A translation only has its own words; the rest comes from the English article. */
function Editor({ meta, article, onClose, onSaved }: { meta: Meta; article?: Detail; onClose: () => void; onSaved: (id: number) => void }) {
  const start = article?.open?.content ?? article?.live ?? EMPTY;
  const translation = article !== undefined && article.locale !== 'en';
  const [key, setKey] = useState(article?.key ?? '');
  const [locale, setLocale] = useState(article?.locale ?? 'en');
  const [v, setV] = useState<Record<string, string>>({
    title: start.title, summary: start.summary, body: start.body, category: start.category, type: start.type, audience: start.audience, module: start.module ?? '',
    tags: start.tags.join(', '), keywords: start.keywords.join(', '), error_codes: start.error_codes.join(', '), contexts: start.contexts.join(', '), related: start.related.join(', '),
    applies_from: start.applies_from ?? meta.product_version, applies_until: start.applies_until ?? '', owner: start.owner ?? '', review_due_on: start.review_due_on ?? '', change_note: '',
  });
  const [preview, setPreview] = useState<{ html: string; problems: string[] } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const { busy, run } = useAction();
  const set = (field: string) => (value: string) => setV((old) => ({ ...old, [field]: value }));
  const list = (value: string) => value.split(',').map((s) => s.trim()).filter(Boolean);
  const isTranslation = translation || locale !== 'en';

  function body() {
    return {
      title: v.title, summary: v.summary, body: v.body, keywords: list(v.keywords), change_note: v.change_note || null,
      ...(isTranslation ? {} : {
        category: v.category || null, type: v.type || null, audience: v.audience || null, module: v.module || null, tags: list(v.tags), error_codes: list(v.error_codes),
        contexts: list(v.contexts), related: list(v.related), applies_from: v.applies_from || null, applies_until: v.applies_until || null, owner: v.owner || null, review_due_on: v.review_due_on || null,
      }),
    };
  }

  function fail(e: unknown) {
    setError(adminErrorMessage(e));
    if (e instanceof AdminApiError) {
      const fields = (e.body?.errors ?? {}) as Record<string, string[]>;
      setErrors(Object.fromEntries(Object.entries(fields).map(([f, m]) => [f, m[0]])));
    }
  }

  function save() {
    setError(null);
    setErrors({});
    const request = article
      ? adminFetch<{ data: { revision: number } }>(`/super-admin/help/articles/${article.id}/draft`, { method: 'PUT', body: body() }).then(() => article.id)
      : adminFetch<{ data: { id: number } }>('/super-admin/help/articles', { method: 'POST', body: { key, locale, ...body() } }).then((r) => r.data.id);
    run('save', () => request, { success: 'Draft saved.', onError: () => undefined }).then((id) => id !== undefined && onSaved(id));
    request.catch(fail);
  }

  function showPreview() {
    run('preview', () => adminFetch<{ data: { html: string; problems: string[] } }>('/super-admin/help/preview', { method: 'POST', body: { key: key || 'new-article', locale, ...body() } }), { onError: setError })
      .then((r) => r && setPreview(r.data));
  }

  const categories = meta.categories.filter((c) => c.audiences.includes(v.audience)).map((c) => ({ value: c.key, label: c.label }));

  return (
    <Dialog
      open
      wide
      title={article ? `Edit: ${article.key} (${article.locale.toUpperCase()})` : 'New article'}
      description="Saved as a draft. Readers see nothing until someone else approves it."
      onClose={onClose}
      busy={busy === 'save'}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button busy={busy === 'preview'} onClick={showPreview}>Preview and check</Button>
          <Button variant="primary" busy={busy === 'save'} onClick={save}>Save draft</Button>
        </>
      }
    >
      <div className="space-y-4">
        <FormError message={error} />
        {!article && (
          <div className="grid gap-3 sm:grid-cols-2">
            <TextField label="Key" value={key} onChange={setKey} hint="Stable id in the address: small letters, digits and hyphens. For a translation, the English article’s key." error={errors.key} />
            <SelectField label="Language" value={locale} onChange={setLocale} options={meta.locales.map((l) => ({ value: l.code, label: l.name }))} />
          </div>
        )}
        <TextField label="Title" value={v.title} onChange={set('title')} maxLength={160} error={errors.title} />
        <TextField label="Summary" value={v.summary} onChange={set('summary')} maxLength={300} hint="One or two sentences, shown in lists and search results." error={errors.summary} />
        {!isTranslation && (
          <div className="grid gap-3 sm:grid-cols-3">
            <SelectField label="For" value={v.audience} onChange={set('audience')} options={Object.entries(meta.audiences).map(([value, label]) => ({ value, label }))} hint="Decides who can find it at all." />
            <SelectField label="Topic" value={v.category} onChange={set('category')} options={categories} placeholder="Choose a topic" error={errors.category} />
            <SelectField label="Kind" value={v.type} onChange={set('type')} options={Object.entries(meta.types).map(([value, label]) => ({ value, label }))} />
          </div>
        )}
        {!isTranslation && <TextField label="Owner" value={v.owner} onChange={set('owner')} hint="Who keeps it accurate, e.g. Orders (Module 09)" error={errors.owner} />}
        <TextAreaField
          label="Text (Markdown)"
          value={v.body}
          onChange={set('body')}
          rows={16}
          error={errors.body}
          hint={`## for a section, - for a list, **bold**, [text](help:other-article), [text](/orders), [text](store:/account). ${v.type === 'troubleshooting' ? `Troubleshooting sections: ${meta.troubleshooting_headings.join(', ')}.` : ''}`}
        />
        <TextField label="Search words" optional value={v.keywords} onChange={set('keywords')} hint="Comma-separated: the words readers might type." />
        {!isTranslation && (
          <details className="rounded-md border border-slate-200 p-3">
            <summary className="cursor-pointer text-sm font-medium">More: page help, links, version and ownership</summary>
            <div className="mt-3 grid gap-3 sm:grid-cols-2">
              <TextField label="Tags" optional value={v.tags} onChange={set('tags')} />
              <TextField label="Error codes" optional value={v.error_codes} onChange={set('error_codes')} hint="e.g. store_closed" />
              <TextField label="Help for admin pages" optional value={v.contexts} onChange={set('contexts')} hint="e.g. /orders, /orders/*" />
              <TextField label="Related articles" optional value={v.related} onChange={set('related')} hint="Article keys" />
              <TextField label="Blueprint module" optional value={v.module} onChange={set('module')} hint="01–35" />
              <TextField label="For version" value={v.applies_from} onChange={set('applies_from')} />
              <TextField label="Review by" optional type="date" value={v.review_due_on} onChange={set('review_due_on')} />
            </div>
          </details>
        )}
        <TextField label="What changed" optional value={v.change_note} onChange={set('change_note')} hint="For the history and the reviewer." />
        {preview && (
          <section aria-label="Preview" className="rounded-md border border-slate-200 p-4">
            {preview.problems.length > 0 ? (
              <div role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <ul className="ms-5 list-disc">{preview.problems.map((p) => <li key={p}>{p}</li>)}</ul>
              </div>
            ) : <p className="mb-3 text-sm text-emerald-800">The checker finds no problems.</p>}
            <div dir={locale === 'ur' ? 'rtl' : undefined} className="help-article text-sm text-slate-800" dangerouslySetInnerHTML={{ __html: preview.html }} />
          </section>
        )}
      </div>
    </Dialog>
  );
}

function FeedbackList({ rows, onChanged }: { rows: FeedbackRow[]; onChanged: () => void }) {
  const access = useAccess();
  const { busy, run } = useAction();

  return (
    <ul className="space-y-2 text-sm">
      {rows.map((f) => (
        <li key={f.id} className="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 pb-2">
          <span>
            <Badge tone={f.helpful ? 'green' : 'amber'}>{f.helpful ? 'Helpful' : 'Not helpful'}</Badge>{' '}
            {f.article && <span className="font-medium">{f.article.title} ({f.article.locale.toUpperCase()}, v{f.revision})</span>}
            {f.reason_label && <span className="text-slate-600"> · {f.reason_label}</span>}
            {f.comment && <span className="mt-1 block text-slate-800">“{f.comment}”</span>}
            <span className="block text-xs text-slate-500">{f.surface === 'storefront' ? 'A shopper' : 'Admin'} · {formatDateTime(f.created_at)}</span>
          </span>
          {f.resolved_at ? <span className="text-xs text-slate-500">Handled</span> : access.platformCan('help') && (
            <Button size="sm" variant="ghost" busy={busy === `r${f.id}`} onClick={() => run(`r${f.id}`, () => adminFetch(`/super-admin/help/feedback/${f.id}/resolve`, { method: 'POST' })).then((r) => r && onChanged())}>
              Mark handled
            </Button>
          )}
        </li>
      ))}
    </ul>
  );
}

type Insight = {
  searches_30d: number; zero_result_30d: number;
  top_searches: { query: string; surface: string; times: number; results: number }[];
  not_found: { query: string; surface: string; times: number }[];
  feedback_30d: { helpful: number; not_helpful: number };
  open_feedback: FeedbackRow[];
};

function Insights() {
  const state = useApi<{ data: Insight }>('/super-admin/help/insights');
  if (state.error) return <ErrorPanel message={state.error} onRetry={state.reload} />;
  if (!state.data) return <Skeleton lines={6} />;
  const d = state.data.data;

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">Last 30 days: {d.searches_30d} searches, {d.zero_result_30d} with no result; {d.feedback_30d.helpful} found an article helpful, {d.feedback_30d.not_helpful} did not. Searches are kept without who searched.</p>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Searches with no result" description="What readers looked for and did not find: the next articles to write.">
          {d.not_found.length === 0 ? <p className="text-sm text-slate-600">None.</p> : (
            <ul className="text-sm">{d.not_found.map((s) => <li key={s.surface + s.query} className="flex justify-between border-b border-slate-100 py-1"><span>{s.query} <span className="text-xs text-slate-500">({s.surface})</span></span><span>{s.times}×</span></li>)}</ul>
          )}
        </Card>
        <Card title="Most searched">
          {d.top_searches.length === 0 ? <p className="text-sm text-slate-600">None yet.</p> : (
            <ul className="text-sm">{d.top_searches.map((s) => <li key={s.surface + s.query} className="flex justify-between border-b border-slate-100 py-1"><span>{s.query} <span className="text-xs text-slate-500">({s.surface})</span></span><span>{s.times}×</span></li>)}</ul>
          )}
        </Card>
      </div>
      <Card title="Feedback to read" description="Not helpful, or with a comment, and not yet handled.">
        {d.open_feedback.length === 0 ? <p className="text-sm text-slate-600">Nothing to read.</p> : <FeedbackList rows={d.open_feedback} onChanged={state.reload} />}
      </Card>
    </div>
  );
}

function Releases() {
  const access = useAccess();
  const state = useApi<{ data: (ReleaseNote & { published_at: string | null })[] }>('/super-admin/help/releases');
  const { busy, run } = useAction();
  const [confirming, setConfirming] = useState<string | null>(null);

  if (state.error) return <ErrorPanel message={state.error} onRetry={state.reload} />;
  if (!state.data) return <Skeleton lines={4} />;
  if (state.data.data.length === 0) return <EmptyPanel title="No release notes" description="They come with each version of the code." />;

  return (
    <div className="space-y-4">
      {state.data.data.map((n) => (
        <Card
          key={n.version}
          title={`${n.title} (${n.version})`}
          description={n.status === 'published' ? `Published · released ${n.released_on ? formatDate(n.released_on) : ''}` : 'Draft: store teams do not see it yet.'}
          actions={n.status !== 'published' && access.platformCan('help') && <Button size="sm" variant="primary" onClick={() => setConfirming(n.version)}>Publish</Button>}
        >
          <p className="text-sm text-slate-700">{n.summary}</p>
          {n.sections.map((s) => (
            <div key={s.key} className="mt-3">
              <h3 className="text-sm font-semibold">{s.label}</h3>
              <ul className="mt-1 list-disc ps-5 text-sm">{s.items.map((item, i) => <li key={i} dangerouslySetInnerHTML={{ __html: item }} />)}</ul>
            </div>
          ))}
        </Card>
      ))}
      <ConfirmDialog
        open={confirming !== null}
        title={`Publish release notes ${confirming ?? ''}?`}
        confirmLabel="Publish"
        variant="primary"
        busy={busy === 'publish'}
        onClose={() => setConfirming(null)}
        onConfirm={() => run('publish', () => adminFetch(`/super-admin/help/releases/${confirming}/publish`, { method: 'POST' }), { success: 'Published.' }).then((r) => { if (r) { setConfirming(null); state.reload(); } })}
      >
        <p className="text-sm text-slate-700">Today becomes the release date, and every store team sees these notes under What’s new. Publish them when this version is live.</p>
      </ConfirmDialog>
    </div>
  );
}
