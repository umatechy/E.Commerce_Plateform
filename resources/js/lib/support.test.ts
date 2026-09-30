import { describe, expect, it } from 'vitest';
import { formatDuration, slaState, statusLabel, supportError, timeAgo } from './support';

const now = new Date('2026-09-30T12:00:00Z');
const at = (minutes: number) => new Date(now.getTime() + minutes * 60000).toISOString();
const sla = (overrides: Partial<{ first_response_due_at: string | null; first_responded_at: string | null; resolution_due_at: string | null; breached: boolean }> = {}) => ({
  first_response_due_at: at(240),
  first_responded_at: null,
  resolution_due_at: at(1440),
  breached: false,
  ...overrides,
});

describe('slaState', () => {
  it('counts down to the first reply until one is sent', () => {
    expect(slaState({ status: 'open', sla: sla() }, now)).toEqual({ state: 'on_track', label: 'Reply due in 4h' });
  });

  it('warns in the last hour and shows overdue at once, before the hourly job flags it', () => {
    expect(slaState({ status: 'open', sla: sla({ first_response_due_at: at(45) }) }, now)).toEqual({ state: 'due_soon', label: 'Reply due in 45m' });
    expect(slaState({ status: 'open', sla: sla({ first_response_due_at: at(-20) }) }, now)).toEqual({ state: 'breached', label: 'Reply overdue by 20m' });
  });

  it('moves on to the resolution target once answered, and keeps a flagged breach', () => {
    expect(slaState({ status: 'awaiting_customer', sla: sla({ first_responded_at: at(-60) }) }, now)).toEqual({ state: 'on_track', label: 'Resolve due in 1d' });
    expect(slaState({ status: 'on_hold', sla: sla({ first_responded_at: at(-60), breached: true, resolution_due_at: at(-120) }) }, now).state).toBe('breached');
  });

  it('says nothing about resolved or closed tickets', () => {
    expect(slaState({ status: 'resolved', sla: sla({ breached: true }) }, now).state).toBe('none');
    expect(slaState({ status: 'closed', sla: sla() }, now).state).toBe('none');
  });
});

describe('formatDuration / timeAgo', () => {
  it('uses the largest sensible units', () => {
    expect(formatDuration(45 * 60000)).toBe('45m');
    expect(formatDuration(150 * 60000)).toBe('2h 30m');
    expect(formatDuration(13 * 3600000)).toBe('13h');
    expect(formatDuration(51 * 3600000)).toBe('2d 3h');
  });

  it('reads naturally for recent activity', () => {
    expect(timeAgo(at(-0.5), now)).toBe('just now');
    expect(timeAgo(at(-90), now)).toBe('1h 30m ago');
  });
});

describe('statusLabel', () => {
  it('tells each side whose turn it is', () => {
    expect(statusLabel('awaiting_customer')).toBe('Waiting for your reply');
    expect(statusLabel('awaiting_customer', 'agent')).toBe('Awaiting customer');
    expect(statusLabel('open')).toBe('Waiting for the team');
  });
});

describe('supportError', () => {
  it('prefers the first field error, then the message', () => {
    expect(supportError({ message: 'Invalid', errors: { subject: ['Too many open requests.'] } })).toBe('Too many open requests.');
    expect(supportError({ message: 'This ticket is closed.' })).toBe('This ticket is closed.');
    expect(supportError(undefined, 'Oops')).toBe('Oops');
  });
});
