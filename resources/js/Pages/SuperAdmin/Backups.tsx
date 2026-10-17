import { FormEvent, useCallback, useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { AdminCrumbs } from '@/Components/AdminPage';
import EmptyState from '@/Components/EmptyState';
import ErrorState from '@/Components/ErrorState';
import LoadingState from '@/Components/LoadingState';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { formatDateTime } from '@/lib/datetime';
import {
  backupTone, formatBytes, formatDurationMs, recoveryPointText, restoreTone, statusLabel, triggerLabel,
  type Backup, type BackupSummary, type RestoreJob, type Tone,
} from '@/lib/backups';

/**
 * Module 23 (Phase B30) — platform backups for Super Admin: what the
 * last backup and the last rehearsal were, run a backup, re-check one,
 * rehearse a restore, and authorize a requested production restore.
 *
 * The server decides everything. A production restore additionally
 * needs the backup id typed back, a reference, and a fresh password
 * (step-up) — this page only collects them.
 */
const TONES: Record<Tone, string> = {
  good: 'bg-green-100 text-green-800',
  bad: 'bg-red-100 text-red-800',
  busy: 'bg-blue-100 text-blue-800',
  neutral: 'bg-slate-100 text-slate-600',
};

function Badge({ tone, children }: { tone: Tone; children: string }) {
  return <span className={`whitespace-nowrap rounded px-2 py-0.5 text-xs ${TONES[tone]}`}>{children}</span>;
}

function Stat({ label, value, note, bad }: { label: string; value: string; note?: string; bad?: boolean }) {
  return (
    <div className={`rounded-2xl border bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)] ${bad ? 'border-red-300' : 'border-slate-200/80'}`}>
      <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
      <div className={`mt-1 text-lg font-semibold ${bad ? 'text-red-700' : ''}`}>{value}</div>
      {note && <p className="mt-1 text-sm text-slate-500">{note}</p>}
    </div>
  );
}

type Page<T> = { data: { data: T[] } };

export default function Backups() {
  const [summary, setSummary] = useState<BackupSummary | null>(null);
  const [backups, setBackups] = useState<Backup[] | null>(null);
  const [jobs, setJobs] = useState<RestoreJob[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'good' | 'bad'; text: string } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [restoring, setRestoring] = useState<RestoreJob | null>(null);

  const load = useCallback(() => {
    Promise.all([
      adminFetch<{ data: BackupSummary }>('/super-admin/backups/summary'),
      adminFetch<Page<Backup>>('/super-admin/backups'),
      adminFetch<Page<RestoreJob>>('/super-admin/restore-jobs'),
    ])
      .then(([s, b, j]) => {
        setSummary(s.data);
        setBackups(b.data.data);
        setJobs(j.data.data);
      })
      .catch((error) => setLoadError(adminErrorMessage(error)));
  }, []);

  useEffect(load, [load]);

  async function act(key: string, work: () => Promise<string>) {
    setBusy(key);
    setNotice(null);
    try {
      setNotice({ tone: 'good', text: await work() });
    } catch (error) {
      setNotice({ tone: 'bad', text: adminErrorMessage(error) });
    } finally {
      setBusy(null);
      load();
    }
  }

  const runBackup = () =>
    act('backup', async () => {
      await adminFetch('/super-admin/backups', { method: 'POST' });

      return 'A platform backup was requested. It appears in the list when it has run.';
    });

  const verify = (backup: Backup) =>
    act(`verify-${backup.id}`, async () => {
      const body = await adminFetch<{ data: { intact: boolean } }>(`/super-admin/backups/${backup.id}/verify`, { method: 'POST' });
      if (!body.data.intact) throw new Error('The stored backup no longer matches its checksum. It has been marked failed.');

      return 'The stored backup matches its recorded checksum.';
    });

  const rehearse = (backup: Backup) =>
    act(`rehearse-${backup.id}`, async () => {
      await adminFetch(`/super-admin/backups/${backup.id}/rehearse`, { method: 'POST' });

      return 'A restore rehearsal was queued. Its result appears under "Restores and rehearsals". Live data is not touched.';
    });

  return (
    <AuthenticatedLayout>
      <AdminCrumbs />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Platform backups</h1>
        <button onClick={runBackup} disabled={busy !== null} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-transparent bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:-translate-y-px hover:bg-brand-700 disabled:pointer-events-none disabled:opacity-50 motion-reduce:transform-none focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2">
          {busy === 'backup' ? 'Requesting…' : 'Back up now'}
        </button>
      </div>

      {loadError && <div className="mt-4"><ErrorState message={loadError} /></div>}
      {!summary && !loadError && <LoadingState />}

      {notice && (
        <p role="status" className={`mt-4 rounded-xl border p-3 text-sm ${notice.tone === 'good' ? 'border-green-300 bg-green-50 text-green-800' : 'border-red-300 bg-red-50 text-red-800'}`}>
          {notice.text}
        </p>
      )}

      {summary && (
        <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Stat
            label="Recovery point"
            value={summary.overdue ? 'Overdue' : summary.last_verified_at ? formatDateTime(summary.last_verified_at) : 'None yet'}
            note={recoveryPointText(summary)}
            bad={summary.overdue}
          />
          <Stat
            label="Last backup"
            value={formatBytes(summary.last_backup_size_bytes)}
            note={summary.last_backup_encrypted === null ? undefined : summary.last_backup_encrypted ? 'Encrypted.' : 'Not encrypted: no backup key is configured.'}
            bad={summary.last_backup_encrypted === false}
          />
          <Stat
            label="Last rehearsal"
            value={summary.last_rehearsal_status === null ? 'Never run' : statusLabel(summary.last_rehearsal_status)}
            note={
              summary.last_successful_rehearsal_at
                ? `Last passed ${formatDateTime(summary.last_successful_rehearsal_at)} in ${formatDurationMs(summary.last_successful_rehearsal_duration_ms)}. Recovery time target: ${summary.rto_target_hours} hours.`
                : `No rehearsal has passed yet. Recovery time target: ${summary.rto_target_hours} hours.`
            }
            bad={summary.last_rehearsal_status === 'failed'}
          />
          <Stat
            label="Failures"
            value={summary.consecutive_scheduled_failures > 0 ? `${summary.consecutive_scheduled_failures} in a row` : `${summary.failed_backups_last_30_days} in 30 days`}
            note={summary.last_failure_reason ? `Last: ${summary.last_failure_reason}` : `Next scheduled backup: ${formatDateTime(summary.next_scheduled_backup_at)}.`}
            bad={summary.consecutive_scheduled_failures > 0}
          />
        </div>
      )}

      {backups && (
        <section className="mt-8">
          <h2 className="font-medium">Backups</h2>
          {backups.length === 0 ? (
            <div className="mt-3"><EmptyState title="No backups yet" description="The first scheduled backup, or “Back up now”, will appear here." /></div>
          ) : (
            <div className="mt-3 overflow-x-auto rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
              <table className="w-full min-w-[760px] text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                  <tr>
                    <th className="px-3 py-2">Created</th><th className="px-3 py-2">Scope</th><th className="px-3 py-2">Started by</th>
                    <th className="px-3 py-2">Status</th><th className="px-3 py-2">Size</th><th className="px-3 py-2">Kept until</th><th className="px-3 py-2" />
                  </tr>
                </thead>
                <tbody>
                  {backups.map((backup) => (
                    <tr key={backup.id} className="border-b last:border-0 align-top">
                      <td className="px-3 py-2">
                        {formatDateTime(backup.created_at)}
                        <div className="font-mono text-xs text-slate-400">{backup.id}</div>
                      </td>
                      <td className="px-3 py-2 capitalize">{backup.scope}</td>
                      <td className="px-3 py-2">{triggerLabel(backup)}</td>
                      <td className="px-3 py-2">
                        <Badge tone={backupTone(backup.status)}>{statusLabel(backup.status)}</Badge>
                        {backup.failure_reason && <div className="mt-1 max-w-xs text-xs text-red-700">{backup.failure_reason}</div>}
                      </td>
                      <td className="px-3 py-2">{formatBytes(backup.size_bytes)}</td>
                      <td className="px-3 py-2">{backup.expires_at ? formatDateTime(backup.expires_at) : '—'}</td>
                      <td className="px-3 py-2 text-right">
                        {backup.status === 'verified' && (
                          <span className="flex justify-end gap-2">
                            <button onClick={() => verify(backup)} disabled={busy !== null} className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                              {busy === `verify-${backup.id}` ? 'Checking…' : 'Check'}
                            </button>
                            <button onClick={() => rehearse(backup)} disabled={busy !== null} className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                              {busy === `rehearse-${backup.id}` ? 'Queuing…' : 'Rehearse'}
                            </button>
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      )}

      {jobs && (
        <section className="mt-8">
          <h2 className="font-medium">Restores and rehearsals</h2>
          {jobs.length === 0 ? (
            <div className="mt-3"><EmptyState title="Nothing yet" description="Rehearsals and restore requests appear here." /></div>
          ) : (
            <ul className="mt-3 space-y-3">
              {jobs.map((job) => (
                <li key={job.id} className="rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] p-5 text-sm">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{job.mode === 'rehearsal' ? 'Rehearsal' : 'Production restore'}</span>
                    <Badge tone={restoreTone(job.status)}>{statusLabel(job.status)}</Badge>
                    <span className="text-slate-500">{formatDateTime(job.created_at)}</span>
                    {job.duration_ms !== null && <span className="text-slate-500">· {formatDurationMs(job.duration_ms)}</span>}
                    {job.reference && <span className="text-slate-500">· {job.reference}</span>}
                    {job.mode === 'production' && job.status === 'requested' && (
                      <button onClick={() => setRestoring(job)} className="ml-auto inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-1 text-xs font-semibold text-red-700 shadow-sm transition-colors hover:bg-red-50">
                        Authorize restore…
                      </button>
                    )}
                  </div>
                  <div className="mt-1 font-mono text-xs text-slate-400">backup {job.backup_id}</div>
                  {job.failure_reason && <p className="mt-2 text-red-700">{job.failure_reason}</p>}
                  {job.report?.checks && (
                    <ul className="mt-2 space-y-1">
                      {job.report.checks.map((check) => (
                        <li key={check.name} className={check.passed ? 'text-slate-600' : 'text-red-700'}>
                          <span className="font-medium">{check.passed ? 'Passed' : 'Failed'}:</span> {check.detail}
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}

      {restoring && (
        <AuthorizeRestore
          job={restoring}
          onClose={() => setRestoring(null)}
          onDone={(text) => {
            setRestoring(null);
            setNotice({ tone: 'good', text });
            load();
          }}
        />
      )}
    </AuthenticatedLayout>
  );
}

/**
 * The one destructive action on this page. It asks for a reference, the
 * backup id typed back, and the password again (step-up), and says what
 * will happen before anything does.
 */
function AuthorizeRestore({ job, onClose, onDone }: { job: RestoreJob; onClose: () => void; onDone: (text: string) => void }) {
  const [reference, setReference] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      // Step-up first (Module 30 §6): the restore route refuses without it.
      await adminFetch('/auth/step-up', { method: 'POST', body: code ? { password, code } : { password } });
      await adminFetch(`/super-admin/restore-jobs/${job.id}/authorize`, { method: 'POST', body: { confirmation, reference } });
      onDone('The restore was authorized and has started. A safety backup was taken first.');
    } catch (failure) {
      const fields = failure instanceof AdminApiError && failure.status === 422 ? (failure.body.errors as Record<string, string[]> | undefined) : undefined;
      setError(fields ? Object.values(fields)[0][0] : adminErrorMessage(failure));
      setBusy(false);
    }
  }

  const field = 'mt-1 block min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus-visible:border-brand-500 focus-visible:ring-2 focus-visible:ring-brand-600';

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/40 p-4 backdrop-blur-[2px] motion-safe:animate-fade-in" role="dialog" aria-modal="true" aria-labelledby="restore-title">
      <form onSubmit={submit} className="mt-16 w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 shadow-2xl ring-1 ring-slate-900/5 motion-safe:animate-scale-in">
        <h2 id="restore-title" className="text-lg font-semibold text-red-700">Restore the live database</h2>
        <p className="text-sm text-slate-700">
          This replaces <strong>all live data of every store</strong> with backup <span className="font-mono">{job.backup_id}</span>.
          Everything changed since that backup is lost. A safety backup of the current data is taken first. There is no automatic rollback.
        </p>
        <p className="text-sm text-slate-700">Put the platform into maintenance and stop the queue workers before you continue (runbook: “Production restore”).</p>

        <div>
          <label htmlFor="reference" className="block text-sm font-medium text-slate-700">Incident or change reference</label>
          <input id="reference" value={reference} onChange={(e) => setReference(e.target.value)} className={field} />
        </div>
        <div>
          <label htmlFor="confirmation" className="block text-sm font-medium text-slate-700">Type the backup id to confirm</label>
          <input id="confirmation" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} className={`${field} font-mono`} autoComplete="off" />
        </div>
        <div>
          <label htmlFor="restore-password" className="block text-sm font-medium text-slate-700">Your password</label>
          <input id="restore-password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} className={field} autoComplete="current-password" />
        </div>
        <div>
          <label htmlFor="restore-code" className="block text-sm font-medium text-slate-700">Two-step code</label>
          <input id="restore-code" value={code} onChange={(e) => setCode(e.target.value)} className={field} inputMode="numeric" autoComplete="one-time-code" />
        </div>

        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}

        <div className="flex justify-end gap-3">
          <button type="button" onClick={onClose} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 shadow-sm transition hover:-translate-y-px hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-50 motion-reduce:transform-none focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2">Keep live data</button>
          <button disabled={busy || confirmation !== job.backup_id || reference.trim().length < 5 || password === ''} className="inline-flex min-h-10 items-center justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 disabled:pointer-events-none disabled:opacity-50">
            {busy ? 'Starting…' : 'Restore live database'}
          </button>
        </div>
      </form>
    </div>
  );
}
