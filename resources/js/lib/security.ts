import type { Tone } from '@/Components/ui/Badge';

/**
 * Phase B52 (Module 32 §58–64, §85; Module 30 §3, §58–61, §87–88): Super
 * Admin → Security. Shapes mirror /api/v1/super-admin/security*; the
 * server decides every state, these only present them.
 */
export type SecurityEventRow = {
  id: number; type: string; severity: string; at: string; user: { name: string; email: string } | null; subject: string | null;
  store_id: number | null; ip_address: string | null; alerted: boolean; request_id: string | null; summary: string | null; details: string | null; action: string | null;
};

export type Control = { key: string; label: string; value: boolean; normal: boolean; emergency: boolean; impact: string; emergency_value: boolean; changed_at: string | null; changed_reason: string | null; changed_by: string | null };

export type RoleInfo = { key: string; label: string; description: string; capabilities: string[] };

export type Overview = {
  failed_logins_24h: number; failed_privileged_logins_24h: number; lockouts_24h: number; alerts_7d: number;
  mfa: { staff: number; staff_with_mfa: number; owners: number; owners_with_mfa: number };
  privileged_sessions: number; open_incidents: Record<string, number>;
  backups: { last_verified_at: string | null; last_rehearsal_at: string | null; last_scheduled_status: string | null };
  recent_alerts: SecurityEventRow[]; controls: Control[]; roles: RoleInfo[]; capabilities: Record<string, string>;
  severities: { key: string; label: string; description: string }[];
  session_limits: { idle_minutes: number; absolute_hours: number };
};

export type StaffRow = { id: string; name: string; email: string; role: string; role_label: string; is_active: boolean; mfa: boolean; last_seen_at: string | null; open_sessions: number };

export type Incident = {
  id: string; reference: string; title: string; severity: string; status: string; component: string; scope: string; affected_store_ids: number[];
  impact: string | null; owner: { id: string; name: string; email: string } | null; detected_at: string; resolved_at: string | null; closed_at: string | null;
  resolution: string | null; postmortem_reference: string | null;
  timeline: { kind: string; from: string | null; to: string | null; note: string | null; author: string | null; at: string }[] | null;
};

export const SEVERITY_TONE: Record<string, Tone> = { info: 'neutral', low: 'neutral', medium: 'amber', high: 'red', critical: 'red' };

export const INCIDENT_TONE: Record<string, Tone> = { sev0: 'red', sev1: 'red', sev2: 'amber', sev3: 'blue', sev4: 'neutral' };

export const STATUS_LABEL: Record<string, string> = {
  detected: 'Detected', triaged: 'Triaged', investigating: 'Investigating', containing: 'Containing', mitigating: 'Mitigating', resolved: 'Resolved', closed: 'Closed',
};

export const EVENT_LABEL: Record<string, string> = {
  login_failed: 'Failed sign-in', login_locked: 'Sign-in slowed down', mfa_failed: 'Wrong two-step code', mfa_enabled: 'Two-step sign-in turned on',
  mfa_disabled: 'Two-step sign-in turned off', mfa_reset: 'Emergency two-step reset', step_up_failed: 'Wrong password on confirm',
  password_changed: 'Password changed', password_reset_requested: 'Password reset asked', password_reset: 'Password reset',
  sessions_revoked: 'Sessions ended', session_timed_out: 'Session timed out', platform_role_changed: 'Platform role changed',
  user_deactivated: 'Account deactivated', data_export: 'Data export', store_deletion_requested: 'Store deletion requested',
  restore_authorized: 'Restore authorised', emergency_control_changed: 'Emergency control changed', incident_opened: 'Incident opened', alert: 'Alert sent',
};

export function percent(part: number, whole: number): number {
  return whole === 0 ? 100 : Math.round((part / whole) * 100);
}
