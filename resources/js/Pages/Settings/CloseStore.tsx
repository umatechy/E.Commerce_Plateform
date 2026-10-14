import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { Card, QueryState } from '@/Components/ui/Page';
import { toast } from '@/Components/ui/toast';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { downloadFile } from '@/lib/download';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { ACTION_LABEL, ACTOR_LABEL, CLOSURE_REASONS, DATASETS, daysUntil, type ClosureInfo } from '@/lib/storeLifecycle';

/**
 * Phase B51 (gap G14; Module 03 §35, §40; SRS STORE-009; owner decision 5)
 * — Store → Close store, for the store's Owner.
 *
 * Before closing, the page says plainly what closing does and until when it
 * can be undone, lists what is still open (orders, returns, store credit,
 * unpaid invoices, own domains), offers the store's data to download, and
 * only then the closing itself: the store's name typed in full, then the
 * password and two-step code (step-up). A closed store shows how many days
 * are left, the downloads and Reopen.
 */
export default function CloseStore() {
  const state = useApi<{ data: ClosureInfo }>('/store-lifecycle');

  return (
    <AdminPage title="Close store" description="Take your data with you, close the store, or reopen it.">
      <QueryState state={state} lines={6}>
        {({ data }) => <Body info={data} onChanged={state.reload} />}
      </QueryState>
    </AdminPage>
  );
}

function Body({ info, onChanged }: { info: ClosureInfo; onChanged: () => void }) {
  const until = info.retention_ends_at ? formatDate(info.retention_ends_at) : null;

  if (!info.is_owner) {
    return (
      <Card title="Only the owner can close this store">
        <p className="text-sm text-slate-700">
          {info.status === 'cancelled' ? `The store is closed. Its data is kept until ${until}.` : info.status === 'suspended' ? 'The store is suspended by Umar Techy.' : 'Closing or reopening the store is the store owner’s decision.'}
        </p>
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      {info.status === 'cancelled' && <ClosedHero info={info} onChanged={onChanged} />}
      {info.status === 'suspended' && (
        <Card title="This store is suspended" actions={<Badge tone="red">Suspended</Badge>}>
          <p className="text-sm text-slate-700">
            Umar Techy has suspended the store{info.suspended_at ? ` on ${formatDate(info.suspended_at)}` : ''}. Its shop is closed and nothing can be changed. You can still see and download your data.
          </p>
          <p className="mt-2 text-sm">
            <Link href="/support" className="font-medium text-indigo-700 underline">Contact Umar Techy support</Link> to talk about it.
          </p>
        </Card>
      )}
      {info.status === 'archived' && (
        <Card title="This store is archived" actions={<Badge>Archived</Badge>}>
          <p className="text-sm text-slate-700">The time to reopen it has passed. <Link href="/support" className="font-medium text-indigo-700 underline">Contact Umar Techy support</Link> if you need anything from it.</p>
        </Card>
      )}

      {info.can_close && <WhatHappens info={info} />}
      {info.can_close && <OpenItems info={info} />}
      {info.datasets.length > 0 && <Downloads info={info} />}
      {info.can_close && <CloseForm info={info} onChanged={onChanged} />}
      {info.history && info.history.length > 0 && <History info={info} />}
    </div>
  );
}

function WhatHappens({ info }: { info: ClosureInfo }) {
  const until = new Date(Date.now() + info.retention_days * 86_400_000).toISOString();
  const points: [string, string][] = [
    ['Your shop closes at once.', 'Customers see that the shop has closed and can no longer order.'],
    ['Billing stops.', 'No new invoices are issued. Invoices already issued stay due.'],
    ['Your admin becomes read-only.', 'You can still see everything and download it, but not change it. Messages to customers and marketing stop.'],
    [`You can change your mind for ${info.retention_days} days.`, `Until about ${formatDate(until)} your data is kept and you can reopen the store exactly as it was.`],
    ['After that the store is archived.', 'You can no longer reopen it. Umar Techy may then delete its data for good; billing records are kept as the law requires.'],
  ];

  return (
    <Card title="What closing means" description="Read this first: some of it cannot be undone after the time to reopen has passed.">
      <ol className="space-y-3">
        {points.map(([title, text], i) => (
          <li key={title} className="flex gap-3">
            <span aria-hidden="true" className="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700">{i + 1}</span>
            <span className="text-sm">
              <span className="font-medium text-slate-900">{title}</span> <span className="text-slate-600">{text}</span>
            </span>
          </li>
        ))}
      </ol>
    </Card>
  );
}

function OpenItems({ info }: { info: ClosureInfo }) {
  const items = info.open_items ?? [];

  return (
    <Card title="Still open" description="Closing does not stop you, but your customers may be waiting on these.">
      {items.length === 0 ? (
        <p className="text-sm text-emerald-800">Nothing is left open: no unfinished orders, returns, store credit or unpaid invoices.</p>
      ) : (
        <ul className="space-y-2">
          {items.map((item) => (
            <li key={item.key} className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm">
              <p className="font-medium text-amber-900">{item.label}</p>
              <p className="text-amber-800">{item.hint}</p>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

function Downloads({ info }: { info: ClosureInfo }) {
  const [busy, setBusy] = useState<string | null>(null);

  function download(dataset: string) {
    setBusy(dataset);
    downloadFile(`/store-lifecycle/export/${dataset}`, {}, `${dataset}.csv`)
      .catch((e) => toast.error(adminErrorMessage(e)))
      .finally(() => setBusy(null));
  }

  return (
    <Card title="Take your data with you" description={`CSV files that open in Excel or Google Sheets. Keep them for your records and taxes${info.status === 'cancelled' ? ' — download them before the store is archived' : ''}.`}>
      <ul className="grid gap-3 sm:grid-cols-2">
        {info.datasets.map((dataset) => (
          <li key={dataset} className="flex items-start justify-between gap-3 rounded-md border border-slate-200 p-3">
            <span className="text-sm">
              <span className="block font-medium text-slate-900">{DATASETS[dataset]?.label ?? dataset}</span>
              <span className="text-slate-600">{DATASETS[dataset]?.hint}</span>
            </span>
            <Button size="sm" busy={busy === dataset} busyLabel="Preparing…" disabled={busy !== null && busy !== dataset} onClick={() => download(dataset)}>
              Download<span className="sr-only"> {DATASETS[dataset]?.label}</span>
            </Button>
          </li>
        ))}
      </ul>
      <p className="mt-3 text-xs text-slate-500">Your invoices from Umar Techy stay under <Link href="/billing" className="underline">Billing</Link>.</p>
    </Card>
  );
}

function CloseForm({ info, onChanged }: { info: ClosureInfo; onChanged: () => void }) {
  const form = useForm({ reason: '', note: '', name: '' });
  const matches = form.values.name.trim().toLowerCase() === info.store_name.trim().toLowerCase();

  async function close(event: FormEvent) {
    event.preventDefault();
    const body = { store_name: form.values.name, reason: form.values.reason || null, note: form.values.note.trim() || null };
    if ((await form.submit(() => adminFetch('/store-lifecycle/close', { method: 'POST', body }), `${info.store_name} is closed.`)) !== undefined) {
      onChanged();
      document.getElementById('main')?.scrollIntoView?.({ block: 'start' }); // to the "closed" summary at the top
    }
  }

  return (
    <section aria-labelledby="close-heading" className="rounded-lg border-2 border-red-200 bg-white p-5">
      <h2 id="close-heading" className="text-base font-semibold text-red-800">Close {info.store_name}</h2>
      <p className="mt-1 text-sm text-slate-600">You will be asked for your password and two-step code.</p>
      <form onSubmit={close} className="mt-4 space-y-4" noValidate>
        <FormError message={form.formError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField
            label="Why are you closing?"
            optional
            value={form.values.reason}
            onChange={(v) => form.set('reason', v)}
            placeholder="Choose a reason"
            options={(info.reasons ?? []).filter((r) => r !== 'platform_request').map((r) => ({ value: r, label: CLOSURE_REASONS[r] ?? r }))}
            error={form.errors.reason}
          />
          <TextField label={`Type the store name: ${info.store_name}`} value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.store_name} autoComplete="off" required />
        </div>
        <TextAreaField label="Anything we could do better?" optional rows={2} maxLength={500} value={form.values.note} onChange={(v) => form.set('note', v)} error={form.errors.note} />
        <div className="flex justify-end">
          <Button type="submit" variant="danger" busy={form.busy} busyLabel="Closing…" disabled={!matches}>Close store</Button>
        </div>
      </form>
    </section>
  );
}

function ClosedHero({ info, onChanged }: { info: ClosureInfo; onChanged: () => void }) {
  const [reopening, setReopening] = useState(false);
  const left = daysUntil(info.retention_ends_at);

  return (
    <section aria-labelledby="closed-heading" className="rounded-lg border border-amber-300 bg-amber-50 p-5">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 id="closed-heading" className="text-lg font-semibold text-amber-950">{info.store_name} is closed</h2>
          <p className="mt-1 text-sm text-amber-900">
            {info.closed_at && <>Closed on {formatDate(info.closed_at)}. </>}
            {info.retention_ends_at && <>Your data is kept until <strong>{formatDate(info.retention_ends_at)}</strong> — {left} day{left === 1 ? '' : 's'} left to download it or reopen.</>}
          </p>
          {!info.can_reopen && info.suspended_at && <p className="mt-2 text-sm text-amber-900">The store is also suspended by Umar Techy: <Link href="/support" className="font-medium underline">contact support</Link> to reopen it.</p>}
        </div>
        {info.can_reopen && <Button variant="primary" onClick={() => setReopening(true)}>Reopen store</Button>}
      </div>
      {info.retention_ends_at && info.retention_days > 0 && (
        <div className="mt-4" aria-hidden="true">
          <div className="h-2 overflow-hidden rounded-full bg-amber-200">
            <div className="h-full rounded-full bg-amber-600" style={{ width: `${Math.min(100, Math.round((left / info.retention_days) * 100))}%` }} />
          </div>
        </div>
      )}
      {reopening && <ReopenDialog info={info} onClose={() => setReopening(false)} onDone={() => { setReopening(false); onChanged(); }} />}
    </section>
  );
}

function ReopenDialog({ info, onClose, onDone }: { info: ClosureInfo; onClose: () => void; onDone: () => void }) {
  const form = useForm({ name: '' });
  const matches = form.values.name.trim().toLowerCase() === info.store_name.trim().toLowerCase();

  async function reopen(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch('/store-lifecycle/reopen', { method: 'POST', body: { store_name: form.values.name } }), `${info.store_name} is open again.`)) !== undefined) onDone();
  }

  return (
    <Dialog open title={`Reopen ${info.store_name}?`} onClose={onClose} busy={form.busy}>
      <form onSubmit={reopen} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <ul className="list-disc space-y-1 pl-5 text-sm text-slate-700">
          <li>Your shop opens to customers again, exactly as it was.</li>
          <li>Billing restarts from today: your next invoice follows under Billing.</li>
        </ul>
        <TextField label={`Type the store name: ${info.store_name}`} value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.store_name} autoComplete="off" required data-autofocus />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Reopening…" disabled={!matches}>Reopen store</Button>
        </div>
      </form>
    </Dialog>
  );
}

function History({ info }: { info: ClosureInfo }) {
  return (
    <Card title="History">
      <ol className="space-y-2 text-sm">
        {(info.history ?? []).map((entry, i) => (
          <li key={`${entry.at}-${i}`} className="flex flex-wrap justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
            <span className="font-medium text-slate-800">{ACTION_LABEL[entry.action] ?? entry.action} <span className="font-normal text-slate-500">by {ACTOR_LABEL[entry.by] ?? entry.by}</span></span>
            <span className="text-slate-500">{formatDateTime(entry.at)}</span>
          </li>
        ))}
      </ol>
    </Card>
  );
}
