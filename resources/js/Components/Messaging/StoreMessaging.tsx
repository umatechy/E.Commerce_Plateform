import { FormEvent, useEffect, useState } from 'react';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import Dialog from '@/Components/ui/Dialog';
import { FormError, TextField } from '@/Components/ui/Form';
import { Card, QueryState } from '@/Components/ui/Page';
import MessagingChannels from '@/Components/Messaging/MessagingChannels';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { formatDateTime } from '@/lib/datetime';
import { CHANNEL_LABEL, TEMPLATE_STATUS, type ChannelInfo, type MessagingChannel, type MessagingEvent } from '@/lib/messaging';

/**
 * Phase B50 (gap G10; Module 21 §9, §23, §31–37, §46): Messages → WhatsApp
 * & SMS. Three steps, top to bottom, as a store owner meets them:
 *
 * 1. Connect an account per channel (MessagingChannels).
 * 2. Choose which order updates go by SMS and which by WhatsApp, and a
 *    daily limit that protects the store's balance with its provider.
 * 3. For WhatsApp: name the template approved in WhatsApp Manager for each
 *    update, with the exact text to submit there.
 *
 * People who may manage messages (notifications.manage) choose updates and
 * templates; connecting accounts needs messaging.providers.
 */
type Settings = {
  can_manage: boolean;
  can_configure_providers: boolean;
  channels: Record<MessagingChannel, ChannelInfo>;
  events: MessagingEvent[];
  sms_events: string[];
  whatsapp_events: string[];
  daily_limit: number;
};

export default function StoreMessaging() {
  const state = useApi<{ data: Settings }>('/messaging-settings');

  return (
    <QueryState state={state} lines={6}>
      {({ data }) => (
        <div className="space-y-6">
          <MessagingChannels channels={data.channels} base="/messaging-settings" owner="store" canConfigure={data.can_configure_providers} onChanged={state.reload} />
          <UpdatesCard settings={data} onSaved={state.reload} />
          {(data.channels.whatsapp.connected !== null || data.whatsapp_events.length > 0) && <TemplatesCard settings={data} onSaved={state.reload} />}
        </div>
      )}
    </QueryState>
  );
}

function isOn(settings: Settings, channel: MessagingChannel): boolean {
  const info = settings.channels[channel];

  return info.providers.some((p) => p.provider === info.connected && p.account?.is_enabled);
}

function UpdatesCard({ settings, onSaved }: { settings: Settings; onSaved: () => void }) {
  const initial = { sms: settings.sms_events, whatsapp: settings.whatsapp_events, limit: String(settings.daily_limit) };
  const form = useForm(initial);
  const savedKey = `${settings.sms_events.join()}|${settings.whatsapp_events.join()}|${settings.daily_limit}`;
  useEffect(() => form.reset(initial), [savedKey]); // eslint-disable-line react-hooks/exhaustive-deps
  const [preview, setPreview] = useState<MessagingEvent | null>(null);
  const v = form.values;
  const readOnly = !settings.can_manage;

  function toggle(channel: MessagingChannel, key: string, checked: boolean) {
    const list = channel === 'sms' ? v.sms : v.whatsapp;
    form.set(channel, checked ? [...list, key] : list.filter((k) => k !== key));
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = { sms_events: v.sms, whatsapp_events: v.whatsapp, daily_limit: Number(v.limit) };
    if ((await form.submit(() => adminFetch('/messaging-settings', { method: 'PUT', body }), 'Order updates saved.')) !== undefined) onSaved();
  }

  const off = (['sms', 'whatsapp'] as const).filter((channel) => (channel === 'sms' ? v.sms : v.whatsapp).length > 0 && !isOn(settings, channel));

  return (
    <Card title="Order updates" description="Which updates customers get, and how. Email updates are unchanged. Messages are never marketing: only news about an order the customer placed.">
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} errors={Object.fromEntries(Object.entries(form.errors).filter(([key]) => key.includes('events')))} />
        <div className="relative overflow-x-auto">
          <table className="w-full text-sm">
            <caption className="sr-only">Order updates by channel</caption>
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                <th scope="col" className="py-2 pr-2 font-medium">Update</th>
                <th scope="col" className="w-14 px-1 py-2 text-center font-medium sm:w-24">SMS</th>
                <th scope="col" className="w-20 px-1 py-2 text-center font-medium sm:w-24">WhatsApp</th>
                <th scope="col" className="w-16 py-2 pl-1"><span className="sr-only">Wording</span></th>
              </tr>
            </thead>
            <tbody>
              {settings.events.map((event) => (
                <tr key={event.key} className="border-b border-slate-100 last:border-0">
                  <th scope="row" className="py-2 pr-2 text-left font-normal text-slate-800">{event.label}</th>
                  {(['sms', 'whatsapp'] as const).map((channel) => (
                    <td key={channel} className="px-1 py-2 text-center">
                      <input
                        type="checkbox"
                        aria-label={`${event.label} by ${CHANNEL_LABEL[channel]}`}
                        checked={(channel === 'sms' ? v.sms : v.whatsapp).includes(event.key)}
                        disabled={readOnly}
                        onChange={(e) => toggle(channel, event.key, e.target.checked)}
                        className="h-4 w-4 rounded border-slate-300 text-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                      />
                    </td>
                  ))}
                  <td className="py-2 pl-1 text-right">
                    <Button size="sm" variant="ghost" onClick={() => setPreview(event)}>Wording<span className="sr-only"> of {event.label}</span></Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {off.length > 0 && (
          <p role="note" className="rounded-md bg-amber-50 p-2 text-xs text-amber-900">
            {off.map((c) => CHANNEL_LABEL[c]).join(' and ')} {off.length > 1 ? 'are' : 'is'} not switched on above: these updates wait until {off.length > 1 ? 'they are' : 'it is'}.
          </p>
        )}
        <div className="grid gap-4 sm:grid-cols-[14rem_1fr] sm:items-end">
          <TextField label="Most messages a day" inputMode="numeric" value={v.limit} disabled={readOnly} onChange={(x) => form.set('limit', x.replace(/\D/g, ''))} error={form.errors.daily_limit} hint="SMS and WhatsApp together. Protects your balance if something goes wrong." />
          {settings.can_manage && (
            <div className="flex justify-end">
              <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={!form.dirty}>Save updates</Button>
            </div>
          )}
        </div>
      </form>
      {preview && (
        <Dialog open title={preview.label} description="What the customer receives. Words in {braces} are filled in from the order." onClose={() => setPreview(null)}>
          <div className="space-y-4 text-sm">
            <div>
              <h3 className="font-medium text-slate-800">SMS</h3>
              <p className="mt-1 whitespace-pre-line rounded-md bg-slate-50 p-3 text-slate-800">{preview.text}</p>
              <p className="mt-1 text-xs text-slate-500">To use your own wording, publish an SMS template with the key <code className="rounded bg-slate-100 px-1">messaging.{preview.key}</code> on the Templates tab.</p>
            </div>
            <div>
              <h3 className="font-medium text-slate-800">WhatsApp</h3>
              <p className="mt-1 text-slate-600">Sent as the template you approved with Meta (see WhatsApp templates below).</p>
            </div>
            <div className="flex justify-end"><Button onClick={() => setPreview(null)}>Close</Button></div>
          </div>
        </Dialog>
      )}
    </Card>
  );
}

function TemplatesCard({ settings, onSaved }: { settings: Settings; onSaved: () => void }) {
  const [editing, setEditing] = useState<MessagingEvent | null>(null);
  const { busy, run } = useAction();
  const chosen = new Set(settings.whatsapp_events);
  const rows = [...settings.events].sort((a, b) => Number(chosen.has(b.key)) - Number(chosen.has(a.key)));

  return (
    <Card title="WhatsApp templates" description="WhatsApp only delivers business messages written as templates that Meta approved. Create each one in WhatsApp Manager with the text shown, then enter its name here.">
      <ul className="divide-y divide-slate-100">
        {rows.map((event) => {
          const template = event.template;
          const status = template ? TEMPLATE_STATUS[template.status] ?? TEMPLATE_STATUS.unknown : null;
          const needed = chosen.has(event.key);

          return (
            <li key={event.key} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <div className="min-w-0">
                <p className="text-sm font-medium text-slate-900">
                  {event.label}
                  {!needed && <span className="ml-2 text-xs font-normal text-slate-500">(not sent on WhatsApp)</span>}
                </p>
                <p className="text-xs text-slate-600">
                  {template ? <><span className="font-mono">{template.name}</span> · {template.language}{template.checked_at && ` · checked ${formatDateTime(template.checked_at)}`}</> : needed ? <span className="text-amber-800">No template yet: this update cannot go out on WhatsApp.</span> : 'No template'}
                </p>
              </div>
              <div className="flex items-center gap-2">
                {status && <Badge tone={status.tone}>{status.label}</Badge>}
                {settings.can_manage && template && (
                  <Button size="sm" variant="ghost" busy={busy === event.key} busyLabel="Checking…" onClick={() => run(event.key, () => adminFetch(`/messaging-settings/templates/${event.key}/check`, { method: 'POST' }), { success: 'Checked with WhatsApp.' }).then((done) => done !== undefined && onSaved())}>
                    Check now<span className="sr-only"> {event.label}</span>
                  </Button>
                )}
                {settings.can_manage && <Button size="sm" onClick={() => setEditing(event)}>{template ? 'Change' : 'Set template'}<span className="sr-only"> for {event.label}</span></Button>}
              </div>
            </li>
          );
        })}
      </ul>
      {editing && <TemplateDialog event={editing} endpoint={`/messaging-settings/templates/${editing.key}`} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); onSaved(); }} />}
    </Card>
  );
}

/** Name the approved template for one update, with the text and variables to submit to Meta. Also used by the Super Admin for critical alerts. */
export function TemplateDialog({ event, endpoint, onClose, onSaved }: { event: Pick<MessagingEvent, 'label' | 'variables' | 'whatsapp_body' | 'template'>; endpoint: string; onClose: () => void; onSaved: () => void }) {
  const form = useForm({ name: event.template?.name ?? '', language: event.template?.language ?? 'en' });
  const [copied, setCopied] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    if ((await form.submit(() => adminFetch(endpoint, { method: 'PUT', body: { template_name: form.values.name.trim(), language_code: form.values.language.trim() } }), 'Template saved.')) !== undefined) onSaved();
  }

  async function copy() {
    try {
      await navigator.clipboard.writeText(event.whatsapp_body);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      /* the text stays visible to copy by hand */
    }
  }

  return (
    <Dialog open wide title={`WhatsApp template: ${event.label}`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <ol className="list-decimal space-y-3 pl-5 text-sm text-slate-700">
          <li>
            In WhatsApp Manager, create a <strong>Utility</strong> template with this body:
            <div className="mt-2 flex items-start gap-2">
              <p className="min-w-0 flex-1 whitespace-pre-line rounded-md bg-slate-50 p-3 font-mono text-xs text-slate-800">{event.whatsapp_body}</p>
              <Button size="sm" variant="ghost" onClick={copy}><span aria-live="polite">{copied ? 'Copied' : 'Copy'}</span></Button>
            </div>
            <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs text-slate-600">
              {event.variables.map((name, i) => (
                <div key={name} className="contents">
                  <dt className="font-mono">{`{{${i + 1}}}`}</dt>
                  <dd>{name.replace(/_/g, ' ')}</dd>
                </div>
              ))}
            </dl>
          </li>
          <li>When Meta has approved it, enter its name and language exactly as WhatsApp Manager shows them.</li>
        </ol>
        <div className="grid gap-4 sm:grid-cols-[1fr_10rem]">
          <TextField label="Template name" value={form.values.name} onChange={(x) => form.set('name', x)} error={form.errors.template_name} maxLength={120} placeholder="order_confirmed" autoComplete="off" required data-autofocus hint="Small letters, digits and _ only." />
          <TextField label="Language code" value={form.values.language} onChange={(x) => form.set('language', x)} error={form.errors.language_code} maxLength={16} placeholder="en" autoComplete="off" required hint="en, en_US, ur …" />
        </div>
        <p className="text-xs text-slate-500">Its approval is checked with WhatsApp when you save, and again before use.</p>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save template</Button>
        </div>
      </form>
    </Dialog>
  );
}
