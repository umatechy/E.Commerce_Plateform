/**
 * Shapes of the return API (Phase B33, Module 09 §45–54): ReturnResource
 * for staff and for the customer, and the "what can be returned" answer.
 * Every amount and every allowed next step (`can`) comes from the server.
 */
export const RETURN_STATUSES = ['requested', 'under_review', 'approved', 'rejected', 'in_transit', 'received', 'inspected', 'approved_for_refund', 'completed', 'cancelled'] as const;

export const RETURN_STATUS_LABELS: Record<string, string> = {
  requested: 'Requested',
  under_review: 'Under review',
  approved: 'Approved — waiting for the items',
  rejected: 'Not accepted',
  in_transit: 'Items on their way back',
  received: 'Items received',
  inspected: 'Inspected',
  approved_for_refund: 'Refund approved',
  completed: 'Completed',
  cancelled: 'Cancelled',
};

export const RETURN_RESOLUTIONS = ['refund', 'replacement', 'exchange'] as const;
export const RESOLUTION_LABELS: Record<string, string> = {
  refund: 'Refund',
  replacement: 'Replacement (the same items again)',
  exchange: 'Exchange for other items',
};

export const RETURN_REASONS = ['defective', 'damaged_in_transit', 'wrong_item', 'not_as_described', 'size_or_fit', 'changed_mind', 'arrived_late', 'other'] as const;
export const REASON_LABELS: Record<string, string> = {
  defective: 'Defective or not working',
  damaged_in_transit: 'Arrived damaged',
  wrong_item: 'Wrong item sent',
  not_as_described: 'Not as described',
  size_or_fit: 'Size or fit',
  changed_mind: 'Changed my mind',
  arrived_late: 'Arrived too late',
  other: 'Other',
};

export const RETURN_METHODS = ['customer_ships', 'carrier_pickup', 'drop_off'] as const;
export const METHOD_LABELS: Record<string, string> = {
  customer_ships: 'The customer sends the parcel',
  carrier_pickup: 'A courier collects it',
  drop_off: 'The customer brings it to the store',
};

export const ORDER_RETURN_LABELS: Record<string, string> = {
  none: 'No returns',
  return_requested: 'Return open',
  partially_returned: 'Partly returned',
  returned: 'Returned',
};

export type ReturnLine = {
  order_item_id: number;
  name: string | null;
  sku: string | null;
  variant: Record<string, unknown> | null;
  unit_price_minor: number;
  quantity: number;
  resalable_quantity: number;
  damaged_quantity: number;
  rejected_quantity: number;
  refund_minor: number;
  inspection_note?: string | null;
};

export type ReturnRecord = {
  id: string;
  return_number: string;
  status: string;
  resolution: string;
  reason: string;
  description: string | null;
  requested_by: 'customer' | 'staff';
  decision_note: string | null;
  return_method: string | null;
  return_shipping_paid_by: 'customer' | 'store' | null;
  return_carrier: string | null;
  return_tracking_number: string | null;
  currency: string;
  items_refund_minor: number;
  shipping_refund_minor: number;
  restocking_fee_minor: number;
  refund_total_minor: number;
  refunded_minor: number;
  order?: { id: string; order_number: string; customer_name?: string | null; payment_status?: string; shipping_total_minor?: number };
  replacement_order?: { id: string; order_number: string; grand_total_minor: number } | null;
  items?: ReturnLine[];
  warehouse?: { id: number; name: string } | null;
  can: Record<'review' | 'approve' | 'reject' | 'cancel' | 'mark_in_transit' | 'receive' | 'inspect' | 'approve_refund' | 'replace' | 'refund', boolean>;
  created_at: string;
  decided_at: string | null;
  shipped_back_at: string | null;
  received_at: string | null;
  inspected_at: string | null;
  refunded_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
};

export type ReturnableLine = {
  order_item_id: number;
  name: string;
  sku: string | null;
  variant: Record<string, unknown> | null;
  ordered: number;
  delivered: number;
  in_returns: number;
  returnable: number;
  delivered_at: string | null;
  window_open: boolean;
  unit_price_minor: number;
};

export type Returnable = { blocked: string | null; window_days: number; customer_requests_enabled?: boolean; enabled?: boolean; lines: ReturnableLine[]; returns?: ReturnRecord[] };

/** The steps of a return that went well, in order, with when each happened. */
export function returnSteps(record: ReturnRecord): { label: string; at: string | null }[] {
  return [
    { label: 'Requested', at: record.created_at },
    { label: record.status === 'rejected' && record.inspected_at === null ? 'Not accepted' : 'Approved', at: record.decided_at },
    { label: 'Sent back', at: record.shipped_back_at },
    { label: 'Received', at: record.received_at },
    { label: 'Inspected', at: record.inspected_at },
    { label: 'Refunded', at: record.refunded_at },
    { label: 'Completed', at: record.completed_at },
  ].filter((step) => step.at !== null);
}
