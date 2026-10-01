import { useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import Badge, { StatusBadge } from '@/Components/ui/Badge';
import { Card, EmptyPanel } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { usePagedApi } from '@/lib/useApi';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { dateTimeOrDash, formatDateTime } from '@/lib/datetime';
import { formatBytes, triggerLabel, type Backup, type RestoreJob } from '@/lib/backups';

/**
 * Module 23 §55 "Tenant Backup Dashboard": the store's own backup
 * history (/api/v1/backups), as the server records it.
 *
 * - A backup file is never downloadable; this page has no file links.
 * - "Request a restore" only files a request. The server asks for the
 *   password again (step-up) before it accepts it, and nothing is
 *   restored until platform staff authorize it with their own checks.
 * - The platform also takes its own scheduled backups; those are not
 *   listed here.
 */
export default function Index() {
  const access = useAccess();
  const [page, setPage] = useState(1);
  const list = usePagedApi<Backup>('/backups', { page });
  const [requesting, setRequesting] = useState<Backup | null>(null);
  const [requestError, setRequestError] = useState<string | null>(null);
  const [filed, setFiled] = useState<RestoreJob | null>(null);
  const { busy, run } = useAction();

  const columns: Column<Backup>[] = [
    {
      key: 'created',
      header: 'Backup',
      render: (backup) => (
        <div>
          <span className="font-medium">{formatDateTime(backup.created_at)}</span>
          <p className="text-xs text-slate-500">{triggerLabel(backup)} · <span className="font-mono">{backup.id.slice(-8)}</span></p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (backup) => <StatusBadge status={backup.status} /> },
    { key: 'size', header: 'Size', align: 'right', render: (backup) => formatBytes(backup.size_bytes) },
    { key: 'protected', header: 'Protection', render: (backup) => <Badge tone={backup.is_encrypted ? 'green' : 'amber'}>{backup.is_encrypted ? 'Encrypted' : 'Not encrypted'}</Badge> },
    { key: 'verified', header: 'Verified', render: (backup) => dateTimeOrDash(backup.verified_at) },
    { key: 'expires', header: 'Kept until', render: (backup) => dateTimeOrDash(backup.expires_at) },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (backup) =>
        access.can('backups.restore') && backup.status === 'verified' && (
          <Button size="sm" variant="ghost" onClick={() => { setRequestError(null); setRequesting(backup); }}>Request a restore</Button>
        ),
    },
  ];

  return (
    <AdminPage
      title="Backups"
      description="Backups of your store's data and their state."
      actions={
        access.can('backups.manage') && (
          <Button
            variant="primary"
            busy={busy === 'backup'}
            busyLabel="Starting…"
            onClick={() => run('backup', () => adminFetch('/backups', { method: 'POST', timeoutMs: 60000 }), { success: 'Backup started.' }).then((result) => result !== undefined && list.reload())}
          >
            Back up now
          </Button>
        )
      }
    >
      {filed && (
        <div role="status" className="mb-4 rounded-md border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900">
          <p className="font-medium">{filed.status === 'requested' ? 'Restore request filed' : `Restore request: ${filed.status.replace(/_/g, ' ')}`}</p>
          <p className="mt-1">
            {filed.failure_reason ?? 'The platform team has been asked. Nothing is restored until they authorize it. Contact support with an explanation of what went wrong so they can act on it.'}
          </p>
        </div>
      )}

      <DataTable
        caption="Backups"
        columns={columns}
        rows={list.rows}
        rowKey={(backup) => backup.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No backups of your own yet" description="The platform backs up all data every day. You can also take a backup yourself before a big change." />}
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />

      <div className="mt-6">
        <Card title="Good to know">
          <ul className="list-disc space-y-1 pl-5 text-sm text-slate-700">
            <li>You can start a few backups per hour. If you are asked to wait, try again later.</li>
            <li>A backup that failed shows as Failed; the platform team is told automatically.</li>
            <li>Backup files cannot be downloaded from the admin.</li>
          </ul>
        </Card>
      </div>

      <ConfirmDialog
        open={requesting !== null}
        title="Request a restore from this backup?"
        confirmLabel="File the request"
        confirmText={requesting?.id.slice(-8)}
        busy={busy === 'restore'}
        error={requestError}
        onClose={() => setRequesting(null)}
        onConfirm={() =>
          requesting &&
          run('restore', () => adminFetch<{ data: RestoreJob }>(`/backups/${requesting.id}/restore-request`, { method: 'POST' }), { onError: setRequestError }).then((result) => {
            if (result) {
              setFiled(result.data);
              setRequesting(null);
            }
          })
        }
      >
        <p>
          A restore puts data back to how it was on <strong>{requesting ? formatDateTime(requesting.created_at) : ''}</strong>. Everything changed after that moment
          would be lost.
        </p>
        <p>This only files a request. You will be asked for your password, and nothing is restored until platform staff review and authorize it.</p>
      </ConfirmDialog>
    </AdminPage>
  );
}
