export type OrderSummary = {
  id: string;
  number: string;
  status: string;
  payment_status: string;
  fulfillment_status: string;
  currency: string;
  grand_total_minor: number;
  item_count: number;
  placed_at: string;
};

export type OrderDetail = OrderSummary & {
  /** Phase B34: the part paid with store credit. */
  store_credit_minor?: number;
  subtotal_minor: number;
  discount_total_minor: number;
  shipping_total_minor: number;
  tax_total_minor: number;
  cancellation_reason: string | null;
  shipping_address: Record<string, string | null> | null;
  billing_address: Record<string, string | null> | null;
  notes: string | null;
  items: { name: string; sku: string | null; variant: Record<string, string> | string | null; quantity: number; unit_price_minor: number; line_total_minor: number }[];
  payments: { method: string; status: string; amount_minor: number }[];
  shipments: { id: string; carrier: string | null; status: string; tracking_number: string | null; shipped_at: string | null; delivered_at: string | null; estimated_delivery_at: string | null }[];
};

/** Shopper-friendly wording for order status codes. */
export function statusLabel(status: string): string {
  const labels: Record<string, string> = {
    pending_confirmation: 'Awaiting confirmation',
    confirmed: 'Confirmed',
    processing: 'Processing',
    ready_to_fulfill: 'Being prepared',
    fulfilling: 'Being packed',
    shipped: 'Shipped',
    delivered: 'Delivered',
    completed: 'Completed',
    cancelled: 'Cancelled',
    return_requested: 'Return requested',
    refund_pending: 'Refund pending',
    refunded: 'Refunded',
  };

  return labels[status] ?? status.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

/** Payment method codes as shoppers know them. */
export function paymentMethodLabel(method: string): string {
  const labels: Record<string, string> = { cod: 'Cash on delivery', bank_transfer: 'Bank transfer', mock_redirect: 'Online payment' };

  return labels[method] ?? method.replace(/_/g, ' ');
}
