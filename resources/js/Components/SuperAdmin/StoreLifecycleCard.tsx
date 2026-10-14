import { FormEvent, useState } from 'react';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import Dialog from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { Card, QueryState, Skeleton } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { STAGE_LABEL, STAGE_TONE } from '@/lib/stores';
import { ACTION_LABEL, ACTOR_LABEL, CLOSURE_REASONS, DELETION_STATUS, SUSPENSION_REASONS, daysUntil, type ClosureInfo, type HistoryEntry } from '@/lib/storeLifecycle';

/**
 * Phase B51 — Module 30 §10 "Store Lifecycle Control", §140 "Tenant
 * Deletion Control"; Module 03 §33–38. The store's life on the Super Admin
 * store page: where it stands and until when, the moves allowed from here
 * (the server lists them), legal hold, and permanent deletion — planned
 * (rows per table, what is kept), requested with a reason and the store's
 * address typed in full, and followed until its final check.
 */
type Deletion = {
  id: string; status: string; reason: string; not_before: string; planned_rows: number; removed_rows: number; removed_files: number; remaining_rows: number;
  backup: { id: string; status: string } | null; failure_message: string | null; started_at: string | null; completed_at: string | null; cancelled_at: string | null;
};
export type Lifecycle = ClosureInfo & {
  store: { id: string; name: string; slug: string };
  suspension_note: string | null;
  archived_at: string | null;
  legal_hold: boolean;
  legal_hold_reason: string | null;
  allowed: string[];
  suspension_reasons: string[];
  deletion_blockers: string[];
  deletion: Deletion | null;
  history: HistoryEntry[];
};
type Plan = { purge: Record<string, number>; retain: Record<string, number>; purge_total: number; retained_tables: string[]; blockers: string[] };

const ACTIONS: Record<string, { label: string; title: string; text: string; variant: 'primary' | 'danger' | 'secondary' }> = {
  suspend: { label: 'Suspend store', title: 'Suspend this store?', text: 'Its shop closes at once and its admin turns read-only. The owner is told by email. Data is kept; after 90 days you may archive it.', variant: 'danger' },
  reactivate: { label: 'Lift suspension', title: 'Lift the suspension?', text: 'The store returns to where it was before. Its subscription must allow access — or be reactivated with it.', variant: 'primary' },
  close: { label: 'Close for the owner', title: 'Close this store for its owner?', text: 'Use this when the owner asked you to. Billing stops, the shop closes, the data is kept for the retention period and the owner can still reopen.', variant: 'danger' },
  reopen: { label: 'Reopen', title: 'Reopen this store?', text: 'The store returns to where it was. Billing restarts from today.', variant: 'primary' },
  archive: { label: 'Archive', title: 'Archive this store?', text: 'Its retention period is over. The owner can no longer reopen it or download its data; you can still restore it.', variant: 'secondary' },
  restore: { label: 'Restore from archive', title: 'Restore this store from the archive?', text: 'It becomes closed again with a new retention period, so its owner can download its data or reopen it.', variant: 'primary' },
};

export default function StoreLifecycleCard({ storeId, onChanged }: { storeId: string; onChanged?: () => void }) {
  const base = `/super-admin/stores/${storeId}/lifecycle`;
  const state = useApi<{ data: Lifecycle }>(base);
  const [action, setAction] = useState<string | null>(null);
  const [hold, setHold] = useState(false);
  const [planning, setPlanning] = useState(false);
  const { busy, run } = useAction();
  const done = () => {
    setAction(null);
    setHold(false);
    setPlanning(false);
    state.reload();
    onChanged?.();
  };

  return (
    <QueryState state={state} lines={4}>
      {({ data }) => {
        const left = daysUntil(data.retention_ends_at);
        const stage = data.status === 'pending_setup' ? 'onboarding' : data.status;

        return (
          <Card
            title="Store life"
            description="Suspend, close, archive and, at the very end, delete. Every step is recorded; the owner is told by email."
            actions={
              <span className="flex flex-wrap items-center gap-2">
                {data.legal_hold && <Badge tone="red">Legal hold</Badge>}
                <Badge tone={STAGE_TONE[stage] ?? 'neutral'}>{STAGE_LABEL[stage] ?? data.status}</Badge>
              </span>
            }
          >
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
              {data.closed_at && <Row label="Closed">{formatDate(data.closed_at)}</Row>}
              {data.suspended_at && <Row label="Suspended">{formatDate(data.suspended_at)} — {SUSPENSION_REASONS[data.suspension_reason ?? ''] ?? data.suspension_reason}{data.suspension_note ? `: ${data.suspension_note}` : ''}</Row>}
              {data.retention_ends_at && <Row label="Data kept until">{formatDate(data.retention_ends_at)} {left > 0 ? <span className="text-slate-500">({left} days)</span> : <Badge tone="amber">Period over</Badge>}</Row>}
              {data.archived_at && <Row label="Archived">{formatDate(data.archived_at)}</Row>}
              {data.legal_hold && <Row label="Legal hold">{data.legal_hold_reason}</Row>}
            </dl>

            <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
              {data.allowed.filter((a) => ACTIONS[a]).map((a) => (
                <Button key={a} size="sm" variant={ACTIONS[a].variant} onClick={() => setAction(a)}>{ACTIONS[a].label}</Button>
              ))}
              {!['deleted', 'deleting'].includes(data.status) && (
                <Button size="sm" variant="ghost" onClick={() => setHold(true)}>{data.legal_hold ? 'Lift legal hold' : 'Set legal hold'}</Button>
              )}
            </div>

            {(data.status === 'archived' || data.deletion) && (
              <DeletionPanel data={data} busy={busy} onPlan={() => setPlanning(true)}
                onCancel={() => setAction('cancel_deletion')}
                onRetry={() => run('retry', () => adminFetch(`${base}/deletion/retry`, { method: 'POST' }), { success: 'The deletion will be tried again on the next run.' }).then((r) => r !== undefined && done())}
              />
            )}

            {data.history.length > 0 && (
              <details className="mt-4 rounded-md border border-slate-200 p-3 text-sm">
                <summary className="cursor-pointer font-medium text-slate-800">History ({data.history.length})</summary>
                <ol className="mt-2 space-y-2">
                  {data.history.map((e, i) => (
                    <li key={`${e.at}-${i}`} className="border-b border-slate-100 pb-2 last:border-0">
                      <span className="font-medium">{ACTION_LABEL[e.action] ?? e.action}</span>{' '}
                      <span className="text-slate-500">by {e.actor ?? ACTOR_LABEL[e.by] ?? e.by} · {formatDateTime(e.at)}</span>
                      {(e.reason || e.note) && <p className="text-slate-600">{[e.reason ? (SUSPENSION_REASONS[e.reason] ?? CLOSURE_REASONS[e.reason] ?? e.reason) : null, e.note].filter(Boolean).join(' — ')}</p>}
                    </li>
                  ))}
                </ol>
              </details>
            )}

            {action && action !== 'cancel_deletion' && <ActionDialog base={base} action={action} data={data} onClose={() => setAction(null)} onDone={done} />}
            {action === 'cancel_deletion' && <NoteDialog title="Cancel the deletion?" text="Nothing more is removed. The store stays archived." label="Keep the store" endpoint={`${base}/deletion/cancel`} onClose={() => setAction(null)} onDone={done} />}
            {hold && <HoldDialog base={base} on={!data.legal_hold} onClose={() => setHold(false)} onDone={done} />}
            {planning && <DeletionDialog base={base} data={data} onClose={() => setPlanning(false)} onDone={done} />}
          </Card>
        );
      }}
    </QueryState>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="text-slate-800">{children}</dd>
    </div>
  );
}

function DeletionPanel({ data, busy, onPlan, onCancel, onRetry }: { data: Lifecycle; busy: string | null; onPlan: () => void; onCancel: () => void; onRetry: () => void }) {
  const d = data.deletion;
  const open = d !== null && ['scheduled', 'backing_up', 'purging', 'failed'].includes(d.status);

  return (
    <section aria-labelledby="deletion-heading" className="mt-4 rounded-md border border-red-200 bg-red-50/40 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 id="deletion-heading" className="text-sm font-semibold text-red-900">Permanent deletion</h3>
        {d && <Badge tone={DELETION_STATUS[d.status]?.tone ?? 'neutral'}>{DELETION_STATUS[d.status]?.label ?? d.status}</Badge>}
      </div>
      {d && (
        <div className="mt-2 space-y-1 text-sm text-slate-700">
          {d.status === 'scheduled' && <p>Runs on or after <strong>{formatDateTime(d.not_before)}</strong> — after a backup is taken and checked. {d.planned_rows.toLocaleString()} rows planned.</p>}
          {d.status === 'backing_up' && <p>Taking and checking a backup first ({d.backup?.status ?? 'queued'}). Nothing has been removed.</p>}
          {d.status === 'purging' && <p>Removing data…</p>}
          {d.status === 'completed' && <p>Deleted on {d.completed_at ? formatDateTime(d.completed_at) : '—'}: {d.removed_rows.toLocaleString()} rows and {d.removed_files} files. Final check: {d.remaining_rows === 0 ? 'nothing left' : `${d.remaining_rows} rows left`}. Backup {d.backup?.id ?? '—'}.</p>}
          {d.status === 'cancelled' && <p>Cancelled on {d.cancelled_at ? formatDateTime(d.cancelled_at) : '—'}.</p>}
          {d.status === 'failed' && <p className="text-red-800">{d.failure_message}</p>}
          <p className="text-slate-500">Reason: {d.reason}</p>
        </div>
      )}
      {!open && data.status === 'archived' && (
        data.deletion_blockers.length > 0 ? (
          <ul className="mt-2 list-disc space-y-0.5 pl-5 text-sm text-slate-700">{data.deletion_blockers.map((b) => <li key={b}>{b}</li>)}</ul>
        ) : (
          <p className="mt-2 text-sm text-slate-700">The store can be deleted. Billing records, the audit trail and this history are kept.</p>
        )
      )}
      <div className="mt-3 flex flex-wrap gap-2">
        {!open && data.status === 'archived' && <Button size="sm" variant="danger" onClick={onPlan} disabled={data.deletion_blockers.length > 0}>Plan deletion…</Button>}
        {d && (d.status === 'scheduled' || d.status === 'failed') && <Button size="sm" onClick={onCancel}>Cancel deletion</Button>}
        {d?.status === 'failed' && <Button size="sm" variant="primary" busy={busy === 'retry'} busyLabel="Saving…" onClick={onRetry}>Try again</Button>}
      </div>
    </section>
  );
}

function ActionDialog({ base, action, data, onClose, onDone }: { base: string; action: string; data: Lifecycle; onClose: () => void; onDone: () => void }) {
  const meta = ACTIONS[action];
  const form = useForm({ reason: '', note: '', also: false });

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = { note: form.values.note.trim(), ...(action === 'suspend' ? { reason: form.values.reason } : {}), ...(action === 'reactivate' ? { also_subscription: form.values.also } : {}) };
    if ((await form.submit(() => adminFetch(`${base}/${action}`, { method: 'POST', body }), `${meta.label}: done.`)) !== undefined) onDone();
  }

  return (
    <Dialog open title={meta.title} description={`${data.store.name} (${data.store.slug})`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <p className="text-sm text-slate-700">{meta.text}</p>
        {action === 'suspend' && (
          <SelectField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} placeholder="Choose a reason" required error={form.errors.reason}
            options={data.suspension_reasons.map((r) => ({ value: r, label: SUSPENSION_REASONS[r] ?? r }))} />
        )}
        <TextAreaField label={action === 'suspend' ? 'What happened (for the record)' : 'Note (for the record)'} rows={2} maxLength={500} required value={form.values.note} onChange={(v) => form.set('note', v)} error={form.errors.note} hint="At least 5 characters. Kept in the audit trail and the store’s history." data-autofocus />
        {action === 'reactivate' && <CheckboxField label="Also reactivate its subscription" hint="Only if the subscription itself was suspended or ended. Billing restarts from today." checked={form.values.also} onChange={(v) => form.set('also', v)} />}
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant={meta.variant === 'secondary' ? 'primary' : meta.variant} busy={form.busy} busyLabel="Saving…">{meta.label}</Button>
        </div>
      </form>
    </Dialog>
  );
}

function NoteDialog({ title, text, label, endpoint, onClose, onDone }: { title: string; text: string; label: string; endpoint: string; onClose: () => void; onDone: () => void }) {
  const form = useForm({ note: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(endpoint, { method: 'POST', body: { note: form.values.note.trim() } }), 'Done.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={title} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <p className="text-sm text-slate-700">{text}</p>
        <TextAreaField label="Note (for the record)" rows={2} maxLength={500} required value={form.values.note} onChange={(v) => form.set('note', v)} error={form.errors.note} data-autofocus />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Back</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">{label}</Button>
        </div>
      </form>
    </Dialog>
  );
}

function HoldDialog({ base, on, onClose, onDone }: { base: string; on: boolean; onClose: () => void; onDone: () => void }) {
  const form = useForm({ reason: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(`${base}/legal-hold`, { method: 'PUT', body: { hold: on, reason: form.values.reason.trim() } }), on ? 'Legal hold set.' : 'Legal hold lifted.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={on ? 'Set a legal hold?' : 'Lift the legal hold?'} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <p className="text-sm text-slate-700">{on ? 'While on hold, nothing of this store can be deleted — for a tax inquiry, a dispute or a legal request.' : 'The store can be deleted again once its other checks pass.'}</p>
        <TextField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={300} required data-autofocus />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">{on ? 'Set legal hold' : 'Lift legal hold'}</Button>
        </div>
      </form>
    </Dialog>
  );
}

function DeletionDialog({ base, data, onClose, onDone }: { base: string; data: Lifecycle; onClose: () => void; onDone: () => void }) {
  const plan = useApi<{ data: Plan }>(`${base}/deletion-plan`);
  const form = useForm({ reason: '', slug: '' });
  const matches = form.values.slug.trim() === data.store.slug;

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(`${base}/deletion`, { method: 'POST', body: { reason: form.values.reason.trim(), confirm_slug: form.values.slug.trim() } }), 'Deletion scheduled.')) !== undefined) onDone();
  }

  const top = Object.entries(plan.data?.data.purge ?? {}).sort((a, b) => b[1] - a[1]);

  return (
    <Dialog open wide title={`Delete ${data.store.name} permanently?`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-700">
          <li>It waits a cooling period first; until it starts you can cancel it.</li>
          <li>A platform backup is taken and checked. Nothing is removed if that fails.</li>
          <li>The store’s rows and files are removed, then every table is counted again.</li>
        </ol>
        {plan.loading && <Skeleton lines={3} />}
        {plan.data && (
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <h3 className="text-sm font-semibold text-slate-900">Removed: {plan.data.data.purge_total.toLocaleString()} rows</h3>
              <ul className="mt-1 max-h-48 overflow-auto rounded border border-slate-200 text-xs">
                {top.map(([table, n]) => <li key={table} className="flex justify-between border-b border-slate-100 px-2 py-1 last:border-0"><span className="font-mono">{table}</span><span>{n.toLocaleString()}</span></li>)}
              </ul>
            </div>
            <div>
              <h3 className="text-sm font-semibold text-slate-900">Kept (law and records)</h3>
              <ul className="mt-1 flex flex-wrap gap-1 text-xs">
                {plan.data.data.retained_tables.map((t) => <li key={t} className="rounded bg-slate-100 px-1.5 py-0.5 font-mono">{t}{plan.data?.data.retain[t] ? ` (${plan.data.data.retain[t]})` : ''}</li>)}
              </ul>
            </div>
          </div>
        )}
        <TextAreaField label="Reason" rows={2} maxLength={500} required value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} hint="For example: the owner asked by email on …. At least 10 characters." />
        <TextField label={`Type the store address: ${data.store.slug}`} value={form.values.slug} onChange={(v) => form.set('slug', v)} error={form.errors.confirm_slug} autoComplete="off" required />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="danger" busy={form.busy} busyLabel="Scheduling…" disabled={!matches || form.values.reason.trim().length < 10}>Schedule deletion</Button>
        </div>
      </form>
    </Dialog>
  );
}
