/**
 * Module 23 (Phase B30) — shapes of the Super Admin backup API and the
 * words the Backups page shows for them. The server decides every state;
 * these helpers only describe it.
 */
export type Backup = {
  id: string;
  scope: 'platform' | 'store';
  status: string;
  initiated_by: 'manual' | 'scheduled' | 'pre_restore_safety';
  retention_tier: 'daily' | 'monthly' | 'manual' | 'pre_change';
  size_bytes: number | null;
  is_encrypted: boolean;
  duration_ms: number | null;
  verified_at: string | null;
  last_checked_at: string | null;
  expires_at: string | null;
  failure_reason: string | null;
  created_at: string;
};

export type RehearsalCheck = { name: string; passed: boolean; detail: string };

export type RestoreJob = {
  id: number;
  backup_id: string;
  mode: 'production' | 'rehearsal';
  status: 'requested' | 'preflight_failed' | 'running' | 'failed' | 'verified' | 'completed';
  reference: string | null;
  failure_reason: string | null;
  report: { checks?: RehearsalCheck[]; import_ms?: number; within_rto_target?: boolean | null } | null;
  duration_ms: number | null;
  created_at: string;
};

export type BackupSummary = {
  rpo_target_hours: number;
  rto_target_hours: number;
  last_verified_backup_id: string | null;
  last_verified_at: string | null;
  hours_since_last_verified: number | null;
  overdue: boolean;
  last_backup_size_bytes: number | null;
  last_backup_encrypted: boolean | null;
  consecutive_scheduled_failures: number;
  failed_backups_last_30_days: number;
  last_failure_reason: string | null;
  last_rehearsal_at: string | null;
  last_rehearsal_status: 'completed' | 'failed' | null;
  last_successful_rehearsal_at: string | null;
  last_successful_rehearsal_duration_ms: number | null;
  next_scheduled_backup_at: string;
};

export function formatBytes(bytes: number | null): string {
  if (bytes === null) return '—';
  if (bytes < 1024) return `${bytes} B`;
  const units = ['KB', 'MB', 'GB', 'TB'];
  let value = bytes / 1024;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }

  return `${value >= 100 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
}

export function formatDurationMs(ms: number | null): string {
  if (ms === null) return '—';
  if (ms < 1000) return `${ms} ms`;
  const seconds = ms / 1000;
  if (seconds < 90) return `${seconds.toFixed(1)} s`;
  const minutes = seconds / 60;

  return minutes < 90 ? `${Math.round(minutes)} min` : `${(minutes / 60).toFixed(1)} h`;
}

const TRIGGERS: Record<Backup['initiated_by'], string> = {
  manual: 'Manual',
  scheduled: 'Scheduled',
  pre_restore_safety: 'Before a restore',
};

export function triggerLabel(backup: Pick<Backup, 'initiated_by' | 'retention_tier'>): string {
  return backup.initiated_by === 'scheduled' ? `Scheduled (${backup.retention_tier})` : TRIGGERS[backup.initiated_by];
}

export type Tone = 'good' | 'bad' | 'neutral' | 'busy';

export function backupTone(status: string): Tone {
  if (status === 'verified') return 'good';
  if (status === 'failed' || status === 'restore_failed') return 'bad';
  if (['created', 'queued', 'running', 'verifying', 'restoring'].includes(status)) return 'busy';

  return 'neutral';
}

export function restoreTone(status: RestoreJob['status']): Tone {
  if (status === 'completed' || status === 'verified') return 'good';
  if (status === 'failed' || status === 'preflight_failed') return 'bad';

  return status === 'running' ? 'busy' : 'neutral';
}

export function statusLabel(status: string): string {
  return status.replace(/_/g, ' ');
}

/** What the page says about the newest backup against the recovery point target. A target, never "met". */
export function recoveryPointText(summary: Pick<BackupSummary, 'hours_since_last_verified' | 'rpo_target_hours' | 'overdue'>): string {
  if (summary.hours_since_last_verified === null) {
    return summary.overdue ? 'No verified backup exists.' : 'No backup yet.';
  }

  const age = summary.hours_since_last_verified < 1 ? 'less than an hour' : `${summary.hours_since_last_verified} hours`;

  return `Newest verified backup is ${age} old. Target: at most ${summary.rpo_target_hours} hours.`;
}
