/**
 * Words for the keys the API uses. The API decides which values exist;
 * these only name them for people. A key without a name here is shown
 * as it is, in words (see `humanize`), never dropped.
 */
import type { Option } from '@/Components/ui/Form';
import { humanize } from '@/Components/ui/Badge';

/** The date ranges the reports API accepts (DateRangeRequest). */
export const DATE_FILTERS: Option[] = [
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: 'last_7_days', label: 'Last 7 days' },
  { value: 'last_30_days', label: 'Last 30 days' },
  { value: 'this_week', label: 'This week' },
  { value: 'last_week', label: 'Last week' },
  { value: 'this_month', label: 'This month' },
  { value: 'last_month', label: 'Last month' },
  { value: 'this_quarter', label: 'This quarter' },
  { value: 'this_year', label: 'This year' },
  { value: 'custom', label: 'Custom dates' },
];

export const USAGE_LABELS: Record<string, string> = {
  max_products: 'Products',
  max_staff_accounts: 'Team seats',
  max_monthly_orders: 'Orders this month',
  max_storage_gb: 'Storage (GB)',
};

export const FEATURE_LABELS: Record<string, string> = {
  'products.variants': 'Product variants',
  'inventory.advanced': 'Advanced inventory',
  'inventory.multi_warehouse': 'More than one warehouse',
  'reports.advanced': 'Advanced reports',
  'advanced_analytics.enabled': 'Advanced analytics',
  'loyalty.points': 'Loyalty points',
  'ai.recommendations': 'AI recommendations',
  'ai.chatbot': 'AI chatbot',
  'integrations.advanced': 'Advanced integrations',
  'integrations.api': 'Developer API',
  'custom_roles.enabled': 'Custom roles',
  'marketing.advanced': 'Advanced marketing',
  'marketing.basic': 'Campaigns and segments',
  'pwa.enabled': 'Installable store (PWA)',
  'orders.basic': 'Orders',
  'wishlist.basic': 'Wishlist',
  'payment.cod': 'Cash on delivery',
  'payment.bank_transfer': 'Bank transfer',
  'payment.online': 'Online payment',
  'shipping.basic': 'Shipping',
  'promotions.basic': 'Promotions and coupons',
  'notifications.basic': 'Notifications',
  'analytics.basic': 'Dashboard and reports',
  'seo.basic': 'SEO and content pages',
  'domains.custom_domain': 'Custom domain',
  'theme.custom_css': 'Custom CSS',
  'products.basic': 'Products',
};

export function labelFor(map: Record<string, string>, key: string): string {
  return map[key] ?? humanize(key);
}

export function options(values: readonly string[], labels: Record<string, string> = {}): Option[] {
  return values.map((value) => ({ value, label: labels[value] ?? humanize(value) }));
}
