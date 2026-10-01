import { formatDateTime } from '@/lib/datetime';
import { useState, type FormEvent } from 'react';
import MessageThread from './MessageThread';
import SlaBadge from './SlaBadge';
import { formatMoney } from '@/lib/money';
import { adminErrorMessage, adminFetch } from '@/lib/adminApi';
import {
  categoryLabel,
  priorityLabel,
  PRIORITIES,
  STATUSES,
  statusLabel,
  type AgentTicketDetail,
  type SupportCategory,
  type SupportStatus,
} from '@/lib/support';

type Agent = { id: string; name: string };

const select = 'rounded border border-gray-300 bg-white px-2 py-1 text-sm disabled:bg-gray-100 disabled:text-gray-500';
const REQUESTER_TYPES: Record<string, string> = { customer: 'Customer', guest: 'Guest', user: 'Merchant' };
const AFTER_REPLY: { value: SupportStatus; label: string }[] = [
  { value: 'awaiting_customer', label: 'Wait for the customer' },
  { value: 'open', label: 'Keep open' },
  { value: 'on_hold', label: 'Put on hold' },
  { value: 'resolved', label: 'Mark resolved' },
];

/**
 * The team's view of one ticket: who asked, the service levels, the
 * triage controls and the conversation with a reply box that can also
 * post internal notes. Controls the signed-in agent may not use are
 * disabled (the server refuses them anyway).
 */
export default function AgentTicketPanel({
  apiBase,
  ticket,
  agents,
  categories,
  abilities,
  now,
  onChange,
}: {
  apiBase: string;
  ticket: AgentTicketDetail;
  agents: Agent[];
  categories: SupportCategory[];
  abilities: { reply: boolean; manage: boolean };
  now: Date;
  onChange: (ticket: AgentTicketDetail) => void;
}) {
  const [body, setBody] = useState('');
  const [internal, setInternal] = useState(false);
  const [after, setAfter] = useState<SupportStatus>('awaiting_customer');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const path = `${apiBase}/tickets/${encodeURIComponent(ticket.id)}`;

  async function run(kind: string, action: () => Promise<{ data: AgentTicketDetail }>, done?: () => void) {
    setBusy(kind);
    setError(null);
    try {
      onChange((await action()).data);
      done?.();
    } catch (e) {
      setError(adminErrorMessage(e));
    } finally {
      setBusy(null);
    }
  }

  const update = (field: string, value: string | null) => run(field, () => adminFetch(path, { method: 'PATCH', body: { [field]: value } }));

  function submit(event: FormEvent) {
    event.preventDefault();
    if (body.trim() === '') return;
    run(
      'reply',
      () => adminFetch(`${path}/messages`, { method: 'POST', body: internal ? { body, internal: true } : { body, status: after } }),
      () => {
        setBody('');
        setInternal(false);
      },
    );
  }

  const canChangeStatus = abilities.reply && ticket.status !== 'closed';

  return (
    <article className="space-y-5" aria-label={`Ticket ${ticket.number}`}>
      <header className="space-y-2">
        <div className="flex flex-wrap items-center gap-2 text-sm text-gray-500">
          <span className="font-mono">{ticket.number}</span>
          <span>· {categoryLabel(ticket.category)}</span>
          <span>· via {ticket.channel}</span>
          <SlaBadge ticket={ticket} now={now} />
        </div>
        <h2 className="text-xl font-semibold text-gray-900">{ticket.subject}</h2>
        <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
          <div>
            <dt className="inline text-gray-500">From </dt>
            <dd className="inline">
              {ticket.requester.name} &lt;{ticket.requester.email}&gt; <span className="text-gray-500">({REQUESTER_TYPES[ticket.requester.type] ?? ticket.requester.type})</span>
            </dd>
          </div>
          {ticket.store && (
            <div>
              <dt className="inline text-gray-500">Store </dt>
              <dd className="inline">{ticket.store.name}</dd>
            </div>
          )}
          {ticket.order && (
            <div>
              <dt className="inline text-gray-500">Order </dt>
              <dd className="inline">
                <span className="font-mono">{ticket.order.number}</span>
                {ticket.order.status && <span className="text-gray-500"> · {ticket.order.status.replace(/_/g, ' ')}</span>}
                {ticket.order.grand_total_minor !== undefined && ticket.order.currency && (
                  <span className="text-gray-500"> · {formatMoney(ticket.order.grand_total_minor, ticket.order.currency)}</span>
                )}
              </dd>
            </div>
          )}
          <div>
            <dt className="inline text-gray-500">Opened </dt>
            <dd className="inline">{formatDateTime(ticket.created_at)}</dd>
          </div>
          <div>
            <dt className="inline text-gray-500">First reply </dt>
            <dd className="inline">
              {ticket.sla.first_responded_at
                ? formatDateTime(ticket.sla.first_responded_at)
                : ticket.sla.first_response_due_at
                  ? `due ${formatDateTime(ticket.sla.first_response_due_at)}`
                  : '—'}
            </dd>
          </div>
          {ticket.satisfaction && (
            <div>
              <dt className="inline text-gray-500">Rating </dt>
              <dd className="inline">
                {'★'.repeat(ticket.satisfaction.rating)}
                <span className="text-gray-300">{'★'.repeat(5 - ticket.satisfaction.rating)}</span>
                {ticket.satisfaction.comment && <span className="text-gray-600"> “{ticket.satisfaction.comment}”</span>}
              </dd>
            </div>
          )}
        </dl>
      </header>

      <div className="flex flex-wrap gap-3 rounded border border-gray-200 bg-gray-50 p-3 text-sm">
        <label className="flex items-center gap-2">
          Status
          <select value={ticket.status} disabled={!canChangeStatus || busy !== null} onChange={(e) => update('status', e.target.value)} className={select} name="status">
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {statusLabel(s, 'agent')}
              </option>
            ))}
          </select>
        </label>
        <label className="flex items-center gap-2">
          Priority
          <select value={ticket.priority} disabled={!abilities.manage || busy !== null} onChange={(e) => update('priority', e.target.value)} className={select} name="priority">
            {PRIORITIES.map((p) => (
              <option key={p} value={p}>
                {priorityLabel(p)}
              </option>
            ))}
          </select>
        </label>
        <label className="flex items-center gap-2">
          Topic
          <select value={ticket.category} disabled={!abilities.manage || busy !== null} onChange={(e) => update('category', e.target.value)} className={select} name="category">
            {categories.map((c) => (
              <option key={c} value={c}>
                {categoryLabel(c)}
              </option>
            ))}
          </select>
        </label>
        <label className="flex items-center gap-2">
          Assignee
          <select
            value={ticket.assignee?.id ?? ''}
            disabled={!abilities.manage || busy !== null}
            onChange={(e) => update('assignee', e.target.value || null)}
            className={select}
            name="assignee"
          >
            <option value="">Unassigned</option>
            {ticket.assignee && !agents.some((a) => a.id === ticket.assignee?.id) && <option value={ticket.assignee.id}>{ticket.assignee.name}</option>}
            {agents.map((a) => (
              <option key={a.id} value={a.id}>
                {a.name}
              </option>
            ))}
          </select>
        </label>
        {busy && busy !== 'reply' && <span className="self-center text-gray-500">Saving…</span>}
      </div>

      {error && (
        <p role="alert" className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
          {error}
        </p>
      )}

      <MessageThread messages={ticket.messages} perspective="agent" tone="admin" />

      {!abilities.reply ? (
        <p className="rounded border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">You can read tickets here; replying needs the support reply permission.</p>
      ) : !ticket.can_reply ? (
        <p className="rounded border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">This ticket is closed. The customer can open a new request if they need more help.</p>
      ) : (
        <form onSubmit={submit} className={`space-y-3 rounded border p-3 ${internal ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white'}`}>
          <label className="block text-sm">
            <span className="mb-1 block text-gray-600">{internal ? 'Internal note — only your team sees this' : `Reply to ${ticket.requester.name}`}</span>
            <textarea rows={5} maxLength={10000} value={body} onChange={(e) => setBody(e.target.value)} className="w-full rounded border border-gray-300 px-3 py-2" name="reply" />
          </label>
          <div className="flex flex-wrap items-center gap-4 text-sm">
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={internal} onChange={(e) => setInternal(e.target.checked)} name="internal" />
              Internal note
            </label>
            {!internal && (
              <label className="flex items-center gap-2">
                Then
                <select value={after} onChange={(e) => setAfter(e.target.value as SupportStatus)} className={select} name="after">
                  {AFTER_REPLY.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <button type="submit" disabled={busy !== null || body.trim() === ''} className="ml-auto rounded bg-gray-900 px-4 py-2 font-medium text-white disabled:opacity-50">
              {busy === 'reply' ? 'Sending…' : internal ? 'Add note' : 'Send reply'}
            </button>
          </div>
        </form>
      )}
    </article>
  );
}
