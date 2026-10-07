import { FormEvent, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import StoreCreditCard from '@/Components/Customers/StoreCreditCard';
import Button, { ButtonLink, FOCUS_RING } from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import Badge, { StatusBadge, humanize } from '@/Components/ui/Badge';
import { AccessNotice, Card, Details, EmptyPanel, ErrorPanel, Skeleton, StatCard } from '@/Components/ui/Page';
import { toast } from '@/Components/ui/toast';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { dateTimeOrDash, formatDate, formatDateTime } from '@/lib/datetime';
import { ACTIVITY_LABELS, ADVANCED_FEATURE, SOURCE_LABELS, type ActivityEvent, type CustomerDetail, type CustomerGroup, type CustomerNote, type CustomerRow } from '@/lib/customers';
import { orderCustomer, type Order } from '@/lib/orders';

/**
 * Module 10 §55 "Customer detail view" (Phase B32, gap G7): one
 * customer — profile, figures, addresses, orders, group, tags, notes,
 * activity, and what staff may do about them. Everything comes from
 * /api/v1/customers/{id} and its sub-resources; every change goes back
 * through the API, which checks the permission and audits it.
 *
 * A registered customer's email and phone are theirs to change (Module
 * 10 §67); staff can only correct them for a customer without an
 * account. Erasing personal data is irreversible and asks for the
 * password again (step-up).
 */
function EditDialog({ customer, onClose, onDone }: { customer: CustomerDetail; onClose: () => void; onDone: (c: CustomerRow) => void }) {
  const form = useForm({ name: customer.name, email: customer.email, phone: customer.phone ?? '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const body: Record<string, unknown> = { name: form.values.name };
    if (!customer.registered) {
      body.email = form.values.email;
      body.phone = form.values.phone === '' ? null : form.values.phone;
    }
    const saved = await form.submit(() => adminFetch<{ data: CustomerRow }>(`/customers/${customer.id}`, { method: 'PATCH', body }), 'Customer saved.');
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open title="Edit customer" onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} data-autofocus />
        <TextField label="Email" type="email" value={form.values.email} onChange={(v) => form.set('email', v)} error={form.errors.email} disabled={customer.registered} hint={customer.registered ? 'This customer has an account: only they can change their email, and they confirm it.' : undefined} />
        <TextField label="Phone" optional value={form.values.phone} onChange={(v) => form.set('phone', v)} error={form.errors.phone} disabled={customer.registered} maxLength={32} hint={customer.registered ? 'Changed by the customer in their account.' : undefined} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save</Button>
        </div>
      </form>
    </Dialog>
  );
}

function StatusDialog({ customer, action, onClose, onDone }: { customer: CustomerDetail; action: 'block' | 'archive' | 'reactivate'; onClose: () => void; onDone: (c: CustomerRow) => void }) {
  const form = useForm({ reason: '' });
  const text = {
    block: { title: `Block ${customer.name}?`, button: 'Block customer', say: 'They cannot sign in or place new orders, also not as a guest with this email. Their open sessions end now. Their orders and history stay. You can unblock them later.' },
    archive: { title: `Archive ${customer.name}?`, button: 'Archive customer', say: 'They cannot sign in and get no marketing email. Their orders and history stay. You can restore them later.' },
    reactivate: { title: customer.status === 'blocked' ? `Unblock ${customer.name}?` : `Restore ${customer.name}?`, button: customer.status === 'blocked' ? 'Unblock' : 'Restore', say: 'They can sign in and order again.' },
  }[action];

  async function save(event: FormEvent) {
    event.preventDefault();
    if (action === 'block' && form.values.reason.trim() === '') {
      form.setFormError('Give the reason. It is kept with the change.');

      return;
    }
    const saved = await form.submit(
      () => adminFetch<{ data: CustomerRow }>(`/customers/${customer.id}/${action}`, { method: 'POST', body: action === 'reactivate' ? {} : { reason: form.values.reason === '' ? null : form.values.reason } }),
      action === 'block' ? 'Customer blocked.' : action === 'archive' ? 'Customer archived.' : 'Customer active again.',
    );
    if (saved) onDone(saved.data);
  }

  return (
    <Dialog open title={text.title} description={text.say} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        {action !== 'reactivate' && (
          <TextField label="Reason" optional={action === 'archive'} value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={500} hint="Seen by your team only." data-autofocus />
        )}
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant={action === 'block' ? 'danger' : 'primary'} busy={form.busy} busyLabel="Saving…">{text.button}</Button>
        </div>
      </form>
    </Dialog>
  );
}

function GroupAndTags({ customer, canManage, packageLocked, onChanged }: { customer: CustomerDetail; canManage: boolean; packageLocked: boolean; onChanged: (c: CustomerRow) => void }) {
  const groups = useApi<{ data: CustomerGroup[] }>(canManage ? '/customer-groups' : null);
  const [tagText, setTagText] = useState('');
  const { busy, run } = useAction();
  const tags = (customer.tags ?? []).map((tag) => tag.name);

  function saveTags(next: string[]) {
    return run('tags', () => adminFetch<{ data: CustomerRow }>(`/customers/${customer.id}/tags`, { method: 'PUT', body: { tags: next } }), { success: 'Tags saved.' }).then((result) => {
      if (result) {
        onChanged(result.data);
        setTagText('');
      }
    });
  }

  return (
    <Card title="Group and tags">
      <div className="space-y-4">
        {canManage && !customer.erased ? (
          <SelectField
            label="Group"
            value={customer.group?.id ?? ''}
            placeholder="No group"
            disabled={busy !== null}
            options={(groups.data?.data ?? []).map((g) => ({ value: g.id, label: g.name }))}
            hint={(groups.data?.data ?? []).length === 0 ? 'Create groups under Customers → Groups and tags.' : undefined}
            onChange={(group) =>
              void run('group', () => adminFetch<{ data: CustomerRow }>(`/customers/${customer.id}`, { method: 'PATCH', body: { group: group === '' ? null : group } }), { success: 'Group saved.' }).then((result) => result && onChanged(result.data))
            }
          />
        ) : (
          <Details items={[{ label: 'Group', value: customer.group?.name ?? 'No group' }]} />
        )}
        <div>
          <p className="text-sm font-medium text-slate-700">Tags</p>
          {tags.length === 0 ? (
            <p className="mt-1 text-sm text-slate-600">No tags.</p>
          ) : (
            <ul className="mt-1 flex flex-wrap gap-2">
              {tags.map((tag) => (
                <li key={tag} className="flex items-center gap-1 rounded-full bg-blue-50 py-0.5 pl-3 pr-1 text-sm text-blue-900 ring-1 ring-blue-200">
                  {tag}
                  {canManage && !customer.erased && (
                    <Button size="sm" variant="ghost" disabled={busy !== null} onClick={() => saveTags(tags.filter((t) => t !== tag))} aria-label={`Remove tag ${tag}`}>✕</Button>
                  )}
                </li>
              ))}
            </ul>
          )}
          {canManage && !customer.erased && (
            <form
              className="mt-2 flex flex-wrap items-end gap-2"
              onSubmit={(event) => {
                event.preventDefault();
                const added = tagText.split(',').map((t) => t.trim()).filter((t) => t !== '');
                if (added.length > 0) void saveTags([...tags, ...added]);
              }}
            >
              <div className="min-w-[12rem] flex-1">
                <TextField label="Add tags" value={tagText} onChange={setTagText} hint="Separate with commas. A tag your store does not have yet is created." maxLength={200} />
              </div>
              <Button type="submit" busy={busy === 'tags'} busyLabel="Saving…" disabled={tagText.trim() === ''}>Add</Button>
            </form>
          )}
        </div>
        {packageLocked && <p className="text-xs text-slate-600">Changing groups and tags comes with the Business and Premium packages.</p>}
      </div>
    </Card>
  );
}

function Orders({ customer }: { customer: CustomerDetail }) {
  const [page, setPage] = useState(1);
  const list = usePagedApi<Order>('/orders', { customer: customer.id, page });
  const columns: Column<Order>[] = [
    {
      key: 'number',
      header: 'Order',
      render: (order) => (
        <Link href={`/orders/${order.id}`} className={`rounded font-mono text-indigo-700 hover:underline ${FOCUS_RING}`}>{order.order_number}</Link>
      ),
    },
    { key: 'date', header: 'Placed', render: (order) => formatDateTime(order.created_at) },
    { key: 'status', header: 'Status', priority: true, render: (order) => <StatusBadge status={order.status} /> },
    { key: 'payment', header: 'Payment', render: (order) => <StatusBadge status={order.payment_status} /> },
    { key: 'total', header: 'Total', align: 'right', priority: true, render: (order) => money(order.grand_total_minor, order.currency) },
  ];

  return (
    <Card title="Orders">
      <DataTable caption={`Orders of ${orderCustomer({ is_guest_order: false, customer })}`} columns={columns} rows={list.rows} rowKey={(order) => order.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<p className="text-sm text-slate-600">No orders on this account.</p>} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />
    </Card>
  );
}

function Notes({ customer, canManage }: { customer: CustomerDetail; canManage: boolean }) {
  const list = useApi<{ data: CustomerNote[] }>(`/customers/${customer.id}/notes`);
  const form = useForm({ body: '' });
  const [removing, setRemoving] = useState<CustomerNote | null>(null);
  const { busy, run } = useAction();

  async function add(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(`/customers/${customer.id}/notes`, { method: 'POST', body: form.values }), 'Note added.')) !== undefined) {
      form.reset({ body: '' });
      list.reload();
    }
  }

  return (
    <Card title="Notes" description="For your team only. Customers never see them.">
      {list.error ? (
        <ErrorPanel message={list.error} onRetry={list.reload} />
      ) : list.data === null ? (
        <Skeleton lines={2} />
      ) : list.data.data.length === 0 ? (
        <p className="text-sm text-slate-600">No notes yet.</p>
      ) : (
        <ul className="space-y-3">
          {list.data.data.map((note) => (
            <li key={note.id} className="rounded-md border border-slate-200 p-3">
              <p className="whitespace-pre-wrap text-sm text-slate-900">{note.body}</p>
              <div className="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                <span>{note.author ?? 'Former team member'} · {formatDateTime(note.created_at)}</span>
                {canManage && <Button size="sm" variant="ghost" onClick={() => setRemoving(note)}>Delete<span className="sr-only"> note</span></Button>}
              </div>
            </li>
          ))}
        </ul>
      )}
      {canManage && !customer.erased && (
        <form onSubmit={add} className="mt-4 space-y-2 border-t border-slate-200 pt-4" noValidate>
          <FormError message={form.formError} />
          <TextAreaField label="Add a note" rows={3} value={form.values.body} onChange={(v) => form.set('body', v)} error={form.errors.body} maxLength={5000} hint="For example a preferred delivery time." />
          <div className="flex justify-end">
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Adding…" disabled={form.values.body.trim() === ''}>Add note</Button>
          </div>
        </form>
      )}
      <ConfirmDialog
        open={removing !== null}
        title="Delete this note?"
        confirmLabel="Delete note"
        busy={busy === 'delete'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/customers/${customer.id}/notes/${removing.id}`, { method: 'DELETE' }), { success: 'Note deleted.' }).then((result) => {
            if (result !== undefined) {
              setRemoving(null);
              list.reload();
            }
          })
        }
      >
        <p>The note is deleted for good. That it existed stays in the activity.</p>
      </ConfirmDialog>
    </Card>
  );
}

function Activity({ customerId, version }: { customerId: string; version: number }) {
  const state = useApi<{ data: ActivityEvent[] }>(`/customers/${customerId}/activity`, { v: version || undefined });

  return (
    <Card title="Activity">
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : state.data === null ? (
        <Skeleton lines={3} />
      ) : state.data.data.length === 0 ? (
        <p className="text-sm text-slate-600">Nothing recorded yet.</p>
      ) : (
        <ol className="space-y-3 text-sm">
          {state.data.data.map((event, index) => (
            <li key={`${event.at}-${index}`} className="border-l-2 border-slate-200 pl-3">
              <p className="font-medium text-slate-900">
                {ACTIVITY_LABELS[event.type] ?? humanize(event.type)}
                {event.order && (
                  <>
                    {' '}
                    <Link href={`/orders/${event.order.id}`} className={`rounded font-mono font-normal text-indigo-700 hover:underline ${FOCUS_RING}`}>{event.order.order_number}</Link>
                  </>
                )}
              </p>
              {typeof event.details.reason === 'string' && <p className="text-slate-700">Reason: {event.details.reason}</p>}
              {typeof event.details.group === 'string' && <p className="text-slate-700">Group: {event.details.group}</p>}
              {Array.isArray(event.details.tags) && <p className="text-slate-700">Tags: {(event.details.tags as string[]).join(', ') || 'none'}</p>}
              <p className="text-xs text-slate-500">
                {formatDateTime(event.at)}
                {event.by && event.by_type === 'user' ? ` · by ${event.by}` : ''}
              </p>
            </li>
          ))}
        </ol>
      )}
    </Card>
  );
}

/**
 * Phase B46 (Module 11 §28): a customer the store does not charge tax, with
 * the certificate or registration number it relies on. Needs tax.manage;
 * new orders follow it, placed orders keep their tax.
 */
function TaxExemption({ customer, canChange, onChanged }: { customer: CustomerDetail; canChange: boolean; onChanged: () => void }) {
  const [editing, setEditing] = useState(false);
  const form = useForm({ tax_exempt: customer.tax_exempt === true, tax_exemption_reference: customer.tax_exemption_reference ?? '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = { tax_exempt: form.values.tax_exempt, tax_exemption_reference: form.values.tax_exempt ? form.values.tax_exemption_reference : null };
    if ((await form.submit(() => adminFetch(`/customers/${customer.id}/tax-exemption`, { method: 'PUT', body }), 'Tax exemption saved.')) !== undefined) {
      setEditing(false);
      onChanged();
    }
  }

  return (
    <Card title="Tax" actions={canChange && !editing && <Button size="sm" onClick={() => setEditing(true)}>Change</Button>}>
      {editing ? (
        <form onSubmit={save} className="space-y-3" noValidate>
          <FormError message={form.formError} />
          <CheckboxField label="Exempt from tax" checked={form.values.tax_exempt} onChange={(x) => form.set('tax_exempt', x)} hint="New orders for this customer are charged no tax." />
          {form.values.tax_exempt && <TextField label="Certificate or registration number" value={form.values.tax_exemption_reference} onChange={(x) => form.set('tax_exemption_reference', x)} error={form.errors.tax_exemption_reference} maxLength={80} required />}
          <div className="flex justify-end gap-2">
            <Button onClick={() => setEditing(false)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save</Button>
          </div>
        </form>
      ) : customer.tax_exempt ? (
        <p className="text-sm">Exempt from tax <span className="text-slate-600">({customer.tax_exemption_reference})</span></p>
      ) : (
        <p className="text-sm text-slate-600">Charged tax as usual.</p>
      )}
    </Card>
  );
}

function Privacy({ customer, onErased }: { customer: CustomerDetail; onErased: () => void }) {
  const [erasing, setErasing] = useState(false);
  const form = useForm({ reason: '', confirm_email: '' });
  const { busy, run } = useAction();

  async function erase(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(`/customers/${customer.id}/erase`, { method: 'POST', body: form.values }), 'Personal data erased.')) !== undefined) {
      setErasing(false);
      onErased();
    }
  }

  function exportData() {
    void run('export', () => adminFetch<{ data: unknown }>(`/customers/${customer.id}/personal-data`)).then((result) => {
      if (!result) return;
      const url = URL.createObjectURL(new Blob([JSON.stringify(result.data, null, 2)], { type: 'application/json' }));
      const link = document.createElement('a');
      link.href = url;
      link.download = `personal-data-${customer.id}.json`;
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      toast.success('Personal data downloaded.');
    });
  }

  return (
    <Card title="Privacy requests" description="When this person asks for their data, or to be forgotten (Module 32).">
      <div className="flex flex-wrap gap-2">
        <Button onClick={exportData} busy={busy === 'export'} busyLabel="Preparing…">Download their personal data</Button>
        {!customer.erased && <Button variant="danger" onClick={() => { form.reset({ reason: '', confirm_email: '' }); setErasing(true); }}>Erase personal data…</Button>}
      </div>
      <Dialog open={erasing} title="Erase this person's personal data?" description="Their name, email, phone, addresses, notes and account are removed for good. Orders stay for your records, without their details. This cannot be undone. You will be asked for your password." onClose={() => setErasing(false)} busy={form.busy}>
        <form onSubmit={erase} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <TextField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={500} required hint="For example: request by email on 2 October." data-autofocus />
          <TextField label={`Type their email (${customer.email}) to confirm`} value={form.values.confirm_email} onChange={(v) => form.set('confirm_email', v)} error={form.errors.confirm_email} autoComplete="off" required />
          <div className="flex justify-end gap-2">
            <Button onClick={() => setErasing(false)} disabled={form.busy}>Keep the data</Button>
            <Button type="submit" variant="danger" busy={form.busy} busyLabel="Erasing…" disabled={form.values.confirm_email.trim().toLowerCase() !== customer.email.toLowerCase() || form.values.reason.trim() === ''}>
              Erase for good
            </Button>
          </div>
        </form>
      </Dialog>
    </Card>
  );
}

/**
 * Module 10 §56: joins this record (a duplicate) into the customer who
 * stays. Staff choose the other record, give a reason and type its
 * email; the server asks for the password again and refuses what would
 * lose a sign-in or a block. Nothing is merged automatically.
 */
function MergeDialog({ customer, preset, onClose }: { customer: CustomerDetail; preset: { id: string; name: string; email: string } | null; onClose: () => void }) {
  const [target, setTarget] = useState<{ id: string; name: string; email: string } | null>(preset);
  const [text, setText] = useState('');
  const results = usePagedApi<CustomerRow>(target === null && text.trim().length >= 2 ? '/customers' : null, { search: text.trim(), status: 'active', per_page: 8 });
  const form = useForm({ reason: '', confirm_email: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    if (target === null) {
      form.setFormError('Choose the customer who stays.');

      return;
    }
    const done = await form.submit(
      () => adminFetch<{ data: { into: string } }>(`/customers/${customer.id}/merge`, { method: 'POST', body: { into: target.id, ...form.values } }),
      'Customers merged.',
    );
    if (done) router.visit(`/customers/${done.data.into}`);
  }

  return (
    <Dialog
      open
      wide
      title={`Merge ${customer.name} into another customer`}
      description="Orders, addresses, notes, tags, wishlist and messages of this record move to the customer who stays. That customer keeps their own name, email, account and marketing choice. This record is archived and marked as merged. It cannot be undone. You will be asked for your password."
      onClose={onClose}
      busy={form.busy}
    >
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} errors={form.errors} />
        {target === null ? (
          <div>
            <TextField label="Find the customer who stays" type="search" value={text} onChange={setText} autoComplete="off" hint="Name, email or phone. Only active customers." data-autofocus />
            {results.rows && (
              <ul className="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200">
                {results.rows.filter((row) => row.id !== customer.id && !row.merged).map((row) => (
                  <li key={row.id}>
                    <button type="button" onClick={() => setTarget({ id: row.id, name: row.name, email: row.email })} className={`w-full p-2 text-left text-sm hover:bg-slate-50 ${FOCUS_RING}`}>
                      <span className="font-medium text-slate-900">{row.name}</span> <span className="text-slate-600">{row.email}</span>
                      {row.registered && <Badge tone="blue">Has an account</Badge>}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        ) : (
          <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 p-3 text-sm">
            <p>
              Stays: <strong>{target.name}</strong> <span className="text-slate-600">{target.email}</span>
            </p>
            <Button size="sm" onClick={() => setTarget(null)} disabled={form.busy}>Choose another</Button>
          </div>
        )}
        <TextField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={500} required hint="For example: the same person, added twice." />
        {target && (
          <TextField label={`Type the email of the customer who stays (${target.email}) to confirm`} value={form.values.confirm_email} onChange={(v) => form.set('confirm_email', v)} error={form.errors.confirm_email} autoComplete="off" required />
        )}
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button
            type="submit"
            variant="danger"
            busy={form.busy}
            busyLabel="Merging…"
            disabled={target === null || form.values.reason.trim() === '' || form.values.confirm_email.trim().toLowerCase() !== target.email.toLowerCase()}
          >
            Merge for good
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

export default function Show({ customerId }: { customerId: string }) {
  const access = useAccess();
  // Owner decision 2026-10-03 (Module 10 §87): groups, tags and merge are Business/Premium.
  const advanced = access.feature(ADVANCED_FEATURE) === true;
  const state = useApi<{ data: CustomerDetail }>(`/customers/${customerId}`);
  const [dialog, setDialog] = useState<'edit' | 'block' | 'archive' | 'reactivate' | 'merge' | null>(null);
  const [mergeInto, setMergeInto] = useState<{ id: string; name: string; email: string } | null>(null);
  const [version, setVersion] = useState(0);
  const customer = state.data?.data ?? null;

  // A change returns the customer row; the detail (addresses, duplicates) is fetched again around it.
  const changed = (row?: CustomerRow) => {
    if (row && customer) state.setData({ data: { ...customer, ...row } });
    state.reload();
    setVersion((v) => v + 1);
    setDialog(null);
  };

  if (state.error) {
    return (
      <AdminPage title="Customer" trail={[{ label: 'Customer' }]}>
        {state.errorStatus === 403 ? <AccessNotice message={state.error} /> : state.errorStatus === 404 ? (
          <EmptyPanel title="Customer not found" description="They are not a customer of this store." action={<ButtonLink href="/customers">Back to customers</ButtonLink>} />
        ) : <ErrorPanel message={adminErrorMessage(null, state.error)} onRetry={state.reload} />}
      </AdminPage>
    );
  }
  if (customer === null) {
    return <AdminPage title="Customer" trail={[{ label: 'Customer' }]}><Skeleton lines={6} /></AdminPage>;
  }

  // A merged record is history only: nothing about it changes any more.
  const merged = customer.merged === true;
  const canManage = access.can('customers.manage') && !merged;
  const canMerge = canManage && advanced && !customer.erased && customer.status !== 'blocked';
  const openMerge = (into: { id: string; name: string; email: string } | null) => {
    setMergeInto(into);
    setDialog('merge');
  };

  return (
    <AdminPage
      title={customer.name}
      trail={[{ label: customer.name }]}
      description={customer.email}
      actions={
        <>
          <ButtonLink href="/customers">All customers</ButtonLink>
          {access.can('orders.create') && customer.status === 'active' && !customer.erased && <ButtonLink href={`/orders/new?customer=${customer.id}`}>Create order</ButtonLink>}
          {canManage && !customer.erased && <Button onClick={() => setDialog('edit')}>Edit</Button>}
          {canManage && !customer.erased && customer.status === 'active' && (
            <>
              <Button onClick={() => setDialog('archive')}>Archive</Button>
              <Button variant="danger" onClick={() => setDialog('block')}>Block</Button>
            </>
          )}
          {canManage && !customer.erased && customer.status !== 'active' && <Button variant="primary" onClick={() => setDialog('reactivate')}>{customer.status === 'blocked' ? 'Unblock' : 'Restore'}</Button>}
          {canMerge && !customer.registered && <Button onClick={() => openMerge(null)}>Merge into…</Button>}
        </>
      }
    >
      <div className="space-y-4">
        {customer.erased && <div role="status" className="rounded-md border border-slate-300 bg-slate-100 p-3 text-sm text-slate-800">This person's personal data was erased. The record stays only so that past orders add up.</div>}
        {merged && customer.merged_into && (
          <div role="status" className="rounded-md border border-slate-300 bg-slate-100 p-3 text-sm text-slate-800">
            This record was merged into{' '}
            <Link href={`/customers/${customer.merged_into.id}`} className={`rounded font-medium underline ${FOCUS_RING}`}>{customer.merged_into.name}</Link>
            {customer.merged_into.at && ` on ${formatDate(customer.merged_into.at)}`}. Its orders and details are there now.
          </div>
        )}
        {!merged && !customer.erased && customer.status !== 'active' && (
          <div role="status" className={`rounded-md border p-3 text-sm ${customer.status === 'blocked' ? 'border-red-200 bg-red-50 text-red-900' : 'border-amber-300 bg-amber-50 text-amber-900'}`}>
            <strong>{customer.status === 'blocked' ? 'Blocked' : 'Archived'}</strong>
            {customer.status_changed_at && ` on ${formatDate(customer.status_changed_at)}`}
            {customer.status_reason && `: ${customer.status_reason}`}
            {customer.status === 'blocked' ? '. They cannot sign in or place orders.' : '. They cannot sign in and get no marketing email.'}
          </div>
        )}
        {customer.possible_duplicates.length > 0 && (
          <div role="note" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
            <p className="font-medium">This may be the same person as:</p>
            <ul className="mt-1 list-disc pl-5">
              {customer.possible_duplicates.map((other) => (
                <li key={other.id}>
                  <Link href={`/customers/${other.id}`} className={`rounded underline ${FOCUS_RING}`}>{other.name}</Link> ({other.email}) — {other.reason === 'same_email' ? 'same email' : 'same phone'}
                  {canMerge && !customer.registered && (
                    <>
                      {' '}
                      <Button size="sm" variant="ghost" onClick={() => openMerge(other)}>Merge this record into {other.name}</Button>
                    </>
                  )}
                </li>
              ))}
            </ul>
            <p className="mt-1">Nothing is merged automatically. Check before acting.{customer.registered ? ' This customer has an account, so the other record is merged into this one: open it to merge.' : ''}{!advanced && canManage ? ' Merging comes with the Business and Premium packages.' : ''}</p>
          </div>
        )}

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard label="Orders" value={customer.orders_count ?? 0} />
          <StatCard label="Spent" value={money(customer.total_spent_minor ?? 0, access.currency)} hint="Orders not cancelled." />
          <StatCard label="Average order" value={customer.average_order_value_minor === null ? null : money(customer.average_order_value_minor, access.currency)} />
          <StatCard label="Last order" value={customer.last_order_at ? formatDate(customer.last_order_at) : null} hint={customer.first_order_at ? `First: ${formatDate(customer.first_order_at)}` : undefined} />
        </div>

        <div className="grid gap-4 lg:grid-cols-3">
          <div className="space-y-4 lg:col-span-2">
            <Card title="Profile">
              <Details
                items={[
                  { label: 'Email', value: <>{customer.email} {customer.registered && <Badge tone={customer.email_verified ? 'green' : 'amber'}>{customer.email_verified ? 'Confirmed' : 'Not confirmed'}</Badge>}</> },
                  { label: 'Phone', value: customer.phone ?? '—' },
                  { label: 'Account', value: customer.registered ? 'Has an account' : 'No account' },
                  { label: 'Came from', value: customer.source ? (SOURCE_LABELS[customer.source] ?? humanize(customer.source)) : 'Storefront' },
                  { label: 'Marketing email', value: customer.marketing_email_opt_in ? 'Agreed' : 'Not agreed' },
                  { label: 'Customer since', value: dateTimeOrDash(customer.created_at) },
                ]}
              />
              <p className="mt-3 text-xs text-slate-500">Only the customer can change their marketing email choice.</p>
            </Card>
            <Orders customer={customer} />
            <Notes customer={customer} canManage={canManage} />
          </div>
          <div className="space-y-4">
            <GroupAndTags customer={customer} canManage={canManage && advanced} packageLocked={canManage && !advanced} onChanged={changed} />
            {(customer.merged_from ?? []).length > 0 && (
              <Card title="Merged into this customer">
                <ul className="space-y-1 text-sm">
                  {(customer.merged_from ?? []).map((record) => (
                    <li key={record.id}>
                      <Link href={`/customers/${record.id}`} className={`rounded text-indigo-700 hover:underline ${FOCUS_RING}`}>{record.name}</Link>{' '}
                      <span className="text-slate-600">on {formatDate(record.at)}</span>
                    </li>
                  ))}
                </ul>
              </Card>
            )}
            <Card title="Addresses">
              {customer.addresses.length === 0 ? (
                <p className="text-sm text-slate-600">No saved addresses.</p>
              ) : (
                <ul className="space-y-3 text-sm">
                  {customer.addresses.map((address, index) => (
                    <li key={index}>
                      <p className="font-medium text-slate-900">{address.label ?? 'Address'} {address.is_default && <Badge tone="blue">Default</Badge>}</p>
                      <address className="not-italic text-slate-700">
                        {[address.name, address.line1, address.line2, [address.city, address.province, address.postal_code].filter(Boolean).join(' '), address.country, address.phone].filter(Boolean).map((line, i) => <span key={i} className="block">{line}</span>)}
                      </address>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
            {!merged && <StoreCreditCard customerId={customer.id} version={version} />}
            {(access.can('tax.manage') || customer.tax_exempt) && (
              <TaxExemption key={`${customer.tax_exempt}-${customer.tax_exemption_reference}`} customer={customer} canChange={access.can('tax.manage') && access.can('customers.manage') && !merged} onChanged={changed} />
            )}
            <Activity customerId={customer.id} version={version} />
            {access.can('privacy.manage') && <Privacy customer={customer} onErased={() => changed()} />}
          </div>
        </div>
      </div>

      {dialog === 'edit' && <EditDialog customer={customer} onClose={() => setDialog(null)} onDone={changed} />}
      {dialog === 'merge' && <MergeDialog customer={customer} preset={mergeInto} onClose={() => setDialog(null)} />}
      {(dialog === 'block' || dialog === 'archive' || dialog === 'reactivate') && <StatusDialog customer={customer} action={dialog} onClose={() => setDialog(null)} onDone={changed} />}
    </AdminPage>
  );
}
