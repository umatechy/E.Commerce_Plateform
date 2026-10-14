import { FormEvent, useState } from 'react';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { TextField } from '@/Components/ui/Form';
import { Card } from '@/Components/ui/Page';
import AccountDialog from '@/Components/Payments/AccountDialog';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { formatDateTime } from '@/lib/datetime';
import { CHANNEL_LABEL, type ChannelInfo, type MessagingChannel, type MessagingProvider } from '@/lib/messaging';

/**
 * Phase B50 (gap G10; Module 21 §31–34, §46; owner decision 3): the SMS
 * and WhatsApp accounts — a store's own on Messages → WhatsApp & SMS, or
 * Umar Techy's own (critical alerts) on the Super Admin Messaging page.
 *
 * One account per channel. Each card says plainly what is connected,
 * whether it is on, the last answer from the provider, and the address the
 * provider sends delivery reports to. "Send a test message" works before
 * the account is switched on, so a sandbox account can be tried safely.
 */
export default function MessagingChannels({ channels, base, owner, canConfigure, onChanged }: {
  channels: Record<MessagingChannel, ChannelInfo>;
  /** '/messaging-settings' (store) or '/super-admin/messaging' (platform). */
  base: string;
  owner: 'store' | 'platform';
  canConfigure: boolean;
  onChanged: () => void;
}) {
  const [editing, setEditing] = useState<MessagingProvider | null>(null);
  const [removing, setRemoving] = useState<MessagingProvider | null>(null);
  const { busy, run } = useAction();

  return (
    <div className="grid gap-4 xl:grid-cols-2">
      {(['whatsapp', 'sms'] as const).map((channel) => (
        <ChannelCard
          key={channel}
          channel={channel}
          info={channels[channel]}
          base={base}
          owner={owner}
          canConfigure={canConfigure}
          busy={busy}
          onEdit={setEditing}
          onRemove={setRemoving}
          onToggle={(enabled) => run(`toggle-${channel}`, () => adminFetch(`${base}/channels/${channel}/enabled`, { method: 'PUT', body: { enabled } }), { success: enabled ? `${CHANNEL_LABEL[channel]} is on.` : `${CHANNEL_LABEL[channel]} is off.` }).then((done) => done !== undefined && onChanged())}
        />
      ))}
      {editing && (
        <AccountDialog info={editing} kind="messaging" endpoint={`${base}/accounts/${editing.provider}`} currency={null} owner={owner} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); onChanged(); }} />
      )}
      <ConfirmDialog
        open={removing !== null}
        title={`Disconnect ${removing?.label ?? ''}?`}
        confirmLabel="Disconnect"
        busy={busy === 'remove'}
        onClose={() => setRemoving(null)}
        onConfirm={() => removing && run('remove', () => adminFetch(`${base}/channels/${removing.channel}`, { method: 'DELETE' }), { success: 'Disconnected.' }).then((done) => {
          if (done !== undefined) {
            setRemoving(null);
            onChanged();
          }
        })}
      >
        <p>The saved details are deleted and no more {removing ? CHANNEL_LABEL[removing.channel] : ''} messages are sent. Messages already sent stay in the list.</p>
      </ConfirmDialog>
    </div>
  );
}

function ChannelCard({ channel, info, base, owner, canConfigure, busy, onEdit, onRemove, onToggle }: {
  channel: MessagingChannel;
  info: ChannelInfo;
  base: string;
  owner: 'store' | 'platform';
  canConfigure: boolean;
  busy: string | null;
  onEdit: (provider: MessagingProvider) => void;
  onRemove: (provider: MessagingProvider) => void;
  onToggle: (enabled: boolean) => void;
}) {
  const connected = info.providers.find((p) => p.provider === info.connected) ?? null;
  const account = connected?.account ?? null;
  const others = info.providers.filter((p) => p.provider !== info.connected);
  const label = CHANNEL_LABEL[channel];
  const isTest = connected?.provider.startsWith('test_') ?? false;
  const canTurnOn = connected !== null && account !== null && (isTest || (account.environment === 'production' && connected.live_allowed));

  return (
    <Card
      title={label}
      description={channel === 'whatsapp'
        ? (owner === 'store' ? 'Order updates on WhatsApp, to customers who said yes at checkout or in their account.' : 'Critical alerts on WhatsApp to the numbers set under Platform settings → Alerts.')
        : (owner === 'store' ? 'Order updates by text message to the phone number on the order.' : 'Umar Techy’s own SMS account.')}
      actions={account === null ? <Badge>Not connected</Badge> : account.is_enabled ? <Badge tone={isTest ? 'blue' : 'green'}>{isTest ? 'On (test)' : 'On'}</Badge> : <Badge tone="amber">Connected, off</Badge>}
    >
      {connected && account ? (
        <div className="space-y-4">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="font-semibold text-slate-900">{connected.label}</p>
              <p className="text-xs text-slate-500">{account.environment === 'production' ? 'Production' : 'Sandbox'}{info.sent_today !== undefined && ` · ${info.sent_today} sent today`}</p>
            </div>
            <Capabilities provider={connected} />
          </div>
          <p className="text-xs text-slate-600">
            {account.last_success_at ? `Last answer ${formatDateTime(account.last_success_at)}` : 'No message sent with it yet.'}
            {account.last_failure_at && <span className="block text-amber-800">Last problem {formatDateTime(account.last_failure_at)}{account.last_failure_message ? `: ${account.last_failure_message}` : ''}</span>}
          </p>
          {!account.is_enabled && (
            <p role="note" className="rounded-md bg-amber-50 p-2 text-xs text-amber-900">
              {canTurnOn
                ? `Nothing is sent until you turn ${label} on.`
                : isTest ? 'The test messenger is not available on this system.' : account.environment === 'production'
                  ? `${connected.label} is not yet open for real messages. Umar Techy opens it after checking sender name, delivery and price.`
                  : 'A sandbox account can send test messages only. Enter your production details to send to customers.'}
            </p>
          )}
          {account.webhook_url && <WebhookDetails provider={connected} url={account.webhook_url} verifyToken={account.verify_token} />}
          {canConfigure && <TestSend base={base} channel={channel} provider={connected} />}
          {canConfigure && (
            <div className="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
              {(account.is_enabled || canTurnOn) && (
                <Button size="sm" variant={account.is_enabled ? 'secondary' : 'primary'} busy={busy === `toggle-${channel}`} busyLabel="Saving…" onClick={() => onToggle(!account.is_enabled)}>
                  {account.is_enabled ? `Turn ${label} off` : `Turn ${label} on`}
                </Button>
              )}
              <Button size="sm" onClick={() => onEdit(connected)}>Edit details</Button>
              <Button size="sm" variant="ghost" onClick={() => onRemove(connected)}>Disconnect</Button>
            </div>
          )}
        </div>
      ) : (
        <p className="text-sm text-slate-600">{canConfigure ? `Choose the provider your ${label} messages go through.` : `Not connected. Ask the store owner or an administrator to connect ${label}.`}</p>
      )}

      {canConfigure && others.length > 0 && (
        <div className="mt-4 border-t border-slate-100 pt-3">
          <h3 className="text-sm font-semibold text-slate-900">{connected ? 'Use another provider' : 'Providers'}</h3>
          {connected && <p className="text-xs text-slate-500">One {label} account at a time: connecting another replaces {connected.label}.</p>}
          <ul className="mt-2 space-y-2">
            {others.map((provider) => (
              <li key={provider.provider} className="flex flex-wrap items-start justify-between gap-2 rounded-md border border-slate-200 p-3">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-slate-900">
                    {provider.label}
                    {provider.capabilities.needs_platform_go_ahead && !provider.live_allowed && <span className="ml-2"><Badge tone="amber">Sandbox only for now</Badge></span>}
                  </p>
                  {provider.capabilities.note && <p className="mt-0.5 text-xs text-slate-500">{provider.capabilities.note}</p>}
                </div>
                <Button size="sm" variant={connected ? 'secondary' : 'primary'} onClick={() => onEdit(provider)}>Connect<span className="sr-only"> {provider.label}</span></Button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Card>
  );
}

function Capabilities({ provider }: { provider: MessagingProvider }) {
  const c = provider.capabilities;
  const items: [boolean, string][] = [[c.delivery_reports, 'Delivery reports'], [c.read_receipts, 'Read receipts'], [c.inbound, 'Replies (STOP)'], [c.templates, 'Approved templates']];

  return (
    <ul aria-label={`${provider.label} can`} className="flex flex-wrap gap-1.5 text-xs">
      {items.filter(([on]) => on).map(([, label]) => <li key={label} className="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-800">{label}</li>)}
    </ul>
  );
}

function CopyRow({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      /* clipboard refused: the value stays visible to copy by hand */
    }
  }

  return (
    <div>
      <dt className="text-xs font-medium text-slate-600">{label}</dt>
      <dd className="mt-0.5 flex items-center gap-2">
        <code className="min-w-0 flex-1 truncate rounded bg-slate-100 px-2 py-1 text-xs text-slate-800" title={value}>{value}</code>
        <Button size="sm" variant="ghost" onClick={copy} aria-label={`Copy ${label.toLowerCase()}`}><span aria-live="polite">{copied ? 'Copied' : 'Copy'}</span></Button>
      </dd>
    </div>
  );
}

/** Where the provider sends delivery reports and replies (and, for Meta, the verify token its dashboard asks for). */
function WebhookDetails({ provider, url, verifyToken }: { provider: MessagingProvider; url: string; verifyToken: string | null }) {
  const meta = provider.provider === 'meta_whatsapp';

  return (
    <details className="rounded-md border border-slate-200 p-3 text-sm">
      <summary className="cursor-pointer font-medium text-slate-800">Delivery reports and replies</summary>
      <p className="mt-2 text-xs text-slate-600">
        {meta
          ? 'In your Meta app: WhatsApp → Configuration → Webhook. Enter the callback URL and verify token below, then subscribe to the “messages” field.'
          : `Give this address to ${provider.label} for delivery reports and replies. Messages sent from here already ask for reports at this address.`}
      </p>
      <dl className="mt-2 space-y-2">
        <CopyRow label={meta ? 'Callback URL' : 'Webhook address'} value={url} />
        {verifyToken && <CopyRow label="Verify token" value={verifyToken} />}
      </dl>
    </details>
  );
}

function TestSend({ base, channel, provider }: { base: string; channel: MessagingChannel; provider: MessagingProvider }) {
  const [number, setNumber] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<{ outcome: string; message: string | null } | null>(null);
  const { busy, run } = useAction();

  async function send(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setResult(null);
    const done = await run('test', () => adminFetch<{ data: { outcome: string; message: string | null } }>(`${base}/channels/${channel}/test`, { method: 'POST', body: { number } }), { onError: setError });
    if (done !== undefined) setResult(done.data);
  }

  return (
    <form onSubmit={send} className="rounded-md bg-slate-50 p-3" noValidate>
      <div className="flex flex-wrap items-end gap-2">
        <div className="min-w-0 flex-1">
        <TextField
          label="Send a test message to"
          type="tel"
          autoComplete="off"
          inputMode="tel"
          placeholder="0300 1234567"
          value={number}
          onChange={setNumber}
          error={error}
          hint={provider.capabilities.templates ? 'WhatsApp sends Meta’s standard “hello_world” template, which every account has.' : 'Your own mobile number. A test message counts like any other with your provider.'}
        />
        </div>
        <Button type="submit" busy={busy === 'test'} busyLabel="Sending…" disabled={number.trim() === ''}>Send test</Button>
      </div>
      {result && (
        <p role="status" className={`mt-2 text-sm ${result.outcome === 'accepted' ? 'text-emerald-800' : 'text-amber-800'}`}>
          {result.outcome === 'accepted' ? `${provider.label} accepted the message.` : result.outcome === 'unknown' ? `No clear answer from ${provider.label}. Check your phone before trying again.` : `${provider.label} refused it${result.message ? `: ${result.message}` : '.'}`}
        </p>
      )}
    </form>
  );
}
