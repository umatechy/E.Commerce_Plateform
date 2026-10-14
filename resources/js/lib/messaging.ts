import type { CredentialFieldDef } from '@/lib/payments';

/**
 * Phase B50 (gap G10): SMS and WhatsApp in the admin. Shapes mirror
 * /api/v1/messaging-settings and /api/v1/super-admin/messaging; the server
 * decides every state, these only present it.
 */
export type MessagingChannel = 'whatsapp' | 'sms';

export const CHANNEL_LABEL: Record<MessagingChannel, string> = { whatsapp: 'WhatsApp', sms: 'SMS' };

export type MessagingProvider = {
  provider: string;
  label: string;
  channel: MessagingChannel;
  capabilities: {
    templates: boolean;
    free_text: boolean;
    delivery_reports: boolean;
    read_receipts: boolean;
    inbound: boolean;
    template_check: boolean;
    verified_api: boolean;
    needs_platform_go_ahead: boolean;
    note: string | null;
  };
  credential_fields: CredentialFieldDef[];
  live_allowed: boolean;
  account: {
    id: string;
    environment: 'sandbox' | 'production';
    is_enabled: boolean;
    credential_hints: Record<string, string>;
    last_success_at: string | null;
    last_failure_at: string | null;
    last_failure_message: string | null;
    webhook_url: string | null;
    verify_token: string | null;
  } | null;
};

export type ChannelInfo = { providers: MessagingProvider[]; connected: string | null; sent_today?: number };

export type TemplateMapping = { name: string; language: string; status: 'submitted' | 'approved' | 'rejected' | 'paused' | 'unknown'; checked_at: string | null };

export type MessagingEvent = { key: string; label: string; variables: string[]; text: string; whatsapp_body: string; template: TemplateMapping | null };

export const TEMPLATE_STATUS: Record<TemplateMapping['status'], { label: string; tone: 'green' | 'amber' | 'red' | 'neutral' }> = {
  approved: { label: 'Approved', tone: 'green' },
  submitted: { label: 'Waiting for approval', tone: 'amber' },
  paused: { label: 'Paused by Meta', tone: 'red' },
  rejected: { label: 'Rejected', tone: 'red' },
  unknown: { label: 'Not checked', tone: 'neutral' },
};
