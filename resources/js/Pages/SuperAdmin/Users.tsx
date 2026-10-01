import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { FormError, TextField } from '@/Components/ui/Form';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import Badge from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';

/**
 * Module 30 user administration: every account on the platform
 * (/api/v1/super-admin/users). Deactivating and reactivating an account
 * are sensitive: the server asks for the password again (step-up) and
 * records who did it.
 *
 * There is no "reset two-step sign-in" here, on purpose. A lost
 * authenticator is recovered only by the server-side emergency procedure
 * in the incident runbook; the application has no such action.
 */
type User = { id: number; name: string; email: string; is_active: boolean; platform_role: string | null; stores?: { id: number; name: string }[] };

function DeactivateDialog({ user, onClose, onDone }: { user: User; onClose: () => void; onDone: () => void }) {
  const form = useForm({ reason: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    if (form.values.reason.trim() === '') {
      form.setFormError('Give the reason. It is recorded in the audit trail.');

      return;
    }
    if ((await form.submit(() => adminFetch(`/super-admin/users/${user.id}/deactivate`, { method: 'POST', body: form.values }), 'Account deactivated.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={`Deactivate ${user.name}?`} description={`${user.email} can no longer sign in to any store until the account is reactivated. You will be asked for your password.`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} maxLength={500} required data-autofocus />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="danger" busy={form.busy} busyLabel="Working…">Deactivate account</Button>
        </div>
      </form>
    </Dialog>
  );
}

export default function Users() {
  const [filters, setFilters] = useUrlState({ search: '', page: '1' });
  const list = usePagedApi<User>('/super-admin/users', { search: filters.search, page: filters.page });
  const [deactivating, setDeactivating] = useState<User | null>(null);
  const { busy, run } = useAction();

  const columns: Column<User>[] = [
    {
      key: 'name',
      header: 'Account',
      render: (user) => (
        <div>
          <span className="font-medium">{user.name}</span> {user.platform_role && <Badge tone="blue">Platform staff</Badge>}
          <p className="text-xs text-slate-600">{user.email}</p>
        </div>
      ),
    },
    { key: 'stores', header: 'Stores', render: (user) => ((user.stores ?? []).length === 0 ? '—' : (user.stores ?? []).map((store) => store.name).join(', ')) },
    { key: 'state', header: 'Status', priority: true, render: (user) => <Badge tone={user.is_active ? 'green' : 'red'}>{user.is_active ? 'Active' : 'Deactivated'}</Badge> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (user) =>
        user.is_active ? (
          <Button size="sm" variant="ghost" onClick={() => setDeactivating(user)}>Deactivate<span className="sr-only"> {user.name}</span></Button>
        ) : (
          <Button size="sm" variant="ghost" busy={busy === `reactivate:${user.id}`} onClick={() => run(`reactivate:${user.id}`, () => adminFetch(`/super-admin/users/${user.id}/reactivate`, { method: 'POST' }), { success: 'Account reactivated.' }).then((result) => result !== undefined && list.reload())}>
            Reactivate<span className="sr-only"> {user.name}</span>
          </Button>
        ),
    },
  ];

  return (
    <AdminPage title="Users" description="Every account on the platform: store owners, store staff and platform staff.">
      <FilterBar>
        <SearchField label="Search" placeholder="Name or email" value={filters.search} onChange={(search) => setFilters({ search, page: '1' })} />
      </FilterBar>
      <DataTable caption="Accounts" columns={columns} rows={list.rows} rowKey={(user) => user.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title={filters.search ? 'No account matches' : 'No accounts'} />} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />
      {deactivating && <DeactivateDialog user={deactivating} onClose={() => setDeactivating(null)} onDone={() => { setDeactivating(null); list.reload(); }} />}
    </AdminPage>
  );
}
