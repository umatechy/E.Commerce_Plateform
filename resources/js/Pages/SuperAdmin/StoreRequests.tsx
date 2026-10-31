import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Badge, { type Tone } from '@/Components/ui/Badge';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField } from '@/Components/ui/Form';
import { FilterBar } from '@/Components/ui/Filters';
import { Card, EmptyPanel, ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { formatDateTime } from '@/lib/datetime';
import { CreateStoreDialog } from '@/Pages/SuperAdmin/Stores';

/**
 * Phase S1 — Super Admin → Store requests (owner decision 2026-10-30: the
 * platform is closed to sign-up; businesses ask Umar Techy for a store).
 * The team calls them back, notes what was agreed, and creates the store
 * from the request; the request then points at its store.
 */
export type StoreRequestRow = {
  id: string; business_name: string; contact_name: string; email: string; phone: string; city: string | null; business_category: string | null;
  package_code: string | null; domain: string | null; hosting: 'umar_techy' | 'own_server' | 'not_sure'; message: string | null;
  status: 'new' | 'contacted' | 'store_created' | 'declined'; staff_note: string | null; handled_by: string | null;
  store: { id: number; name: string; slug: string } | null; created_at: string | null;
};
type Option = { value: string; label: string };

const STATUS: Record<StoreRequestRow['status'], [string, Tone]> = {
  new: ['New', 'blue'], contacted: ['Contacted', 'amber'], store_created: ['Store created', 'green'], declined: ['Declined', 'neutral'],
};
const HOSTING: Record<StoreRequestRow['hosting'], string> = { umar_techy: 'Umar Techy hosting', own_server: 'Their own server', not_sure: 'Not decided' };

function FollowUpDialog({ row, onClose, onDone }: { row: StoreRequestRow; onClose: () => void; onDone: () => void }) {
  const form = useForm<{ status: string; staff_note: string }>({ status: row.status === 'new' ? 'contacted' : row.status, staff_note: row.staff_note ?? '' });

  async function save() {
    const saved = await form.submit(() => adminFetch(`/super-admin/store-requests/${row.id}`, { method: 'PUT', body: { status: form.values.status, staff_note: form.values.staff_note || null } }), 'Saved.');
    if (saved !== undefined) onDone();
  }

  return (
    <Dialog open title={`Follow up ${row.business_name}`} onClose={onClose} busy={form.busy}>
      <div className="space-y-4">
        <FormError message={form.formError} />
        <SelectField label="Status" value={form.values.status} onChange={(v) => form.set('status', v)} options={[{ value: 'new', label: 'New' }, { value: 'contacted', label: 'Contacted' }, { value: 'declined', label: 'Declined' }]} />
        <TextAreaField label="Notes for the team" optional value={form.values.staff_note} onChange={(v) => form.set('staff_note', v)} rows={4} maxLength={2000} hint="What was agreed: package, price, domain, hosting, when to start." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button variant="primary" busy={form.busy} onClick={save}>Save</Button>
        </div>
      </div>
    </Dialog>
  );
}

function RequestCard({ row, categories, onChanged }: { row: StoreRequestRow; categories: Option[]; onChanged: () => void }) {
  const [dialog, setDialog] = useState<'follow' | 'create' | 'remove' | null>(null);
  const { busy, run } = useAction();
  const open = row.status !== 'store_created';
  const category = categories.find((c) => c.value === row.business_category)?.label;

  return (
    <Card
      title={row.business_name}
      icon="store"
      description={`${row.contact_name} · asked ${row.created_at ? formatDateTime(row.created_at) : ''}`}
      actions={<Badge tone={STATUS[row.status][1]}>{STATUS[row.status][0]}</Badge>}
    >
      <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div><dt className="text-slate-500">WhatsApp / phone</dt><dd className="font-medium"><a href={`https://wa.me/${row.phone.replace(/\D/g, '').replace(/^0/, '92')}`} target="_blank" rel="noopener noreferrer" className={`rounded text-brand-700 hover:underline ${FOCUS_RING}`}>{row.phone}</a></dd></div>
        <div><dt className="text-slate-500">Email</dt><dd className="break-all font-medium"><a href={`mailto:${row.email}`} className={`rounded text-brand-700 hover:underline ${FOCUS_RING}`}>{row.email}</a></dd></div>
        <div><dt className="text-slate-500">Hosting</dt><dd className="font-medium">{HOSTING[row.hosting]}</dd></div>
        <div><dt className="text-slate-500">Domain</dt><dd className="font-medium">{row.domain ?? 'None yet'}</dd></div>
        <div><dt className="text-slate-500">Sells</dt><dd className="font-medium">{[category, row.city].filter(Boolean).join(' · ') || '—'}</dd></div>
        <div><dt className="text-slate-500">Package</dt><dd className="font-medium capitalize">{row.package_code ?? 'Help to choose'}</dd></div>
      </dl>
      {row.message && <p className="mt-4 whitespace-pre-line rounded-xl bg-slate-50 p-3 text-sm text-slate-700">{row.message}</p>}
      {row.staff_note && <p className="mt-3 whitespace-pre-line rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"><span className="font-semibold">Team note{row.handled_by ? ` (${row.handled_by})` : ''}:</span> {row.staff_note}</p>}
      <div className="mt-5 flex flex-wrap items-center justify-end gap-2">
        {row.store ? (
          <Link href={`/super-admin/stores/${row.store.id}`} className={`rounded font-medium text-brand-700 hover:underline ${FOCUS_RING}`}>Open {row.store.name}</Link>
        ) : (
          <>
            <Button size="sm" variant="ghost" onClick={() => setDialog('remove')}>Remove</Button>
            <Button size="sm" onClick={() => setDialog('follow')}>Follow up</Button>
            {open && <Button size="sm" variant="primary" onClick={() => setDialog('create')}>Create store</Button>}
          </>
        )}
      </div>
      {dialog === 'follow' && <FollowUpDialog row={row} onClose={() => setDialog(null)} onDone={() => { setDialog(null); onChanged(); }} />}
      {dialog === 'create' && (
        <CreateStoreDialog
          categories={categories}
          onClose={() => setDialog(null)}
          storeRequestId={row.id}
          initial={{ store_name: row.business_name, business_category: row.business_category ?? '', package_code: row.package_code ?? '', owner_email: row.email, ...(row.hosting === 'own_server' ? { trial_days: '0' } : {}) }}
        />
      )}
      <ConfirmDialog
        open={dialog === 'remove'}
        title={`Remove the request from ${row.business_name}?`}
        confirmLabel="Remove"
        busy={busy === 'remove'}
        onClose={() => setDialog(null)}
        onConfirm={() => run('remove', () => adminFetch(`/super-admin/store-requests/${row.id}`, { method: 'DELETE' }), { success: 'Removed.' }).then((r) => { setDialog(null); if (r) onChanged(); })}
      >
        Their name, phone and email are deleted. Use this when they ask, or for spam. You will be asked for your password.
      </ConfirmDialog>
    </Card>
  );
}

export default function StoreRequests() {
  const [status, setStatus] = useState('');
  const state = useApi<{ data: StoreRequestRow[]; meta: { new: number } }>('/super-admin/store-requests', { status: status || undefined });
  const categories = useApi<{ data: Option[] }>('/super-admin/business-categories');
  const rows = state.data?.data;

  return (
    <AdminPage title="Store requests" description="Businesses who asked Umar Techy for a store. Call them back, note what was agreed, and create their store from here.">
      <FilterBar>
        <div className="w-56">
          <SelectField label="Show" value={status} onChange={setStatus} options={[{ value: '', label: 'All requests' }, { value: 'new', label: 'New' }, { value: 'contacted', label: 'Contacted' }, { value: 'store_created', label: 'Store created' }, { value: 'declined', label: 'Declined' }]} />
        </div>
        {state.data && state.data.meta.new > 0 && <p className="text-sm font-medium text-blue-800">{state.data.meta.new} new {state.data.meta.new === 1 ? 'request' : 'requests'} waiting</p>}
      </FilterBar>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : rows === undefined ? (
        <Skeleton lines={6} />
      ) : rows.length === 0 ? (
        <EmptyPanel icon="store" title="No requests" description="Requests from the home page's “Ask for your store” form appear here, and the team gets an email for each." />
      ) : (
        <div className="grid gap-6 xl:grid-cols-2">
          {rows.map((row) => <RequestCard key={`${row.id}-${row.status}-${row.staff_note}`} row={row} categories={categories.data?.data ?? []} onChanged={state.reload} />)}
        </div>
      )}
    </AdminPage>
  );
}
