import { ReactNode } from 'react';

/**
 * A small status label. The status is always written out; colour only
 * supports the text (no colour-only meaning).
 */
export type Tone = 'neutral' | 'green' | 'amber' | 'red' | 'blue';

const TONE: Record<Tone, string> = {
  neutral: 'bg-slate-100 text-slate-700 ring-slate-200',
  green: 'bg-green-50 text-green-800 ring-green-200',
  amber: 'bg-amber-50 text-amber-900 ring-amber-200',
  red: 'bg-red-50 text-red-800 ring-red-200',
  blue: 'bg-blue-50 text-blue-800 ring-blue-200',
};

export default function Badge({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
  return <span className={`inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${TONE[tone]}`}>{children}</span>;
}

/** "pending_confirmation" → "Pending confirmation". */
export function humanize(value: string): string {
  const text = value.replace(/[_.]/g, ' ').trim();

  return text === '' ? '' : text.charAt(0).toUpperCase() + text.slice(1);
}

const GREEN = ['active', 'paid', 'completed', 'delivered', 'verified', 'published', 'succeeded', 'sent', 'ok', 'fulfilled', 'in_stock', 'enabled', 'yes', 'restored', 'read'];
const AMBER = ['pending', 'pending_confirmation', 'draft', 'trialing', 'past_due', 'grace_period', 'scheduled', 'paused', 'processing', 'warning', 'partially_paid', 'partially_fulfilled', 'partially_refunded', 'verification_required', 'low_stock', 'queued', 'running', 'verifying', 'open', 'requires_action', 'authorized', 'unfulfilled', 'unpaid', 'refund_pending', 'suspended', 'expired', 'requested', 'under_review', 'return_requested', 'partially_returned', 'approved_for_refund', 'received', 'inspected'];
const RED = ['failed', 'cancelled', 'rejected', 'critical', 'overdue', 'out_of_stock', 'revoked', 'restore_failed', 'delivery_failed', 'lost', 'damaged', 'disputed', 'reversed', 'uncollectible', 'broken', 'removed', 'disabled'];
const BLUE = ['confirmed', 'ready', 'ready_to_fulfill', 'in_transit', 'shipped', 'out_for_delivery', 'picked_up', 'fulfilling', 'refunded', 'returned', 'returning', 'label_created', 'approved'];

export function toneForStatus(status: string): Tone {
  if (GREEN.includes(status)) return 'green';
  if (RED.includes(status)) return 'red';
  if (BLUE.includes(status)) return 'blue';
  if (AMBER.includes(status)) return 'amber';

  return 'neutral';
}

/** A server status shown as the server named it, in words. */
export function StatusBadge({ status, label }: { status: string; label?: string }) {
  return <Badge tone={toneForStatus(status)}>{label ?? humanize(status)}</Badge>;
}
