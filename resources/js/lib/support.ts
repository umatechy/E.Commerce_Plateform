import { formatDate } from './datetime';

/**
 * Module 34 (Phase B26) — shapes of the support API (SupportPresenter)
 * and the display helpers shared by the storefront and admin pages.
 * The server decides what each side may see and do; these helpers only
 * turn its answers into words.
 */

export type SupportStatus = 'open' | 'awaiting_customer' | 'on_hold' | 'resolved' | 'closed';
export type SupportPriority = 'low' | 'normal' | 'high' | 'urgent';
export type SupportCategory = 'order' | 'shipping' | 'payment' | 'returns' | 'product' | 'account' | 'billing' | 'technical' | 'other';

export type TicketSummary = {
  id: string;
  number: string;
  subject: string;
  category: SupportCategory;
  status: SupportStatus;
  created_at: string;
  updated_at: string;
};

export type AgentTicketSummary = TicketSummary & {
  desk: 'store' | 'platform';
  store: { id: string; name: string } | null;
  priority: SupportPriority;
  channel: string;
  requester: { type: 'customer' | 'guest' | 'user'; name: string; email: string };
  assignee: { id: string; name: string } | null;
  sla: {
    first_response_due_at: string | null;
    first_responded_at: string | null;
    resolution_due_at: string | null;
    breached: boolean;
  };
  satisfaction_rating: number | null;
  last_requester_activity_at: string | null;
};

export type TicketMessage = {
  id: string;
  author_type: 'requester' | 'agent' | 'system';
  author_name: string;
  body: string;
  internal?: boolean;
  created_at: string;
};

export type TicketExtras = {
  order: { id: string; number: string; status?: string; grand_total_minor?: number; currency?: string } | null;
  messages: TicketMessage[];
  can_reply: boolean;
  can_resolve: boolean;
  can_rate: boolean;
  satisfaction: { rating: number; comment: string | null } | null;
};

export type TicketDetail = TicketSummary & TicketExtras;
export type AgentTicketDetail = AgentTicketSummary & TicketExtras;

export type TicketPage<T> = { tickets: T[]; pagination: { page: number; per_page: number; total: number; last_page: number } };

export type SupportSummary = {
  abilities: { reply: boolean; manage: boolean };
  by_status: Partial<Record<SupportStatus, number>>;
  unassigned: number;
  breached: number;
  mine: number;
  /** The averages are null until there is something to average. */
  last_30_days: { created: number; avg_first_response_minutes: number | null; satisfaction_avg: number | null };
};

/** The categories each desk offers (mirrors SupportCategory::forDesk()). */
export const STORE_CATEGORIES: SupportCategory[] = ['order', 'shipping', 'payment', 'returns', 'product', 'account', 'other'];
export const PLATFORM_CATEGORIES: SupportCategory[] = ['billing', 'technical', 'account', 'payment', 'other'];
export const PRIORITIES: SupportPriority[] = ['urgent', 'high', 'normal', 'low'];
export const STATUSES: SupportStatus[] = ['open', 'awaiting_customer', 'on_hold', 'resolved', 'closed'];

const CATEGORY_LABELS: Record<SupportCategory, string> = {
  order: 'An order',
  shipping: 'Shipping & delivery',
  payment: 'Payment',
  returns: 'Returns & refunds',
  product: 'A product',
  account: 'My account',
  billing: 'Billing & plan',
  technical: 'Technical problem',
  other: 'Something else',
};

const PRIORITY_LABELS: Record<SupportPriority, string> = { urgent: 'Urgent', high: 'High', normal: 'Normal', low: 'Low' };

export function categoryLabel(category: SupportCategory): string {
  return CATEGORY_LABELS[category] ?? category;
}

export function priorityLabel(priority: SupportPriority): string {
  return PRIORITY_LABELS[priority] ?? priority;
}

/**
 * Status words differ by reader: "awaiting customer" is a waiting reply
 * to the team, and the requester's own next step to the requester.
 */
export function statusLabel(status: SupportStatus, audience: 'requester' | 'agent' = 'requester'): string {
  if (audience === 'requester') {
    return (
      {
        open: 'Waiting for the team',
        awaiting_customer: 'Waiting for your reply',
        on_hold: 'On hold',
        resolved: 'Resolved',
        closed: 'Closed',
      } as Record<SupportStatus, string>
    )[status];
  }

  return (
    { open: 'Open', awaiting_customer: 'Awaiting customer', on_hold: 'On hold', resolved: 'Resolved', closed: 'Closed' } as Record<
      SupportStatus,
      string
    >
  )[status];
}

export function isActive(status: SupportStatus): boolean {
  return status === 'open' || status === 'awaiting_customer' || status === 'on_hold';
}

/** A short span of time: "45m", "5h", "2d 3h". */
export function formatDuration(ms: number): string {
  const minutes = Math.max(0, Math.round(Math.abs(ms) / 60000));
  if (minutes < 60) return `${minutes}m`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h${minutes % 60 && hours < 10 ? ` ${minutes % 60}m` : ''}`;
  const days = Math.floor(hours / 24);

  return `${days}d${hours % 24 ? ` ${hours % 24}h` : ''}`;
}

/** "just now", "5m ago", "3h ago", "2d ago", or the date after a week. */
export function timeAgo(iso: string, now: Date = new Date()): string {
  const ms = now.getTime() - new Date(iso).getTime();
  if (ms < 60000) return 'just now';
  if (ms > 7 * 24 * 3600 * 1000) return formatDate(iso);

  return `${formatDuration(ms)} ago`;
}

export type SlaState = { state: 'breached' | 'due_soon' | 'on_track' | 'none'; label: string };

/**
 * Where a ticket stands against its service levels: the first reply is
 * due first, then the resolution. A due time that has passed counts as
 * overdue at once, even before the hourly job flags it; "due soon" is
 * the last hour. Tickets on hold keep their clock, as on the server.
 */
export function slaState(ticket: Pick<AgentTicketSummary, 'status' | 'sla'>, now: Date = new Date()): SlaState {
  if (!isActive(ticket.status)) {
    return { state: 'none', label: '' };
  }

  const answered = ticket.sla.first_responded_at !== null;
  const due = answered ? ticket.sla.resolution_due_at : ticket.sla.first_response_due_at;
  const what = answered ? 'Resolve' : 'Reply';

  if (ticket.sla.breached || (due !== null && new Date(due).getTime() <= now.getTime())) {
    return { state: 'breached', label: due ? `${what} overdue by ${formatDuration(now.getTime() - new Date(due).getTime())}` : 'Overdue' };
  }
  if (due === null) {
    return { state: 'none', label: '' };
  }

  const left = new Date(due).getTime() - now.getTime();

  return { state: left <= 3600 * 1000 ? 'due_soon' : 'on_track', label: `${what} due in ${formatDuration(left)}` };
}

/** Error text from a failed support call: the first field error, else the server's message. */
export function supportError(body: Record<string, unknown> | undefined, fallback = 'Something went wrong. Please try again.'): string {
  const errors = body?.errors as Record<string, string[]> | undefined;
  const first = errors ? Object.values(errors)[0]?.[0] : undefined;

  return first ?? (typeof body?.message === 'string' ? body.message : fallback);
}
