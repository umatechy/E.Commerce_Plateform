import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError, TextAreaField } from '@/Components/ui/Form';
import Badge from '@/Components/ui/Badge';
import { Card, QueryState, StatCard } from '@/Components/ui/Page';
import MessagingChannels from '@/Components/Messaging/MessagingChannels';
import { TemplateDialog } from '@/Components/Messaging/StoreMessaging';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { formatDateTime } from '@/lib/datetime';
import { CHANNEL_LABEL, TEMPLATE_STATUS, type ChannelInfo, type MessagingChannel, type TemplateMapping } from '@/lib/messaging';

/**
 * Phase B50 — Module 30 §27 "Provider Management", Module 21 §31–34, §54;
 * owner decisions 3 and 4.
 *
 * - Umar Techy's own WhatsApp (and SMS) account, which critical alerts go
 *   out through, and the approved alert template.
 * - Which SMS providers may send real messages: Twilio stays sandbox-only
 *   until its sender ID, delivery and prices for Pakistan are checked;
 *   closing it switches every store's live account off at once.
 * - SMS and WhatsApp across all stores in the last 24 hours.
 *
 * No credential is ever shown. Changes ask for the password again.
 */
type Gated = { provider: string; label: string; live_allowed: boolean };
type Overview = {
  channels: Record<MessagingChannel, ChannelInfo>;
  gated: Gated[];
  alert_template: { variables: string[]; whatsapp_body: string; template: Omit<TemplateMapping, 'checked_at'> & { checked_at: string | null } | null };
  alerts: { whatsapp_enabled: boolean; whatsapp_recipients: number };
  last_24h: Partial<Record<MessagingChannel, Record<string, number>>>;
  stores_connected: Record<string, number>;
};

export default function Messaging() {
  const state = useApi<{ data: Overview }>('/super-admin/messaging');

  return (
    <AdminPage title="WhatsApp & SMS" description="Umar Techy’s own accounts for critical alerts, which SMS providers may send real messages, and how messages are doing across all stores.">
      <QueryState state={state} lines={8}>
        {({ data }) => (
          <div className="space-y-6">
            <Health data={data} />
            <AlertsCard data={data} onSaved={state.reload} />
            <MessagingChannels channels={data.channels} base="/super-admin/messaging" owner="platform" canConfigure onChanged={state.reload} />
            <GatedCard rows={data.gated} stores={data.stores_connected} onSaved={state.reload} />
          </div>
        )}
      </QueryState>
    </AdminPage>
  );
}

function Health({ data }: { data: Overview }) {
  const count = (channel: MessagingChannel, ...statuses: string[]) => statuses.reduce((sum, s) => sum + (data.last_24h[channel]?.[s] ?? 0), 0);
  const storesOn = Object.values(data.stores_connected).reduce((a, b) => a + b, 0);

  return (
    <section aria-label="Last 24 hours" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {(['whatsapp', 'sms'] as const).map((channel) => (
        <StatCard key={channel} label={`${CHANNEL_LABEL[channel]} sent, 24 h`} value={count(channel, 'sent', 'delivered')} hint={`${count(channel, 'delivered')} delivered`} />
      ))}
      <StatCard label="Failed, 24 h" value={count('whatsapp', 'failed') + count('sms', 'failed')} hint="Bad numbers, refusals and limits" />
      <StatCard label="Stores sending" value={storesOn} hint="Accounts switched on" />
    </section>
  );
}

function AlertsCard({ data, onSaved }: { data: Overview; onSaved: () => void }) {
  const [editing, setEditing] = useState(false);
  const template = data.alert_template.template;
  const status = template ? TEMPLATE_STATUS[template.status] ?? TEMPLATE_STATUS.unknown : null;
  const whatsappOn = data.channels.whatsapp.providers.some((p) => p.provider === data.channels.whatsapp.connected && p.account?.is_enabled);
  const ready = data.alerts.whatsapp_enabled && data.alerts.whatsapp_recipients > 0 && whatsappOn && template?.status === 'approved';
  const steps: [boolean, string][] = [
    [data.alerts.whatsapp_enabled && data.alerts.whatsapp_recipients > 0, data.alerts.whatsapp_recipients > 0 ? `WhatsApp alerts switched on for ${data.alerts.whatsapp_recipients} number${data.alerts.whatsapp_recipients === 1 ? '' : 's'}` : 'WhatsApp alerts and the numbers to alert'],
    [whatsappOn, 'Umar Techy’s WhatsApp account connected and on'],
    [template?.status === 'approved', 'An approved alert template'],
  ];

  return (
    <Card
      title="Critical alerts on WhatsApp"
      description="Email always carries critical alerts. WhatsApp is an extra channel, sent once per incident."
      actions={ready ? <Badge tone="green">Ready</Badge> : <Badge tone="amber">Not ready</Badge>}
    >
      <ul className="space-y-1.5 text-sm">
        {steps.map(([done, label]) => (
          <li key={label} className="flex items-start gap-2">
            <span aria-hidden="true" className={`mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[10px] ${done ? 'bg-emerald-600 text-white' : 'border border-slate-300'}`}>{done ? '✓' : ''}</span>
            <span className={done ? 'text-slate-800' : 'text-slate-600'}>{label}<span className="sr-only">{done ? ' — done' : ' — to do'}</span></span>
          </li>
        ))}
      </ul>
      <p className="mt-2 text-xs text-slate-500">Numbers and the on/off switch are under <Link href="/super-admin/settings" className="font-medium text-brand-700 underline">Platform settings</Link> → Alerts.</p>
      <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
        <p className="text-sm text-slate-700">
          Template: {template ? <><span className="font-mono">{template.name}</span> · {template.language}{template.checked_at && <span className="text-xs text-slate-500"> · checked {formatDateTime(template.checked_at)}</span>}</> : 'none yet'}
          {status && <span className="ml-2"><Badge tone={status.tone}>{status.label}</Badge></span>}
        </p>
        <Button size="sm" onClick={() => setEditing(true)}>{template ? 'Change template' : 'Set template'}</Button>
      </div>
      {editing && (
        <TemplateDialog
          event={{ label: 'Critical alert', variables: data.alert_template.variables, whatsapp_body: data.alert_template.whatsapp_body, template: template ? { ...template, checked_at: template.checked_at } : null }}
          endpoint="/super-admin/messaging/alert-template"
          onClose={() => setEditing(false)}
          onSaved={() => { setEditing(false); onSaved(); }}
        />
      )}
    </Card>
  );
}

function GatedCard({ rows, stores, onSaved }: { rows: Gated[]; stores: Record<string, number>; onSaved: () => void }) {
  const [live, setLive] = useState<{ row: Gated; allowed: boolean } | null>(null);

  return (
    <Card title="Real messages by SMS provider" description="Owner decision 3: an SMS provider sends real messages only after Umar Techy has checked its sender ID, delivery and prices for Pakistan. Until then stores can connect it in sandbox only. Meta’s WhatsApp is open: each store uses its own approved WhatsApp Business account.">
      <ul className="divide-y divide-slate-100">
        {rows.map((row) => (
          <li key={row.provider} className="flex flex-wrap items-center justify-between gap-3 py-3">
            <div>
              <p className="font-medium text-slate-900">{row.label}</p>
              <p className="text-xs text-slate-500">{stores[row.provider] ?? 0} store account{(stores[row.provider] ?? 0) === 1 ? '' : 's'} switched on</p>
            </div>
            <div className="flex items-center gap-2">
              {row.live_allowed ? <Badge tone="green">Open for real messages</Badge> : <Badge tone="amber">Sandbox only</Badge>}
              <Button size="sm" variant={row.live_allowed ? 'danger' : 'primary'} onClick={() => setLive({ row, allowed: !row.live_allowed })}>
                {row.live_allowed ? 'Close' : 'Open'}<span className="sr-only"> {row.label} for real messages</span>
              </Button>
            </div>
          </li>
        ))}
      </ul>
      {live && <LiveDialog row={live.row} allowed={live.allowed} onClose={() => setLive(null)} onDone={() => { setLive(null); onSaved(); }} />}
    </Card>
  );
}

function LiveDialog({ row, allowed, onClose, onDone }: { row: Gated; allowed: boolean; onClose: () => void; onDone: () => void }) {
  const form = useForm({ reason: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch(`/super-admin/messaging/providers/${row.provider}/live`, { method: 'PUT', body: { allowed, reason: form.values.reason } }), allowed ? `${row.label} is open for real messages.` : `${row.label} is closed for real messages.`)) !== undefined) onDone();
  }

  return (
    <Dialog open title={allowed ? `Open ${row.label} for real messages?` : `Close ${row.label} for real messages?`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        {allowed ? (
          <div className="space-y-2 text-sm text-slate-700">
            <p>Stores will be able to connect production {row.label} accounts and text their customers.</p>
            <p className="rounded-md bg-amber-50 p-2 text-amber-900">Only after checking with a real {row.label} account: the sender ID reaches Pakistani networks, delivery reports arrive, and the price per message is known.</p>
          </div>
        ) : (
          <p className="text-sm text-slate-700">Every store’s live {row.label} account is switched off at once. Messages already sent are not affected. Stores turn it back on themselves after you reopen it.</p>
        )}
        <TextAreaField label="Reason" rows={2} value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={300} required data-autofocus hint="Kept in the audit log." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant={allowed ? 'primary' : 'danger'} busy={form.busy} busyLabel="Saving…">{allowed ? 'Open for real messages' : 'Close for real messages'}</Button>
        </div>
      </form>
    </Dialog>
  );
}
