import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink, FOCUS_RING } from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, TextAreaField, TextField } from '@/Components/ui/Form';
import { AccessNotice, Card, PackageNotice } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { ADVANCED_FEATURE, type CustomerGroup, type CustomerTag } from '@/lib/customers';

/**
 * Module 10 §23–24 (Phase B32, gap G7): the store's customer groups and
 * tags. A customer is in at most one group; tags are free labels. Tags
 * are created where they are given to a customer; here they can be
 * renamed or removed from everyone at once. Deleting a group leaves its
 * customers in no group; nothing else about them changes.
 */
function GroupDialog({ group, onClose, onDone }: { group: CustomerGroup | null; onClose: () => void; onDone: () => void }) {
  const form = useForm({ name: group?.name ?? '', description: group?.description ?? '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = { name: form.values.name, description: form.values.description === '' ? null : form.values.description };
    const saved = await form.submit(
      () => adminFetch(group ? `/customer-groups/${group.id}` : '/customer-groups', { method: group ? 'PATCH' : 'POST', body }),
      group ? 'Group saved.' : 'Group created.',
    );
    if (saved !== undefined) onDone();
  }

  return (
    <Dialog open title={group ? `Edit ${group.name}` : 'New customer group'} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={120} hint="For example Wholesale or Retail." data-autofocus />
        <TextAreaField label="Description" optional rows={2} value={form.values.description} onChange={(v) => form.set('description', v)} error={form.errors.description} maxLength={500} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">{group ? 'Save' : 'Create group'}</Button>
        </div>
      </form>
    </Dialog>
  );
}

function RenameTagDialog({ tag, onClose, onDone }: { tag: CustomerTag; onClose: () => void; onDone: () => void }) {
  const form = useForm({ name: tag.name });

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(`/customer-tags/${tag.id}`, { method: 'PATCH', body: form.values }), 'Tag renamed.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={`Rename tag ${tag.name}`} description="The new name shows on every customer with this tag." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={60} data-autofocus />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Rename</Button>
        </div>
      </form>
    </Dialog>
  );
}

const memberLink = (query: string, count: number) => (
  <Link href={`/customers?${query}`} className={`rounded text-indigo-700 hover:underline ${FOCUS_RING}`}>
    {count} {count === 1 ? 'customer' : 'customers'}
  </Link>
);

export default function Groups() {
  const access = useAccess();
  // Owner decision 2026-10-03: changing groups and tags is Business/Premium. What exists stays visible.
  const advanced = access.feature(ADVANCED_FEATURE) === true;
  const canManage = access.can('customers.manage') && advanced;
  const groups = useApi<{ data: CustomerGroup[] }>('/customer-groups');
  const tags = useApi<{ data: CustomerTag[] }>('/customer-tags');
  const [editing, setEditing] = useState<CustomerGroup | 'new' | null>(null);
  const [renaming, setRenaming] = useState<CustomerTag | null>(null);
  const [removing, setRemoving] = useState<{ kind: 'group'; item: CustomerGroup } | { kind: 'tag'; item: CustomerTag } | null>(null);
  const { busy, run } = useAction();

  if (groups.errorStatus === 403) {
    return (
      <AdminPage title="Groups and tags" trail={[{ label: 'Groups and tags' }]}>
        <AccessNotice message={groups.error ?? undefined} />
      </AdminPage>
    );
  }

  const groupColumns: Column<CustomerGroup>[] = [
    { key: 'name', header: 'Group', priority: true, render: (g) => <span className="font-medium text-slate-900">{g.name}</span> },
    { key: 'description', header: 'Description', render: (g) => g.description ?? '—' },
    { key: 'members', header: 'Customers', render: (g) => memberLink(`group=${g.id}`, g.customers_count) },
    ...(canManage
      ? [{
          key: 'actions',
          header: 'Actions',
          srOnlyHeader: true,
          align: 'right' as const,
          render: (g: CustomerGroup) => (
            <div className="flex justify-end gap-1">
              <Button size="sm" onClick={() => setEditing(g)}>Edit<span className="sr-only"> {g.name}</span></Button>
              <Button size="sm" variant="ghost" onClick={() => setRemoving({ kind: 'group', item: g })}>Delete<span className="sr-only"> {g.name}</span></Button>
            </div>
          ),
        }]
      : []),
  ];

  const tagColumns: Column<CustomerTag>[] = [
    { key: 'name', header: 'Tag', priority: true, render: (t) => <span className="font-medium text-slate-900">{t.name}</span> },
    { key: 'members', header: 'Customers', render: (t) => memberLink(`tag=${t.id}`, t.customers_count) },
    ...(canManage
      ? [{
          key: 'actions',
          header: 'Actions',
          srOnlyHeader: true,
          align: 'right' as const,
          render: (t: CustomerTag) => (
            <div className="flex justify-end gap-1">
              <Button size="sm" onClick={() => setRenaming(t)}>Rename<span className="sr-only"> {t.name}</span></Button>
              <Button size="sm" variant="ghost" onClick={() => setRemoving({ kind: 'tag', item: t })}>Delete<span className="sr-only"> {t.name}</span></Button>
            </div>
          ),
        }]
      : []),
  ];

  return (
    <AdminPage
      title="Groups and tags"
      trail={[{ label: 'Groups and tags' }]}
      description="Sort your customers: one group each, and as many tags as you need."
      actions={
        <>
          <ButtonLink href="/customers">All customers</ButtonLink>
          {canManage && <Button variant="primary" onClick={() => setEditing('new')}>New group</Button>}
        </>
      }
    >
      <div className="space-y-4">
        {!advanced && (
          <PackageNotice title="Customer groups and tags come with the Business and Premium packages" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
            Groups and tags you already have stay, and you can still filter customers by them.
          </PackageNotice>
        )}
        <Card title="Groups">
          <DataTable caption="Customer groups" columns={groupColumns} rows={groups.data?.data ?? []} rowKey={(g) => g.id} loading={groups.data === null && !groups.error} error={groups.error} onRetry={groups.reload} empty={<p className="text-sm text-slate-600">No groups yet.{canManage ? ' Create one with New group.' : ''}</p>} />
        </Card>
        <Card title="Tags" description="Tags are created when you give one to a customer.">
          <DataTable caption="Customer tags" columns={tagColumns} rows={tags.data?.data ?? []} rowKey={(t) => t.id} loading={tags.data === null && !tags.error} error={tags.error} onRetry={tags.reload} empty={<p className="text-sm text-slate-600">No tags yet. Add them on a customer's page.</p>} />
        </Card>
      </div>

      {editing !== null && (
        <GroupDialog
          group={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onDone={() => {
            setEditing(null);
            groups.reload();
          }}
        />
      )}
      {renaming !== null && (
        <RenameTagDialog
          tag={renaming}
          onClose={() => setRenaming(null)}
          onDone={() => {
            setRenaming(null);
            tags.reload();
          }}
        />
      )}
      <ConfirmDialog
        open={removing !== null}
        title={removing ? `Delete ${removing.kind} ${removing.item.name}?` : ''}
        confirmLabel={removing?.kind === 'group' ? 'Delete group' : 'Delete tag'}
        busy={busy === 'remove'}
        onClose={() => setRemoving(null)}
        onConfirm={() => {
          if (!removing) return;
          const path = removing.kind === 'group' ? `/customer-groups/${removing.item.id}` : `/customer-tags/${removing.item.id}`;
          void run('remove', () => adminFetch(path, { method: 'DELETE' }), { success: removing.kind === 'group' ? 'Group deleted.' : 'Tag deleted.' }).then((result) => {
            if (result === undefined) return;
            const kind = removing.kind;
            setRemoving(null);
            if (kind === 'group') groups.reload();
            else tags.reload();
          });
        }}
      >
        <p>
          {removing?.kind === 'group'
            ? `Its ${removing.item.customers_count} customers stay, in no group.`
            : `It is removed from ${removing?.item.customers_count ?? 0} customers.`}
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}
