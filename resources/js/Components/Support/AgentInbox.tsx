import { useCallback, useEffect, useState } from 'react';
import AgentTicketPanel from './AgentTicketPanel';
import SlaBadge from './SlaBadge';
import EmptyState from '@/Components/EmptyState';
import ErrorState from '@/Components/ErrorState';
import LoadingState from '@/Components/LoadingState';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';
import {
  categoryLabel,
  formatDuration,
  priorityLabel,
  PRIORITIES,
  STATUSES,
  statusLabel,
  timeAgo,
  type AgentTicketDetail,
  type AgentTicketSummary,
  type SupportCategory,
  type SupportSummary,
  type TicketPage,
} from '@/lib/support';

type Agent = { id: string; name: string };
type Filters = { status: string; priority: string; category: string; assignee: string; q: string; breached: boolean };

const DEFAULT_FILTERS: Filters = { status: 'active', priority: '', category: '', assignee: '', q: '', breached: false };
const select = 'rounded border border-slate-300 bg-white px-2 py-1.5 text-sm';
const PRIORITY_TONES: Record<string, string> = { urgent: 'bg-red-600 text-white', high: 'bg-orange-100 text-orange-800', normal: '', low: 'text-slate-400' };

function initialTicket(): string | null {
  if (typeof window === 'undefined') return null;
  const id = new URLSearchParams(window.location.search).get('ticket');

  return id && /^[0-9A-Za-z]{26}$/.test(id) ? id : null;
}

/**
 * A support team's inbox: workload figures, filters, the ticket queue
 * (overdue first, then the oldest waiting) and the selected ticket.
 * Shared by the store inbox (/support) and the platform inbox
 * (/super-admin/support); `apiBase` names the API it works against.
 * ?ticket=<id> opens a ticket directly (the links in the team's emails).
 */
export default function AgentInbox({ apiBase, title, categories, showStore = false }: { apiBase: string; title: string; categories: SupportCategory[]; showStore?: boolean }) {
  const [summary, setSummary] = useState<SupportSummary | null>(null);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [filters, setFilters] = useState<Filters>(DEFAULT_FILTERS);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [list, setList] = useState<TicketPage<AgentTicketSummary> | null>(null);
  const [selected, setSelected] = useState<string | null>(initialTicket);
  const [detail, setDetail] = useState<AgentTicketDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [now, setNow] = useState(() => new Date());

  // Due times are shown relative to now; refresh them every minute.
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 60000);

    return () => window.clearInterval(timer);
  }, []);

  const loadSummary = useCallback(() => {
    adminFetch<{ data: SupportSummary }>(`${apiBase}/summary`)
      .then((res) => setSummary(res.data))
      .catch((e) => setError(e instanceof AdminApiError && e.status === 403 ? 'You do not have access to this support inbox.' : adminErrorMessage(e)));
  }, [apiBase]);

  useEffect(() => {
    loadSummary();
    adminFetch<{ data: Agent[] }>(`${apiBase}/agents`)
      .then((res) => setAgents(res.data))
      .catch(() => setAgents([]));
  }, [apiBase, loadSummary]);

  // The search box waits for a pause in typing.
  useEffect(() => {
    const timer = window.setTimeout(() => {
      setFilters((f) => (f.q === search.trim() ? f : { ...f, q: search.trim() }));
      setPage(1);
    }, 300);

    return () => window.clearTimeout(timer);
  }, [search]);

  const loadList = useCallback(() => {
    adminFetch<{ data: TicketPage<AgentTicketSummary> }>(`${apiBase}/tickets`, {
      query: {
        status: filters.status,
        priority: filters.priority,
        category: filters.category,
        assignee: filters.assignee,
        q: filters.q,
        breached: filters.breached ? '1' : undefined,
        page: String(page),
      },
    })
      .then((res) => setList(res.data))
      .catch((e) => setError(adminErrorMessage(e)));
  }, [apiBase, filters, page]);

  useEffect(loadList, [loadList]);

  useEffect(() => {
    if (!selected) {
      setDetail(null);

      return;
    }
    setDetailError(null);
    adminFetch<{ data: AgentTicketDetail }>(`${apiBase}/tickets/${encodeURIComponent(selected)}`)
      .then((res) => setDetail(res.data))
      .catch((e) => {
        setDetail(null);
        setDetailError(e instanceof AdminApiError && e.status === 404 ? 'This ticket was not found in this inbox.' : adminErrorMessage(e));
      });
  }, [apiBase, selected]);

  function open(id: string) {
    setSelected(id);
    window.history.replaceState(window.history.state, '', `${window.location.pathname}?ticket=${id}`);
  }

  function changed(ticket: AgentTicketDetail) {
    setDetail(ticket);
    // Keep the queue row in step without reloading the whole page of results.
    setList((current) => current && { ...current, tickets: current.tickets.map((t) => (t.id === ticket.id ? { ...t, ...ticket } : t)) });
    loadSummary();
  }

  function filter(patch: Partial<Filters>) {
    setFilters((f) => ({ ...f, ...patch }));
    setPage(1);
  }

  if (error) return <ErrorState message={error} />;

  const avgReply = summary?.last_30_days.avg_first_response_minutes ?? null;
  const satisfaction = summary?.last_30_days.satisfaction_avg ?? null;
  const cards: { label: string; value: string; tone?: string; onClick?: () => void; pressed?: boolean }[] = summary
    ? [
        { label: 'Open', value: String(summary.by_status.open ?? 0), onClick: () => filter({ status: 'open', assignee: '', breached: false }), pressed: filters.status === 'open' },
        {
          label: 'Awaiting customer',
          value: String(summary.by_status.awaiting_customer ?? 0),
          onClick: () => filter({ status: 'awaiting_customer', assignee: '', breached: false }),
          pressed: filters.status === 'awaiting_customer',
        },
        { label: 'Unassigned', value: String(summary.unassigned), onClick: () => filter({ status: 'active', assignee: 'unassigned', breached: false }), pressed: filters.assignee === 'unassigned' },
        {
          label: 'Overdue',
          value: String(summary.breached),
          tone: summary.breached > 0 ? 'text-red-700' : undefined,
          onClick: () => filter({ status: 'active', assignee: '', breached: true }),
          pressed: filters.breached,
        },
        { label: 'Assigned to me', value: String(summary.mine), onClick: () => filter({ status: 'active', assignee: 'me', breached: false }), pressed: filters.assignee === 'me' },
        {
          label: 'Avg first reply (30 days)',
          value: avgReply === null ? '—' : avgReply < 1 ? 'under 1m' : formatDuration(avgReply * 60000),
        },
        { label: 'Satisfaction (30 days)', value: satisfaction === null ? '—' : `${satisfaction.toFixed(1)} / 5` },
      ]
    : [];

  return (
    <div className="space-y-5">
      <h1 className="text-lg font-semibold">{title}</h1>

      {!summary ? (
        <LoadingState />
      ) : (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7" data-testid="support-summary">
          {cards.map((card) =>
            card.onClick ? (
              <button
                key={card.label}
                type="button"
                onClick={card.onClick}
                aria-pressed={card.pressed}
                className={`rounded-2xl border bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md motion-reduce:transform-none ${card.pressed ? 'border-brand-500 ring-2 ring-brand-100' : 'border-slate-200 hover:border-slate-300'}`}
              >
                <span className="block text-xs text-slate-500">{card.label}</span>
                <span className={`text-2xl font-semibold tracking-tight text-slate-900 ${card.tone ?? ''}`}>{card.value}</span>
              </button>
            ) : (
              <div key={card.label} className="rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] p-4">
                <span className="block text-xs text-slate-500">{card.label}</span>
                <span className="text-2xl font-semibold tracking-tight text-slate-900">{card.value}</span>
              </div>
            ),
          )}
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] p-4" role="search">
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search number, subject, name or email"
          aria-label="Search tickets"
          className="min-w-[14rem] flex-1 block min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm shadow-slate-900/5 transition-colors placeholder:text-slate-400 hover:border-slate-400 focus:outline-none focus-visible:border-brand-500 focus-visible:ring-2 focus-visible:ring-brand-600"
        />
        <select aria-label="Status" value={filters.status} onChange={(e) => filter({ status: e.target.value })} className={select}>
          <option value="active">All active</option>
          <option value="all">All tickets</option>
          {STATUSES.map((s) => (
            <option key={s} value={s}>
              {statusLabel(s, 'agent')}
            </option>
          ))}
        </select>
        <select aria-label="Priority" value={filters.priority} onChange={(e) => filter({ priority: e.target.value })} className={select}>
          <option value="">Any priority</option>
          {PRIORITIES.map((p) => (
            <option key={p} value={p}>
              {priorityLabel(p)}
            </option>
          ))}
        </select>
        <select aria-label="Topic" value={filters.category} onChange={(e) => filter({ category: e.target.value })} className={select}>
          <option value="">Any topic</option>
          {categories.map((c) => (
            <option key={c} value={c}>
              {categoryLabel(c)}
            </option>
          ))}
        </select>
        <select aria-label="Assignee" value={filters.assignee} onChange={(e) => filter({ assignee: e.target.value })} className={select}>
          <option value="">Anyone</option>
          <option value="me">Me</option>
          <option value="unassigned">Unassigned</option>
          {agents.map((a) => (
            <option key={a.id} value={a.id}>
              {a.name}
            </option>
          ))}
        </select>
        <label className="flex items-center gap-1.5 text-sm">
          <input type="checkbox" checked={filters.breached} onChange={(e) => filter({ breached: e.target.checked })} />
          Overdue only
        </label>
      </div>

      <div className="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <section aria-label="Tickets">
          {!list && <LoadingState />}
          {list?.tickets.length === 0 && <EmptyState title="No tickets here" description="Nothing matches these filters." />}
          {list && list.tickets.length > 0 && (
            <ul className="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
              {list.tickets.map((ticket) => (
                <li key={ticket.id}>
                  <button
                    type="button"
                    onClick={() => open(ticket.id)}
                    aria-current={selected === ticket.id}
                    className={`block w-full px-3 py-3 text-left hover:bg-slate-50 ${selected === ticket.id ? 'bg-blue-50' : ''}`}
                  >
                    <div className="flex items-center gap-2 text-xs text-slate-500">
                      <span className="font-mono">{ticket.number}</span>
                      {ticket.priority !== 'normal' && <span className={`rounded px-1.5 py-0.5 font-medium ${PRIORITY_TONES[ticket.priority]}`}>{priorityLabel(ticket.priority)}</span>}
                      <span>{statusLabel(ticket.status, 'agent')}</span>
                      <span className="ml-auto">{timeAgo(ticket.last_requester_activity_at ?? ticket.created_at, now)}</span>
                    </div>
                    <p className="mt-1 truncate font-medium text-slate-900">{ticket.subject}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                      <span>{ticket.requester.name}</span>
                      {showStore && ticket.store && <span>· {ticket.store.name}</span>}
                      <span>· {ticket.assignee ? ticket.assignee.name : 'Unassigned'}</span>
                      <SlaBadge ticket={ticket} now={now} />
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          )}
          {list && list.pagination.last_page > 1 && (
            <div className="mt-3 flex items-center justify-between text-sm">
              <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                Previous
              </button>
              <span className="text-slate-500">
                Page {list.pagination.page} of {list.pagination.last_page} · {list.pagination.total} tickets
              </span>
              <button type="button" disabled={page >= list.pagination.last_page} onClick={() => setPage(page + 1)} className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                Next
              </button>
            </div>
          )}
        </section>

        <section aria-label="Selected ticket" className="rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] p-5">
          {detailError && <ErrorState message={detailError} />}
          {!selected && !detailError && <EmptyState title="Select a ticket" description="Pick a ticket from the list to read and answer it." />}
          {selected && !detail && !detailError && <LoadingState />}
          {detail && summary && (
            <AgentTicketPanel apiBase={apiBase} ticket={detail} agents={agents} categories={categories} abilities={summary.abilities} now={now} onChange={changed} />
          )}
        </section>
      </div>
    </div>
  );
}
