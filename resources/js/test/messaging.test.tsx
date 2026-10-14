import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import CommunicationIndex from '@/Pages/Communication/Index';
import SuperAdminMessaging from '@/Pages/SuperAdmin/Messaging';
import { clearToasts } from '@/Components/ui/toast';
import type { MessagingProvider } from '@/lib/messaging';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B50 (gap G10; Module 21): WhatsApp & SMS accounts, order updates, WhatsApp templates, and the Super Admin page. */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
  window.history.replaceState(null, '', '/');
});

const caps = (whatsapp: boolean, extra: Partial<MessagingProvider['capabilities']> = {}): MessagingProvider['capabilities'] => ({
  templates: whatsapp, free_text: !whatsapp, delivery_reports: true, read_receipts: whatsapp, inbound: true, template_check: whatsapp, verified_api: true, needs_platform_go_ahead: false, note: null, ...extra,
});
const account = (extra: Partial<NonNullable<MessagingProvider['account']>> = {}): NonNullable<MessagingProvider['account']> => ({
  id: 'a1', environment: 'production', is_enabled: false, credential_hints: {}, last_success_at: null, last_failure_at: null, last_failure_message: null, webhook_url: null, verify_token: null, ...extra,
});
const meta = (acc: MessagingProvider['account'] = null): MessagingProvider => ({
  provider: 'meta_whatsapp', label: 'WhatsApp (Meta Cloud API)', channel: 'whatsapp', capabilities: caps(true), live_allowed: true, account: acc,
  credential_fields: [{ key: 'phone_number_id', label: 'Phone number ID', secret: false, max_length: 32, help: null }, { key: 'access_token', label: 'Access token', secret: true, max_length: 1000, help: null }],
});
const twilio = (): MessagingProvider => ({
  provider: 'twilio', label: 'Twilio SMS', channel: 'sms', capabilities: caps(false, { needs_platform_go_ahead: true, note: 'Real SMS open once Umar Techy has checked sender ID.' }), live_allowed: false, account: null,
  credential_fields: [{ key: 'account_sid', label: 'Account SID', secret: false, max_length: 34, help: null }],
});
const events = [
  { key: 'order_confirmed', label: 'Order confirmed', variables: ['customer_name', 'store_name', 'order_number', 'order_total'], text: 'Hi {{customer_name}}, thank you for your order {{order_number}}.', whatsapp_body: 'Hi {{1}}, thank you for your order {{3}} at {{2}}. Total: {{4}}.', template: null },
  { key: 'order_shipped', label: 'Order shipped', variables: ['customer_name', 'order_number', 'courier', 'tracking_number'], text: 'Hi {{customer_name}}, your order is on its way.', whatsapp_body: 'Hi {{1}}, your order {{2}} is on its way with {{3}}. Tracking number: {{4}}.', template: { name: 'order_shipped_v1', language: 'en', status: 'approved', checked_at: null } },
];
const settings = (whatsapp: MessagingProvider, extra: Record<string, unknown> = {}) => ({ data: {
  can_manage: true, can_configure_providers: true,
  channels: { whatsapp: { providers: [whatsapp], connected: whatsapp.account ? whatsapp.provider : null, sent_today: 3 }, sms: { providers: [twilio()], connected: null, sent_today: 0 } },
  events, sms_events: [], whatsapp_events: ['order_shipped'], daily_limit: 500, ...extra,
} });

describe('Messages → WhatsApp & SMS', () => {
  beforeEach(() => {
    setPage(owner, '/notifications?tab=channels');
    window.history.replaceState(null, '', '/notifications?tab=channels');
  });

  it('shows a connected account plainly: its state, the webhook details to copy, and a test message', async () => {
    let test: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/messaging-settings': () => json(200, settings(meta(account({ webhook_url: 'https://shop.example/api/v1/messaging-webhooks/tok', verify_token: 'tok' })))),
      'POST /messaging-settings/channels/whatsapp/test': (_url, init) => {
        test = JSON.parse(String(init.body));

        return json(200, { data: { outcome: 'accepted', message: null, provider_message_id: 'wamid.1' } });
      },
    }));
    render(<CommunicationIndex />);

    expect(await screen.findByText('Connected, off')).toBeTruthy();
    expect(screen.getByText(/Production · 3 sent today/)).toBeTruthy();
    expect(screen.getByText('Nothing is sent until you turn WhatsApp on.')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Turn WhatsApp on' })).toBeTruthy();
    expect(screen.getByText('Callback URL')).toBeTruthy();
    expect(screen.getByText('Verify token')).toBeTruthy();
    // SMS: Twilio is offered, but marked as sandbox-only until Umar Techy opens it.
    expect(screen.getByText('Sandbox only for now')).toBeTruthy();

    fireEvent.change(screen.getByLabelText(/^Send a test message to/), { target: { value: '0300 1234567' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send test' }));
    expect(await screen.findByText('WhatsApp (Meta Cloud API) accepted the message.')).toBeTruthy();
    expect(test).toEqual({ number: '0300 1234567' });
  });

  it('chooses which updates go by SMS and WhatsApp, warns about a channel that is off, and saves the limit', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/messaging-settings': () => json(200, settings(meta())),
      'PUT /messaging-settings': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: {} });
      },
    }));
    render(<CommunicationIndex />);

    fireEvent.click(await screen.findByRole('checkbox', { name: 'Order confirmed by SMS' }));
    expect(screen.getByText(/SMS and WhatsApp are not switched on above/)).toBeTruthy();
    fireEvent.change(screen.getByLabelText(/^Most messages a day/), { target: { value: '12a0' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save updates' }));
    await waitFor(() => expect(sent).toEqual({ sms_events: ['order_confirmed'], whatsapp_events: ['order_shipped'], daily_limit: 120 }));
  });

  it('lists WhatsApp templates and shows the exact text and variables to submit to Meta', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/messaging-settings': () => json(200, settings(meta(account({ is_enabled: true })))),
      'PUT /messaging-settings/templates/order_confirmed': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: { name: 'order_confirmed', language: 'en', status: 'submitted', checked_at: null } });
      },
    }));
    render(<CommunicationIndex />);

    expect(await screen.findByText('Approved')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Set template for Order confirmed' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Hi {{1}}, thank you for your order {{3}} at {{2}}. Total: {{4}}.')).toBeTruthy();
    expect(within(dialog).getByText('order number')).toBeTruthy();
    fireEvent.change(within(dialog).getByLabelText(/^Template name/), { target: { value: ' order_confirmed ' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save template' }));
    await waitFor(() => expect(sent).toEqual({ template_name: 'order_confirmed', language_code: 'en' }));
  });

  it('lets a manager choose updates but not connect accounts', async () => {
    vi.stubGlobal('fetch', routeFetch({ '/messaging-settings': () => json(200, settings(meta(), { can_configure_providers: false })) }));
    render(<CommunicationIndex />);

    expect(await screen.findAllByText(/Ask the store owner or an administrator/)).toHaveLength(2);
    expect(screen.queryByRole('button', { name: /^Connect/ })).toBeNull();
    expect(screen.queryByLabelText(/^Send a test message to/)).toBeNull();
    expect((screen.getByRole('checkbox', { name: 'Order confirmed by SMS' }) as HTMLInputElement).disabled).toBe(false);
  });
});

describe('Super Admin — WhatsApp & SMS', () => {
  beforeEach(() => setPage({ ...owner, user: { ...owner.user!, is_platform_staff: true } }, '/super-admin/messaging'));

  it('shows what critical WhatsApp alerts still need, and opens an SMS provider only with a reason', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      '/super-admin/messaging': () => json(200, { data: {
        channels: { whatsapp: { providers: [meta(account({ is_enabled: true }))], connected: 'meta_whatsapp' }, sms: { providers: [twilio()], connected: null } },
        gated: [{ provider: 'twilio', label: 'Twilio SMS', live_allowed: false }],
        alert_template: { variables: ['summary', 'reference'], whatsapp_body: 'Critical alert: {{1}} Reference: {{2}}', template: null },
        alerts: { whatsapp_enabled: true, whatsapp_recipients: 2 },
        last_24h: { whatsapp: { sent: 4, delivered: 6, failed: 1 } },
        stores_connected: { meta_whatsapp: 5 },
      } }),
      'PUT /super-admin/messaging/providers/twilio/live': (_url, init) => {
        sent = JSON.parse(String(init.body));

        return json(200, { data: {} });
      },
    }));
    render(<SuperAdminMessaging />);

    expect(await screen.findByText('Not ready')).toBeTruthy();
    expect(screen.getByText('WhatsApp alerts switched on for 2 numbers')).toBeTruthy();
    expect(screen.getByText('An approved alert template').parentElement?.textContent).toContain('to do');
    expect(screen.getByText('WhatsApp sent, 24 h').parentElement?.textContent).toContain('10');

    fireEvent.click(screen.getByRole('button', { name: 'Open Twilio SMS for real messages' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Sender ID checked on Jazz and Zong' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Open for real messages' }));
    await waitFor(() => expect(sent).toEqual({ allowed: true, reason: 'Sender ID checked on Jazz and Zong' }));
  });
});
