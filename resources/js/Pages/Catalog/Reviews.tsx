import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { EmptyPanel } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { formatDate } from '@/lib/datetime';

/**
 * Owner decision 15 (Module 05 §27 "store administrators must control
 * moderation"): reviews from customers who bought the product. Shoppers see
 * a review only after it is approved; the product's rating counts approved
 * reviews only. Business and Premium.
 */
export type AdminReview = {
  id: number;
  rating: number;
  title: string | null;
  body: string;
  author: string;
  status: 'pending' | 'approved' | 'rejected';
  verified_purchase: boolean;
  reply: string | null;
  created_at: string;
  product: { id: string; name: string; slug: string } | null;
};
type ReviewList = { data: AdminReview[]; meta: { current_page: number; last_page: number; total: number; per_page: number }; counts: Record<string, number> };

const STATUS_TONE = { pending: 'amber', approved: 'green', rejected: 'red' } as const;
const STATUS_LABEL = { pending: 'Waiting', approved: 'Published', rejected: 'Rejected' } as const;

export default function Reviews() {
  const [status, setStatus] = useState('pending');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const list = useApi<ReviewList>('/reviews', { status, search: search.trim(), page: String(page) });
  const [replying, setReplying] = useState<AdminReview | null>(null);
  const [removing, setRemoving] = useState<AdminReview | null>(null);
  const { busy, run } = useAction();
  const counts = list.data?.counts ?? {};

  function moderate(review: AdminReview, to: 'approved' | 'rejected') {
    void run(`m${review.id}`, () => adminFetch(`/reviews/${review.id}/status`, { method: 'PUT', body: { status: to } }), { success: to === 'approved' ? 'Review published.' : 'Review rejected.' }).then((done) => done !== undefined && list.reload());
  }

  const columns: Column<AdminReview>[] = [
    {
      key: 'review', header: 'Review', priority: true,
      render: (r) => (
        <span className="block max-w-xl">
          <span className="font-medium" aria-label={`${r.rating} out of 5`}>{'★'.repeat(r.rating)}{'☆'.repeat(5 - r.rating)}</span> {r.title && <strong>{r.title}</strong>}
          <span className="mt-1 block whitespace-pre-line text-sm text-slate-700">{r.body}</span>
          {r.reply && <span className="mt-1 block text-xs text-slate-600">Your reply: {r.reply}</span>}
        </span>
      ),
    },
    { key: 'product', header: 'Product', render: (r) => r.product?.name ?? '—' },
    { key: 'author', header: 'Customer', render: (r) => <span>{r.author}{r.verified_purchase && <span className="block text-xs text-green-700">Verified purchase</span>}</span> },
    { key: 'date', header: 'Written', render: (r) => formatDate(r.created_at) },
    { key: 'status', header: 'Status', priority: true, render: (r) => <Badge tone={STATUS_TONE[r.status]}>{STATUS_LABEL[r.status]}</Badge> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (r) => (
        <span className="flex flex-wrap justify-end gap-1">
          {r.status !== 'approved' && <Button size="sm" variant="primary" busy={busy === `m${r.id}`} onClick={() => moderate(r, 'approved')}>Publish<span className="sr-only"> review {r.id}</span></Button>}
          {r.status !== 'rejected' && <Button size="sm" busy={busy === `m${r.id}`} onClick={() => moderate(r, 'rejected')}>Reject<span className="sr-only"> review {r.id}</span></Button>}
          <Button size="sm" variant="ghost" onClick={() => setReplying(r)}>Reply<span className="sr-only"> to review {r.id}</span></Button>
          <Button size="sm" variant="ghost" onClick={() => setRemoving(r)}>Delete<span className="sr-only"> review {r.id}</span></Button>
        </span>
      ),
    },
  ];

  return (
    <AdminPage title="Reviews" description="Reviews from customers who bought the product. Publish them to show them on your store; the product's rating counts published reviews only.">
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <SelectField
          label="Show"
          value={status}
          onChange={(v) => { setStatus(v); setPage(1); }}
          options={[
            { value: 'pending', label: `Waiting (${counts.pending ?? 0})` },
            { value: 'approved', label: `Published (${counts.approved ?? 0})` },
            { value: 'rejected', label: `Rejected (${counts.rejected ?? 0})` },
            { value: '', label: 'All' },
          ]}
        />
        <TextField label="Search" value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Text or product" />
      </div>
      <DataTable
        caption="Reviews"
        columns={columns}
        rows={list.data?.data ?? null}
        rowKey={(r) => r.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title={status === 'pending' ? 'No reviews waiting' : 'No reviews here'} description="Customers who bought a product can review it from its page." />}
      />
      <Pagination meta={list.data ? { page: list.data.meta.current_page, lastPage: list.data.meta.last_page, total: list.data.meta.total, perPage: list.data.meta.per_page } : null} onPage={setPage} disabled={list.loading} />

      {replying && <ReplyDialog review={replying} onClose={() => setReplying(null)} onDone={() => { setReplying(null); list.reload(); }} />}
      <ConfirmDialog
        open={removing !== null}
        title="Delete this review?"
        confirmLabel="Delete review"
        busy={busy === 'delete'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/reviews/${removing.id}`, { method: 'DELETE' }), { success: 'Review deleted.' }).then((done) => {
            if (done !== undefined) {
              setRemoving(null);
              list.reload();
            }
          })
        }
      >
        <p>The review by <strong>{removing?.author}</strong> is removed for good and the product's rating is counted again. To hide it but keep it, reject it instead.</p>
      </ConfirmDialog>
    </AdminPage>
  );
}

function ReplyDialog({ review, onClose, onDone }: { review: AdminReview; onClose: () => void; onDone: () => void }) {
  const form = useForm({ reply: review.reply ?? '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(`/reviews/${review.id}/reply`, { method: 'PUT', body: { reply: form.values.reply === '' ? null : form.values.reply } }), 'Reply saved.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={`Reply to ${review.author}`} description="Shown under the review on your store once the review is published. Leave empty to remove your reply." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextAreaField label="Your reply" rows={4} value={form.values.reply} onChange={(v) => form.set('reply', v)} error={form.errors.reply} maxLength={2000} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save reply</Button>
        </div>
      </form>
    </Dialog>
  );
}
