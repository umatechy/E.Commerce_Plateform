import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import SuperAdminSecurity from '@/Pages/SuperAdmin/Security';
import ForgotPassword from '@/Pages/Auth/ForgotPassword';
import ResetPassword from '@/Pages/Auth/ResetPassword';
import { SessionsCard } from '@/Components/Security/AccountSecurity';
import { clearToasts } from '@/Components/ui/toast';
import type { Overview } from '@/lib/security';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B52: Super Admin → Security, the account's sessions, and staff password recovery. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
  window.history.replaceState(null, '', '/');
});

const overview = (extra: Partial<Overview> = {}): Overview => ({
  failed_logins_24h: 12, failed_privileged_logins_24h: 6, lockouts_24h: 1, alerts_7d: 2,
  mfa: { staff: 4, staff_with_mfa: 3, owners: 10, owners_with_mfa: 10 }, privileged_sessions: 2, open_incidents: { sev2: 1 },
  backups: { last_verified_at: '2026-10-14T01:00:00Z', last_rehearsal_at: null, last_scheduled_status: 'verified' },
  recent_alerts: [{ id: 9, type: 'alert', severity: 'high', at: '2026-10-14T10:00:00Z', user: null, subject: 'x', store_id: null, ip_address: '203.0.113.5', alerted: true, request_id: null, summary: 'Repeated failed sign-ins on a platform staff account', details: '5+ failed sign-ins in 15 minutes', action: 'Deactivate the account if it was not them.' }],
  controls: [
    { key: 'platform.maintenance_mode', label: 'Maintenance mode', value: false, normal: false, emergency: false, impact: 'Every storefront shows a maintenance page.', emergency_value: true, changed_at: null, changed_reason: null, changed_by: null },
    { key: 'platform.developer_api_enabled', label: 'Developer API', value: false, normal: true, emergency: true, impact: 'Every API key stops working.', emergency_value: false, changed_at: '2026-10-14T09:00:00Z', changed_reason: 'INC-2026-0001', changed_by: 'sec@umartechy.test' },
  ],
  roles: [{ key: 'super_admin', label: 'Super admin', description: 'Everything.', capabilities: ['view', 'security'] }, { key: 'support_admin', label: 'Support admin', description: 'Answers stores.', capabilities: ['view', 'support'] }],
  capabilities: { view: 'See', security: 'Security', support: 'Support' },
  severities: [{ key: 'sev1', label: 'SEV1', description: 'Major: a core service is down.' }, { key: 'sev2', label: 'SEV2', description: 'Significant but limited.' }],
  session_limits: { idle_minutes: 30, absolute_hours: 12 },
  ...extra,
});

const asStaff = (capabilities: string[]) => setPage({ ...owner, user: { ...owner.user!, is_platform_staff: true }, platform_capabilities: capabilities }, '/super-admin/security');

describe('Super Admin → Security', () => {
  it('shows the overview, the active emergency control and the alerts with what to do', async () => {
    asStaff(['view']);
    vi.stubGlobal('fetch', routeFetch({ '/super-admin/security': () => json(200, { data: overview() }) }));
    render(<SuperAdminSecurity />);

    expect(await screen.findByText(/Emergency control active:/)).toBeTruthy();
    expect(screen.getByText('6 on platform staff accounts')).toBeTruthy();
    expect(screen.getByText('3 of 4 (75%)')).toBeTruthy();
    expect(screen.getByText('Never')).toBeTruthy();
    expect(screen.getByText(/What to do: Deactivate the account/)).toBeTruthy();
  });

  it('switches an emergency control only with a reason, and hides the switch from roles without security rights', async () => {
    asStaff(['view', 'security']);
    window.history.replaceState(null, '', '/super-admin/security?tab=controls');
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/security': () => json(200, { data: overview() }),
      'PUT /super-admin/security/emergency/platform.maintenance_mode': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: overview().controls });
      },
    }));
    render(<SuperAdminSecurity />);

    fireEvent.click(await screen.findByRole('button', { name: 'Turn on Maintenance mode' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Every storefront shows a maintenance page.')).toBeTruthy();
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'INC-2026-0002 payment outage' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Turn on' }));
    await waitFor(() => expect(sent).toEqual({ value: true, reason: 'INC-2026-0002 payment outage' }));
    expect(screen.getByRole('button', { name: 'Back to normal Developer API' })).toBeTruthy();

    cleanup();
    asStaff(['view']);
    render(<SuperAdminSecurity />);
    await screen.findByText('Developer API');
    expect(screen.queryByRole('button', { name: /Back to normal/ })).toBeNull();
  });

  it('opens an incident with a severity and a component', async () => {
    asStaff(['view', 'security']);
    window.history.replaceState(null, '', '/super-admin/security?tab=incidents');
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/security': () => json(200, { data: overview() }),
      '/super-admin/security/incidents': () => json(200, { data: [] }),
      'POST /super-admin/security/incidents': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(201, { data: { id: 'I1', reference: 'INC-2026-0001', title: 't', severity: 'sev1', status: 'detected', component: 'Payments', scope: 'platform', affected_store_ids: [], impact: null, owner: null, detected_at: '2026-10-14T10:00:00Z', resolved_at: null, closed_at: null, resolution: null, postmortem_reference: null, timeline: [] } });
      },
      '/super-admin/security/incidents/I1': () => json(200, { data: { id: 'I1', reference: 'INC-2026-0001', title: 'Card payments failing', severity: 'sev1', status: 'detected', component: 'Payments', scope: 'platform', affected_store_ids: [], impact: null, owner: null, detected_at: '2026-10-14T10:00:00Z', resolved_at: null, closed_at: null, resolution: null, postmortem_reference: null, timeline: [{ kind: 'opened', from: null, to: 'detected', note: null, author: 'GM Awan', at: '2026-10-14T10:00:00Z' }] } }),
    }));
    render(<SuperAdminSecurity />);

    fireEvent.click(await screen.findByRole('button', { name: 'Open an incident' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText(/^What is happening/), { target: { value: 'Card payments failing' } });
    fireEvent.change(within(dialog).getByLabelText(/^Severity/), { target: { value: 'sev1' } });
    fireEvent.change(within(dialog).getByLabelText(/^Component/), { target: { value: 'Payments' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Open incident' }));
    await waitFor(() => expect(sent).toMatchObject({ title: 'Card payments failing', severity: 'sev1', component: 'Payments', scope: 'platform' }));
    expect(await screen.findByText('Opened')).toBeTruthy();
  });
});

describe('Account security', () => {
  beforeEach(() => setPage(owner, '/security'));

  it('lists the places the account is signed in and signs the others out', async () => {
    let called = false;
    vi.stubGlobal('fetch', routeFetch({
      '/auth/sessions': () => json(200, { data: [
        { id: 'S1', current: true, device: 'Chrome on Windows', ip_address: '203.0.113.1', with_mfa: true, privileged: false, signed_in_at: '2026-10-14T08:00:00Z', last_seen_at: '2026-10-14T10:00:00Z' },
        { id: 'S2', current: false, device: 'Safari on iOS', ip_address: '198.51.100.7', with_mfa: true, privileged: false, signed_in_at: '2026-10-13T08:00:00Z', last_seen_at: '2026-10-13T09:00:00Z' },
      ] }),
      'POST /auth/sessions/revoke-others': () => {
        called = true;

        return json(200, { data: [], ended: 1 });
      },
    }));
    render(<SessionsCard />);

    expect(await screen.findByText('This device')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Sign out Safari on iOS' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Sign out Chrome on Windows' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Sign out all other devices' }));
    await waitFor(() => expect(called).toBe(true));
  });
});

describe('Staff password recovery', () => {
  it('gives the same answer whatever the email, and sends the token from the link fragment', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      'POST /auth/password/forgot': () => json(202, { message: 'ok' }),
      'POST /auth/password/reset': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { message: 'ok' });
      },
    }));
    render(<ForgotPassword />);
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'nobody@example.com' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send the link' }));
    expect(await screen.findByText(/If an account exists for/)).toBeTruthy();
    cleanup();

    const token = 'a'.repeat(64);
    window.history.replaceState(null, '', `/reset-password#token=${token}&email=owner%40example.com`);
    render(<ResetPassword />);
    fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'brand-new-pass' } });
    fireEvent.change(screen.getByLabelText('New password again'), { target: { value: 'brand-new-pass' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save new password' }));
    expect(await screen.findByText(/signed out everywhere/)).toBeTruthy();
    expect(sent).toEqual({ token, email: 'owner@example.com', password: 'brand-new-pass', password_confirmation: 'brand-new-pass' });
    expect(window.location.hash).toBe('');
  });

  it('says plainly when the link is incomplete', () => {
    window.history.replaceState(null, '', '/reset-password');
    render(<ResetPassword />);
    expect(screen.getByText(/This link is not complete/)).toBeTruthy();
  });
});
