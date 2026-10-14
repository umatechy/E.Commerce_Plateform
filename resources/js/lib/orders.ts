/**
 * Shapes of the order, payment and shipment APIs (OrderResource,
 * PaymentResource, ShipmentResource) and the values their requests
 * accept. Status values are the server's enums; the server's state
 * machines decide every transition.
 */
export const ORDER_STATUSES = [
  'draft', 'pending_confirmation', 'confirmed', 'processing', 'ready_to_fulfill', 'fulfilling', 'shipped', 'delivered', 'completed', 'cancelled',
  'return_requested', 'partially_returned', 'returned', 'refund_pending', 'partially_refunded', 'refunded', 'failed',
] as const;

export const ORDER_PAYMENT_STATUSES = ['unpaid', 'pending', 'authorized', 'paid', 'partially_paid', 'failed', 'cancelled', 'refund_pending', 'partially_refunded', 'refunded'] as const;

/** CancelOrderRequest. */
export const CANCELLATION_REASONS = ['customer_request', 'out_of_stock', 'payment_failed', 'address_issue', 'fraud_review', 'store_cancellation', 'other'] as const;

/** UpdateShipmentStatusRequest: the statuses staff may set by hand. */
export const SHIPMENT_STATUS_UPDATES = ['ready', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'delivery_failed', 'returning', 'returned', 'cancelled'] as const;

export const SHIPMENT_STATUSES = ['draft', 'ready', 'label_created', 'pickup_requested', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'delivery_failed', 'returning', 'returned', 'cancelled', 'lost', 'damaged'] as const;

export const PAYMENT_STATUSES = ['created', 'pending', 'requires_action', 'authorized', 'paid', 'partially_paid', 'failed', 'cancelled', 'expired', 'refund_pending', 'partially_refunded', 'refunded', 'disputed', 'reversed'] as const;

/**
 * Carrier codes in words (CreateShipmentRequest). Phase B49: "another courier" is any courier booked
 * outside the platform; the test courier moves no parcel. No real courier is connected yet.
 */
export const CARRIER_LABELS: Record<string, string> = {
  store_pickup: 'Store pickup',
  local_delivery: 'Own delivery (rider)',
  manual_courier: 'Another courier',
  test_courier: 'Test courier (no real parcel)',
  mock_courier: 'Test courier (no real carrier connected)',
};

export const PAYMENT_METHOD_LABELS: Record<string, string> = {
  cod: 'Cash on delivery',
  bank_transfer: 'Bank transfer',
  mock_redirect: 'Test online payment (no real gateway connected)',
  // Phase B48: mobile wallets.
  jazzcash: 'JazzCash',
  easypaisa: 'Easypaisa',
  test_wallet: 'Test wallet (no real money)',
};

export type OrderItem = {
  id: number;
  product_name: string;
  sku: string | null;
  variant: Record<string, unknown> | null;
  quantity: number;
  unit_price_minor: number;
  discount_minor: number;
  tax_minor: number;
  line_total_minor: number;
  fulfillment_status: string;
};

/** Phase B46: the tax kept on an order (Module 29 §58). */
export type OrderTax = {
  label: string;
  prices_include_tax: boolean;
  exempt: boolean;
  exemption_reference: string | null;
  shipping_tax_minor: number;
  breakdown: { name: string; rate_bps: number; base_minor: number; tax_minor: number }[];
};

/** "17", "16.5" — a rate in basis points as a percentage. */
export function ratePercent(bps: number): string {
  return (bps / 100).toFixed(2).replace(/\.?0+$/, '');
}

export type Order = {
  id: string;
  order_number: string;
  status: string;
  payment_status: string;
  fulfillment_status: string;
  source: string;
  currency: string;
  subtotal_minor: number;
  discount_total_minor: number;
  tax_total_minor: number;
  /** Phase B46: the tax as charged (null: the store did not charge tax). */
  tax?: OrderTax | null;
  shipping_total_minor: number;
  grand_total_minor: number;
  /** Phase B34 (Module 12 §13): paid with store credit, and what was left for the payment. */
  store_credit_minor?: number;
  payable_minor?: number;
  /** Phase B33: none, return_requested, partially_returned, returned. */
  return_status?: string;
  /** The order this one replaces (made from a return). */
  replacement_for?: { id: string; order_number: string } | null;
  is_guest_order: boolean;
  guest_name?: string | null;
  guest_email?: string | null;
  guest_phone?: string | null;
  customer?: { id: string; name: string; email: string } | null;
  shipping_address: Record<string, unknown> | null;
  billing_address: Record<string, unknown> | null;
  notes: string | null;
  cancellation_reason: string | null;
  items?: OrderItem[];
  created_at: string;
  cancelled_at: string | null;
};

export type TimelineEvent = { event_type: string; from_status: string | null; to_status: string | null; reason: string | null; note: string | null; created_at: string };

export type Payment = {
  id: string;
  order?: { id: string; order_number: string } | null;
  method: string;
  /** Phase B48: jazzcash, easypaisa, simulator; null for cash and bank transfer. */
  provider?: string | null;
  status: string;
  amount_minor: number;
  currency: string;
  completed_at: string | null;
  failed_at: string | null;
  created_at: string | null;
};

export type PaymentBalance = {
  paid_amount_minor: number;
  refunded_amount_minor: number;
  refundable_amount_minor: number;
  // Phase B48: the online side.
  provider?: string | null;
  provider_label?: string | null;
  /** provider: refunded by the provider's API; portal: made in the provider's portal and recorded here; record: cash or bank. */
  refund_mode?: 'provider' | 'portal' | 'record';
  reconciliation_status?: string | null;
  attempts?: import('./payments').StaffAttempt[];
  reconciliation?: { id: string; kind: string; status: string; resolution_note: string | null; created_at: string }[];
};

export type PaymentTransaction = {
  type: string;
  status: string;
  amount_minor: number;
  currency: string;
  provider_transaction_reference: string | null;
  failure_code: string | null;
  failure_reason: string | null;
  created_at: string;
};

export type TrackingEvent = { status: string; description: string | null; location: string | null; source: string | null; occurred_at: string };

export type Shipment = {
  id: string;
  order?: { id: string; order_number: string } | null;
  carrier: string;
  status: string;
  tracking_number: string | null;
  shipping_cost_minor: number | null;
  currency: string | null;
  estimated_delivery_at: string | null;
  shipped_at: string | null;
  delivered_at: string | null;
  created_at: string | null;
  items?: { order_item_id: number; product_name: string; quantity: number }[];
  tracking_events?: TrackingEvent[];
  // Phase B49: couriers, labels, cash on delivery, returns.
  direction?: 'outbound' | 'return';
  carrier_label?: string;
  carrier_name?: string | null;
  carrier_reference?: string | null;
  tracking_url?: string | null;
  weight_grams?: number | null;
  pieces?: number;
  cod_amount_minor?: number;
  cod_status?: 'none' | 'pending' | 'to_remit' | 'remitted';
  cod_remitted_at?: string | null;
  cod_remittance_reference?: string | null;
  booking_status?: 'booking' | 'requesting' | 'booked' | 'failed' | 'unknown' | null;
  booking_error?: string | null;
  label_source?: 'courier' | 'platform' | null;
  last_polled_at?: string | null;
  restocked_at?: string | null;
  actions?: { book: boolean; cancel: boolean; refresh: boolean; restock: boolean; set_tracking: boolean };
};

/** Who placed the order, in words. */
export function orderCustomer(order: Pick<Order, 'is_guest_order' | 'guest_name' | 'customer'>): string {
  if (order.customer) return order.customer.name;
  if (order.is_guest_order) return `${order.guest_name ?? 'Guest'} (guest)`;

  return 'Customer';
}

/** An address snapshot as lines of text. Its keys are whatever checkout stored; empty values are left out. */
export function addressLines(address: Record<string, unknown> | null | undefined): string[] {
  if (!address) return [];

  return Object.entries(address)
    .filter(([, value]) => (typeof value === 'string' || typeof value === 'number') && String(value).trim() !== '')
    .map(([, value]) => String(value));
}
