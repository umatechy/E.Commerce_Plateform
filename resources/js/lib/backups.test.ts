import { describe, expect, it } from 'vitest';
import { backupTone, formatBytes, formatDurationMs, recoveryPointText, restoreTone, triggerLabel } from './backups';

describe('backups', () => {
  it('formats sizes', () => {
    expect(formatBytes(null)).toBe('—');
    expect(formatBytes(512)).toBe('512 B');
    expect(formatBytes(38865)).toBe('38.0 KB');
    expect(formatBytes(5 * 1024 * 1024 * 1024)).toBe('5.0 GB');
  });

  it('formats durations', () => {
    expect(formatDurationMs(null)).toBe('—');
    expect(formatDurationMs(840)).toBe('840 ms');
    expect(formatDurationMs(9340)).toBe('9.3 s');
    expect(formatDurationMs(25 * 60 * 1000)).toBe('25 min');
    expect(formatDurationMs(3 * 3600 * 1000)).toBe('3.0 h');
  });

  it('names what started a backup', () => {
    expect(triggerLabel({ initiated_by: 'scheduled', retention_tier: 'monthly' })).toBe('Scheduled (monthly)');
    expect(triggerLabel({ initiated_by: 'pre_restore_safety', retention_tier: 'pre_change' })).toBe('Before a restore');
    expect(triggerLabel({ initiated_by: 'manual', retention_tier: 'manual' })).toBe('Manual');
  });

  it('never shows a failed backup or restore as good', () => {
    expect(backupTone('verified')).toBe('good');
    expect(backupTone('failed')).toBe('bad');
    expect(backupTone('running')).toBe('busy');
    expect(backupTone('deleted')).toBe('neutral');
    expect(restoreTone('completed')).toBe('good');
    expect(restoreTone('preflight_failed')).toBe('bad');
    expect(restoreTone('failed')).toBe('bad');
    expect(restoreTone('running')).toBe('busy');
    expect(restoreTone('requested')).toBe('neutral');
  });

  it('describes the recovery point as an age against a target', () => {
    expect(recoveryPointText({ hours_since_last_verified: 7.5, rpo_target_hours: 24, overdue: false })).toBe(
      'Newest verified backup is 7.5 hours old. Target: at most 24 hours.',
    );
    expect(recoveryPointText({ hours_since_last_verified: 0.2, rpo_target_hours: 24, overdue: false })).toContain('less than an hour');
    expect(recoveryPointText({ hours_since_last_verified: null, rpo_target_hours: 24, overdue: true })).toBe('No verified backup exists.');
    expect(recoveryPointText({ hours_since_last_verified: null, rpo_target_hours: 24, overdue: false })).toBe('No backup yet.');
  });
});
