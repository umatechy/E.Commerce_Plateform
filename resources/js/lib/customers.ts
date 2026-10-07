/**
 * Shapes of the staff customer API (Phase B32, Module 10):
 * StaffCustomerResource, the customer detail, notes, groups, tags,
 * activity and the import preview. The server computes every figure.
 */
/** Owner decision 2026-10-03 (Module 10 §87): groups, tags, import, export and merge are Business/Premium. */
export const ADVANCED_FEATURE = 'customers.advanced';

export const CUSTOMER_STATUSES = ['active', 'blocked', 'archived'] as const;
export type CustomerStatus = (typeof CUSTOMER_STATUSES)[number];

export const CUSTOMER_SORTS: { value: string; label: string }[] = [
  { value: 'newest', label: 'Newest first' },
  { value: 'oldest', label: 'Oldest first' },
  { value: 'name', label: 'Name' },
  { value: 'orders', label: 'Most orders' },
  { value: 'spent', label: 'Most spent' },
  { value: 'last_order', label: 'Latest order' },
];

export const SOURCE_LABELS: Record<string, string> = {
  registered: 'Registered on the storefront',
  staff: 'Added by staff',
  import: 'Imported',
};

export type CustomerRow = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  status: CustomerStatus;
  status_reason: string | null;
  status_changed_at: string | null;
  source: string | null;
  registered: boolean;
  email_verified: boolean;
  marketing_email_opt_in: boolean;
  tax_exempt?: boolean; // Phase B46
  tax_exemption_reference?: string | null;
  erased: boolean;
  /** Module 10 §56: this record was merged into another. */
  merged?: boolean;
  group?: { id: string; name: string } | null;
  tags?: { id: string; name: string }[];
  orders_count?: number;
  total_spent_minor?: number;
  last_order_at: string | null;
  created_at: string | null;
};

export type CustomerAddress = {
  label: string | null;
  name: string | null;
  phone: string | null;
  line1: string | null;
  line2: string | null;
  city: string | null;
  province: string | null;
  postal_code: string | null;
  country: string | null;
  is_default: boolean;
};

export type CustomerDetail = CustomerRow & {
  first_order_at: string | null;
  average_order_value_minor: number | null;
  addresses: CustomerAddress[];
  possible_duplicates: { id: string; name: string; email: string; reason: 'same_email' | 'same_phone' }[];
  merged_into?: { id: string; name: string; at: string | null } | null;
  merged_from?: { id: string; name: string; at: string }[];
};

export type CustomerNote = { id: string; body: string; author: string | null; is_yours: boolean; created_at: string };
export type CustomerGroup = { id: string; name: string; description: string | null; customers_count: number };
export type CustomerTag = { id: string; name: string; customers_count: number };

export type ActivityEvent = {
  type: string;
  at: string;
  by: string | null;
  by_type: string | null;
  details: Record<string, unknown>;
  order: { id: string; order_number: string } | null;
};

export type ImportRow = { row: number; name: string; email: string; status: 'ready' | 'existing' | 'invalid'; messages: string[] };
export type ImportPreview = { id: string; totals: { rows: number; ready: number; existing: number; invalid: number }; rows: ImportRow[]; columns: string[] };

/** What happened, in words, for the activity timeline. Unknown types are shown as they are named. */
export const ACTIVITY_LABELS: Record<string, string> = {
  'order.placed': 'Placed an order',
  'customer.registered': 'Created an account',
  'customer.created': 'Added to the store',
  'customer.updated': 'Details changed',
  'customer.blocked': 'Blocked',
  'customer.unblocked': 'Unblocked',
  'customer.archived': 'Archived',
  'customer.restored': 'Restored',
  'customer.merged_into': 'Merged into another customer record',
  'customer.merged_from': 'Another customer record was merged into this one',
  'customer.group_changed': 'Group changed',
  'customer.tags_changed': 'Tags changed',
  'customer.note_added': 'Note added',
  'customer.note_deleted': 'Note deleted',
  'customer.email_verification_requested': 'Asked to confirm email',
  'customer.email_verified': 'Confirmed email address',
  'customer.guest_orders_linked': 'Earlier guest orders joined the account',
  'customer.marketing_consent_changed': 'Marketing email choice changed',
  'customer.password_reset_requested': 'Asked for a password reset',
  'customer.password_reset': 'Reset password',
  'auth.login.succeeded': 'Signed in',
  'auth.login.refused': 'Sign-in refused',
  'auth.logout': 'Signed out',
  'privacy.customer_data_exported': 'Personal data exported',
  'privacy.customer_erased': 'Personal data erased',
};

/** The text of the CSV the import expects, as a starting point. */
export const IMPORT_TEMPLATE = 'name,email,phone,group,tags\nAyesha Khan,ayesha@example.com,+923001234567,Retail,VIP;Lahore\n';
