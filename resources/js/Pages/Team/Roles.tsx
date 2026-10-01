import { FormEvent, useMemo, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink } from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { CheckboxField, FormError, TextField } from '@/Components/ui/Form';
import Badge, { humanize } from '@/Components/ui/Badge';
import { EmptyPanel, PackageNotice } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';

/**
 * Module 02 roles (/api/v1/roles, /permissions): what each role of the
 * store may do.
 *
 * The built-in roles cannot be changed. Roles of your own are part of
 * the `custom_roles.enabled` package feature and are created by the
 * Store Owner; the server enforces both. This page only edits a role's
 * list of permissions. Whether a person may do something is decided on
 * the server from that list, on every request.
 */
type Role = { id: number; name: string; slug: string; is_system: boolean; permissions?: string[] };
type Permission = { key: string; group: string; description: string | null };

function RoleDialog({ role, catalog, readOnly, onClose, onDone }: { role: Role | null; catalog: Permission[]; readOnly: boolean; onClose: () => void; onDone: () => void }) {
  const form = useForm<{ name: string; permission_keys: string[] }>({ name: role?.name ?? '', permission_keys: role?.permissions ?? [] });
  const groups = useMemo(() => {
    const map = new Map<string, Permission[]>();
    for (const permission of catalog) map.set(permission.group, [...(map.get(permission.group) ?? []), permission]);

    return [...map.entries()];
  }, [catalog]);
  const chosen = form.values.permission_keys;

  async function save(event: FormEvent) {
    event.preventDefault();
    const saved = await form.submit(() => adminFetch(role === null ? '/roles' : `/roles/${role.id}`, { method: role === null ? 'POST' : 'PUT', body: form.values }), role === null ? 'Role created.' : 'Role saved.');
    if (saved !== undefined) onDone();
  }

  return (
    <Dialog
      open
      wide
      title={role === null ? 'Add role' : readOnly ? `${role.name}: what it may do` : `Edit ${role.name}`}
      description={role?.slug === 'owner' ? 'The Owner may do everything in the store.' : readOnly && role?.is_system ? 'A built-in role. It cannot be changed.' : undefined}
      onClose={onClose}
      busy={form.busy}
    >
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError ?? form.errors.role} />
        {!readOnly && <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} data-autofocus />}
        {role?.slug !== 'owner' && (
          <div className="space-y-4">
            {groups.map(([group, permissions]) => (
              <fieldset key={group}>
                <legend className="text-sm font-semibold text-slate-900">{humanize(group)}</legend>
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                  {permissions.map((permission) => (
                    <CheckboxField
                      key={permission.key}
                      label={humanize(permission.key.split('.').slice(1).join(' ') || permission.key)}
                      hint={permission.description ?? undefined}
                      checked={chosen.includes(permission.key)}
                      disabled={readOnly}
                      onChange={(checked) => form.set('permission_keys', checked ? [...chosen, permission.key] : chosen.filter((key) => key !== permission.key))}
                    />
                  ))}
                </div>
              </fieldset>
            ))}
          </div>
        )}
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>{readOnly ? 'Close' : 'Cancel'}</Button>
          {!readOnly && <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save role</Button>}
        </div>
      </form>
    </Dialog>
  );
}

export default function Roles() {
  const access = useAccess();
  const roles = useApi<{ data: Role[] }>('/roles');
  const catalog = useApi<{ data: Permission[] }>('/permissions');
  const [open, setOpen] = useState<Role | 'new' | null>(null);
  const [removing, setRemoving] = useState<Role | null>(null);
  const [removeError, setRemoveError] = useState<string | null>(null);
  const { busy, run } = useAction();

  const included = access.feature('custom_roles.enabled') !== false;
  // Creating and changing roles is the Store Owner's (RolePolicy); the server checks again.
  const canEdit = access.isOwner && included;

  const columns: Column<Role>[] = [
    { key: 'name', header: 'Role', render: (role) => <span className="font-medium">{role.name}</span> },
    { key: 'kind', header: 'Kind', priority: true, render: (role) => <Badge tone={role.is_system ? 'neutral' : 'blue'}>{role.is_system ? 'Built in' : 'Your own'}</Badge> },
    { key: 'count', header: 'Permissions', align: 'right', render: (role) => (role.slug === 'owner' ? 'All' : (role.permissions?.length ?? 0)) },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (role) => (
        <span className="flex flex-wrap justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => setOpen(role)}>{canEdit && !role.is_system ? 'Edit' : 'View'}<span className="sr-only"> {role.name}</span></Button>
          {canEdit && !role.is_system && <Button size="sm" variant="ghost" onClick={() => { setRemoveError(null); setRemoving(role); }}>Delete<span className="sr-only"> {role.name}</span></Button>}
        </span>
      ),
    },
  ];

  return (
    <AdminPage
      title="Roles"
      description="What each role may do in your store. Give people a role on the Team page."
      actions={
        <>
          <ButtonLink href="/team">Team</ButtonLink>
          {access.isOwner && <Button variant="primary" disabled={!included} title={!included ? 'Not included in your package' : undefined} onClick={() => setOpen('new')}>Add role</Button>}
        </>
      }
    >
      {!included && (
        <div className="mb-4">
          <PackageNotice title="Roles of your own are not included in your package" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
            The built-in roles below are available on every package.
          </PackageNotice>
        </div>
      )}

      <DataTable caption="Roles" columns={columns} rows={roles.data?.data ?? null} rowKey={(role) => role.id} loading={roles.loading} error={roles.error ?? catalog.error} onRetry={() => { roles.reload(); catalog.reload(); }} empty={<EmptyPanel title="No roles" />} />

      {open !== null && catalog.data && (
        <RoleDialog
          role={open === 'new' ? null : open}
          catalog={catalog.data.data}
          readOnly={open !== 'new' && (!canEdit || open.is_system)}
          onClose={() => setOpen(null)}
          onDone={() => {
            setOpen(null);
            roles.reload();
          }}
        />
      )}

      <ConfirmDialog
        open={removing !== null}
        title="Delete this role?"
        confirmLabel="Delete role"
        busy={busy === 'delete'}
        error={removeError}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/roles/${removing.id}`, { method: 'DELETE' }), { success: 'Role deleted.', onError: setRemoveError }).then((result) => {
            if (result !== undefined) {
              setRemoving(null);
              roles.reload();
            }
          })
        }
      >
        <p>
          <strong>{removing?.name}</strong> is deleted for good. A role that people still have, or that an invitation used, cannot be deleted; the server will say so.
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}
