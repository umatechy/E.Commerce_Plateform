import type { Tone } from '@/Components/ui/Badge';

/**
 * Phase B51 (gap G14; Module 03 §33–37): a store's life in the admin.
 * Shapes mirror /api/v1/store-lifecycle and
 * /api/v1/super-admin/stores/{id}/lifecycle; the server decides every
 * state and every allowed move — these only present them.
 */
export type OpenItem = { key: string; count: number; label: string; hint: string };

export type HistoryEntry = { action: string; from: string; to: string; by: string; at: string; reason?: string | null; note?: string | null; actor?: string | null };

export type ClosureInfo = {
  status: string;
  stage_label: string;
  closed_at?: string | null;
  suspended_at?: string | null;
  suspension_reason?: string | null;
  retention_ends_at: string | null;
  retention_days: number;
  can_close?: boolean;
  can_reopen: boolean;
  is_owner: boolean;
  store_name: string;
  open_items?: OpenItem[];
  reasons?: string[];
  history?: HistoryEntry[];
  datasets: string[];
};

export const CLOSURE_REASONS: Record<string, string> = {
  not_selling: 'I am not selling any more',
  too_expensive: 'It costs too much',
  missing_features: 'Something I need is missing',
  moving_platform: 'I am moving to another platform',
  temporary_break: 'I am taking a break',
  other: 'Another reason',
  platform_request: 'Closed by Umar Techy',
};

export const SUSPENSION_REASONS: Record<string, string> = {
  abuse: 'Abuse or fraud',
  security_incident: 'Security incident',
  policy: 'Breaks the platform rules',
  customer_request: 'The owner asked for it',
  infrastructure: 'Technical problem',
  other: 'Another reason',
};

export const ACTION_LABEL: Record<string, string> = {
  close: 'Closed',
  reopen: 'Reopened',
  suspend: 'Suspended',
  reactivate: 'Suspension lifted',
  archive: 'Archived',
  restore: 'Brought back from the archive',
  legal_hold_set: 'Legal hold set',
  legal_hold_lifted: 'Legal hold lifted',
  deletion_requested: 'Deletion requested',
  deletion_cancelled: 'Deletion cancelled',
  start_deletion: 'Deletion started',
  finish_deletion: 'Data deleted',
};

export const ACTOR_LABEL: Record<string, string> = { owner: 'the owner', platform: 'Umar Techy', system: 'automatically' };

export const DATASETS: Record<string, { label: string; hint: string }> = {
  orders: { label: 'Orders', hint: 'One row per order: dates, statuses, customer, addresses and amounts.' },
  'order-lines': { label: 'Order lines', hint: 'One row per item sold: product, SKU, quantity and prices.' },
  customers: { label: 'Customers', hint: 'Names, contact details, consent and order totals.' },
  products: { label: 'Products', hint: 'Your catalogue in the import format, ready to load elsewhere.' },
};

export const DELETION_STATUS: Record<string, { label: string; tone: Tone }> = {
  scheduled: { label: 'Scheduled', tone: 'amber' },
  backing_up: { label: 'Taking a backup', tone: 'blue' },
  purging: { label: 'Removing data', tone: 'red' },
  completed: { label: 'Deleted', tone: 'neutral' },
  failed: { label: 'Stopped', tone: 'red' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
};

/** Whole days from now until `iso` (0 when past). */
export function daysUntil(iso: string | null | undefined, now: Date = new Date()): number {
  if (!iso) return 0;

  return Math.max(0, Math.ceil((new Date(iso).getTime() - now.getTime()) / 86_400_000));
}
