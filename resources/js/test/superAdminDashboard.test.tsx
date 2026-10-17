import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import Dashboard from '@/Pages/SuperAdmin/Dashboard';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-5: the platform overview says what needs attention and shows mixed-currency sums as plain grouped numbers. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const staff = { ...owner, user: { ...owner.user!, is_platform_staff: true }, activeStore: null };
const summary = (failed: number) => ({
  data: {
    stores: { total: 77, active_subscriptions: 1, trial_subscriptions: 76, suspended_subscriptions: 0 },
    orders: { total_last_30_days: 63, revenue_minor_last_30_days: 13847647 },
    payments: { collected_amount_minor_last_30_days: 5497750, failed_last_30_days: failed },
  },
});
const backups = (overdue: boolean) => ({ data: { overdue, consecutive_scheduled_failures: 0, failed_backups_last_30_days: 0, last_rehearsal_status: 'completed', newest_verified_backup_at: null, recovery_point_hours: 2, target_hours: 24 } });

describe('platform overview', () => {
  it('lists what needs attention and groups the digits of sums', async () => {
    setPage(staff, '/super-admin');
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/dashboard': () => json(200, summary(2)),
      '/super-admin/infrastructure/health': () => json(200, { data: { status: 'degraded', checks: { database: { status: 'ok' }, cache: { status: 'failed', detail: 'timeout' } } } }),
      '/super-admin/backups/summary': () => json(200, backups(true)),
    }));
    render(<Dashboard />);

    expect(await screen.findByRole('heading', { name: 'Needs attention' })).toBeTruthy();
    expect(screen.getByText('The newest verified backup is older than the target.')).toBeTruthy();
    expect(screen.getByText('Infrastructure check failing: Cache.')).toBeTruthy();
    expect(screen.getByText('2 failed payments in the last 30 days.')).toBeTruthy();
    expect(screen.getByText('138,476.47')).toBeTruthy();
  });

  it('says all is clear when nothing needs attention', async () => {
    setPage(staff, '/super-admin');
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/dashboard': () => json(200, summary(0)),
      '/super-admin/infrastructure/health': () => json(200, { data: { status: 'ok', checks: { database: { status: 'ok' } } } }),
      '/super-admin/backups/summary': () => json(200, backups(false)),
    }));
    render(<Dashboard />);

    expect(await screen.findByText(/All clear/)).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Needs attention' })).toBeNull();
  });
});
