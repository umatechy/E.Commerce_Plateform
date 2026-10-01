import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, TextAreaField, TextField } from '@/Components/ui/Form';
import { StatusBadge } from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { useAccess, useAuth } from '@/lib/access';
import { usePagedApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { dateTimeOrDash } from '@/lib/datetime';

/**
 * Module 16 content pages (About, Returns, ...): /api/v1/content-pages.
 * The server cleans the page text (ContentSanitizer) before storing it,
 * so scripts and unsafe markup never reach the storefront; this page
 * does not try to do that itself. The server also decides which status
 * may follow which. Changing a published page's address makes the server
 * add a redirect from the old one.
 */
type ContentPage = { id: number; title: string; slug: string; body: string; status: string; published_at: string | null };

/** The moves the server allows from each status (ContentPageService). Scheduling is left out: the API takes no publish date. */
const MOVES: Record<string, { to: string; label: string; confirm?: string }[]> = {
  draft: [{ to: 'published', label: 'Publish' }, { to: 'archived', label: 'Archive', confirm: 'An archived page cannot be brought back.' }],
  scheduled: [{ to: 'published', label: 'Publish' }, { to: 'draft', label: 'Back to draft' }],
  published: [{ to: 'unpublished', label: 'Unpublish', confirm: 'The page disappears from your storefront until you publish it again.' }],
  unpublished: [{ to: 'published', label: 'Publish' }, { to: 'draft', label: 'Back to draft' }, { to: 'archived', label: 'Archive', confirm: 'An archived page cannot be brought back.' }],
};

const BLANK = { title: '', slug: '', body: '' };

export default function Pages() {
  const access = useAccess();
  const auth = useAuth();
  const canManage = access.can('seo.manage');
  const [page, setPage] = useState(1);
  const list = usePagedApi<ContentPage>('/content-pages', { page });
  const [editing, setEditing] = useState<ContentPage | 'new' | null>(null);
  const [confirming, setConfirming] = useState<{ page: ContentPage; to: string; label: string; text: string } | null>(null);
  const form = useForm(BLANK);
  const { busy, run } = useAction();

  function open(target: ContentPage | 'new') {
    form.reset(target === 'new' ? BLANK : { title: target.title, slug: target.slug, body: target.body });
    setEditing(target);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const target = editing;
    const saved = await form.submit(
      () => adminFetch(target === 'new' ? '/content-pages' : `/content-pages/${(target as ContentPage).id}`, { method: target === 'new' ? 'POST' : 'PUT', body: form.values }),
      target === 'new' ? 'Page saved as a draft.' : 'Page saved.',
    );
    if (saved !== undefined) {
      setEditing(null);
      list.reload();
    }
  }

  function move(target: ContentPage, to: string, label: string) {
    return run(`${to}:${target.id}`, () => adminFetch(`/content-pages/${target.id}/transition`, { method: 'POST', body: { status: to } }), { success: `${label}: done.` }).then((result) => {
      if (result !== undefined) list.reload();

      return result;
    });
  }

  const columns: Column<ContentPage>[] = [
    {
      key: 'title',
      header: 'Page',
      render: (row) => (
        <div>
          <span className="font-medium">{row.title}</span>
          <p className="font-mono text-xs text-slate-500">/pages/{row.slug}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (row) => <StatusBadge status={row.status} /> },
    { key: 'published', header: 'First published', render: (row) => dateTimeOrDash(row.published_at) },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (row) => (
        <span className="flex flex-wrap justify-end gap-1">
          {row.status === 'published' && auth.activeStore && (
            <a href={`/shop/${auth.activeStore.slug}/pages/${row.slug}`} target="_blank" rel="noreferrer" className="rounded px-2.5 py-1 text-sm text-indigo-700 hover:underline">
              View<span className="sr-only"> {row.title} (opens in a new tab)</span>
            </a>
          )}
          {canManage && row.status !== 'archived' && <Button size="sm" variant="ghost" onClick={() => open(row)}>Edit<span className="sr-only"> {row.title}</span></Button>}
          {canManage &&
            (MOVES[row.status] ?? []).map((option) => (
              <Button
                key={option.to}
                size="sm"
                variant="ghost"
                busy={busy === `${option.to}:${row.id}`}
                onClick={() => (option.confirm ? setConfirming({ page: row, to: option.to, label: option.label, text: option.confirm }) : move(row, option.to, option.label))}
              >
                {option.label}
              </Button>
            ))}
        </span>
      ),
    },
  ];

  return (
    <AdminPage title="Pages" description="Content pages of your storefront, such as About us or Returns." actions={canManage && <Button variant="primary" onClick={() => open('new')}>Add page</Button>}>
      <DataTable
        caption="Content pages"
        columns={columns}
        rows={list.rows}
        rowKey={(row) => row.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No pages yet" description="Add pages your customers expect, like About us or your returns policy." action={canManage ? <Button variant="primary" onClick={() => open('new')}>Add page</Button> : undefined} />}
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />

      <Dialog open={editing !== null} wide title={editing === 'new' ? 'Add page' : 'Edit page'} description={editing === 'new' ? 'Saved as a draft. Publish it from the list when it is ready.' : undefined} onClose={() => setEditing(null)} busy={form.busy}>
        <form onSubmit={save} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <TextField label="Title" value={form.values.title} onChange={(v) => form.set('title', v)} error={form.errors.title} required maxLength={255} data-autofocus />
          <TextField label="Address" value={form.values.slug} onChange={(v) => form.set('slug', v)} error={form.errors.slug} required maxLength={255} hint="Letters, numbers and dashes. The page opens at /pages/ followed by this." />
          <TextAreaField label="Text" rows={12} value={form.values.body} onChange={(v) => form.set('body', v)} error={form.errors.body} required maxLength={50000} hint="The server removes scripts and unsafe markup before the page is stored." />
          <div className="flex justify-end gap-2">
            <Button onClick={() => setEditing(null)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save page</Button>
          </div>
        </form>
      </Dialog>

      <ConfirmDialog
        open={confirming !== null}
        title={confirming ? `${confirming.label} this page?` : ''}
        confirmLabel={confirming?.label ?? ''}
        busy={busy !== null}
        onClose={() => setConfirming(null)}
        onConfirm={() => confirming && move(confirming.page, confirming.to, confirming.label).then((result) => result !== undefined && setConfirming(null))}
      >
        <p>
          <strong>{confirming?.page.title}</strong>: {confirming?.text}
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}
