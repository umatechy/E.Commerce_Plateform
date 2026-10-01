import type { Access } from './access';

/**
 * The admin navigation (Phase B31, gap G6): every admin page that exists
 * (routes/web.php), grouped the way a store is run.
 *
 * An entry names the permissions that open its page (any one of them)
 * and, where the page belongs to a package feature, that feature. The
 * menu hides what a user may not open and shows as unavailable what the
 * package does not include. That is a convenience only: each page's API
 * enforces both, server-side, on every call.
 */
export type NavItem = {
  href: string;
  label: string;
  description: string;
  /** Any one of these permissions opens the page. Absent: every signed-in staff user. */
  permissions?: string[];
  /** A package feature the whole page depends on. */
  feature?: string;
};

export type NavGroup = { label: string; items: NavItem[] };

export const storeNav: NavGroup[] = [
  {
    label: 'Sell',
    items: [
      { href: '/orders', label: 'Orders', description: 'Orders, their status, payments and deliveries.', permissions: ['orders.view'] },
      { href: '/payments', label: 'Payments', description: 'Payments, confirmations and refunds.', permissions: ['payments.view'] },
      { href: '/shipments', label: 'Shipments', description: 'Deliveries and their tracking.', permissions: ['shipments.view'] },
      { href: '/customers', label: 'Customers', description: 'Customer figures for your store.', permissions: ['analytics.view'] },
    ],
  },
  {
    label: 'Catalog',
    items: [
      { href: '/products', label: 'Products', description: 'Products, prices, variants and images.', permissions: ['products.view'] },
      { href: '/categories', label: 'Categories', description: 'The category tree of your store.', permissions: ['categories.manage', 'products.view'] },
      { href: '/brands', label: 'Brands', description: 'The brands you sell.', permissions: ['brands.manage', 'products.view'] },
      { href: '/attributes', label: 'Attributes', description: 'Options such as size and colour.', permissions: ['attributes.manage', 'products.view'] },
      { href: '/inventory', label: 'Inventory', description: 'Stock levels, adjustments and movements.', permissions: ['inventory.view'] },
      { href: '/warehouses', label: 'Warehouses', description: 'Where your stock is kept.', permissions: ['warehouses.manage', 'inventory.view'] },
    ],
  },
  {
    label: 'Marketing',
    items: [
      { href: '/promotions', label: 'Promotions', description: 'Discounts and coupon codes.', permissions: ['promotions.view'] },
      { href: '/campaigns', label: 'Campaigns', description: 'Email campaigns to your customers.', permissions: ['marketing.view'] },
      { href: '/segments', label: 'Segments', description: 'Groups of customers for campaigns.', permissions: ['marketing.view'] },
      { href: '/notifications', label: 'Messages', description: 'Sent messages and their templates.', permissions: ['notifications.view'] },
    ],
  },
  {
    label: 'Storefront',
    items: [
      { href: '/storefront/theme', label: 'Theme', description: 'Colours, branding and home page sections.', permissions: ['theme.view'] },
      { href: '/content/pages', label: 'Pages', description: 'Content pages such as About or Returns.', permissions: ['seo.view'] },
      { href: '/content/seo', label: 'SEO', description: 'Search engine titles and descriptions.', permissions: ['seo.view'] },
      { href: '/content/redirects', label: 'Redirects', description: 'Send old addresses to new ones.', permissions: ['seo.view'] },
      { href: '/domains', label: 'Domains', description: 'Your store addresses.', permissions: ['domains.view'] },
      { href: '/shipping', label: 'Shipping setup', description: 'Zones, methods and rates.', permissions: ['shipping_config.manage'] },
    ],
  },
  {
    label: 'Insights',
    items: [
      { href: '/reports', label: 'Reports', description: 'Sales, products, payments and more.', permissions: ['analytics.view'] },
      { href: '/store-health', label: 'Store health', description: 'Health checks for your store.', permissions: ['store_health.view'] },
    ],
  },
  {
    label: 'Store',
    items: [
      { href: '/team', label: 'Team', description: 'Invite staff, assign roles, suspend or remove members.', permissions: ['users.view'] },
      { href: '/team/roles', label: 'Roles', description: 'What each role may do.', permissions: ['roles.view'] },
      { href: '/billing', label: 'Billing', description: 'Your package, subscription and invoices.', permissions: ['billing.view'] },
      { href: '/settings', label: 'Settings', description: 'Currency, timezone and language.', permissions: ['settings.view'] },
      { href: '/settings/audit-log', label: 'Audit log', description: 'Who did what in your store.', permissions: ['audit.view'] },
      { href: '/settings/developer', label: 'Developer', description: 'API keys and webhooks.', permissions: ['developer_platform.view'] },
      { href: '/backups', label: 'Backups', description: 'Backups of your store data.', permissions: ['backups.view'] },
      { href: '/support', label: 'Support', description: 'Customer tickets and your requests to the platform.', permissions: ['support.view', 'support.reply', 'support.manage', 'support.platform'] },
    ],
  },
];

/** The signed-in user's own account. Always reachable, also while the admin is closed for two-step sign-in. */
export const accountNav: NavItem = { href: '/security', label: 'Security', description: 'Two-step sign-in for your account.' };

/** Umar Techy Super Admin: pages for platform staff only. The server refuses everyone else, whatever the menu shows. */
export const platformNav: NavGroup = {
  label: 'Platform administration',
  items: [
    { href: '/super-admin', label: 'Platform overview', description: 'Stores, orders and payments across the platform.' },
    { href: '/super-admin/stores', label: 'Stores', description: 'Every store, its package and its health.' },
    { href: '/super-admin/users', label: 'Users', description: 'Every account on the platform.' },
    { href: '/super-admin/packages', label: 'Packages', description: 'Packages and what they include.' },
    { href: '/super-admin/billing', label: 'Platform billing', description: 'Prices, invoices and payments received.' },
    { href: '/super-admin/monitoring', label: 'Monitoring', description: 'Infrastructure, store health and queues.' },
    { href: '/super-admin/audit-log', label: 'Platform audit log', description: 'The audit trail of every store and the platform.' },
    { href: '/super-admin/catalog', label: 'Themes & domains', description: 'Themes, domains and developer applications.' },
    { href: '/super-admin/settings', label: 'Platform settings', description: 'Platform-wide configuration.' },
    { href: '/super-admin/backups', label: 'Platform backups', description: 'Backups, restore rehearsals and restore requests.' },
    { href: '/super-admin/support', label: 'Platform support', description: 'Requests from every store to the platform.' },
  ],
};

export type NavEntryState = 'open' | 'locked' | 'not_in_package';
export type NavEntry = NavItem & { state: NavEntryState; reason?: string };
export type ResolvedGroup = { label: string; platform: boolean; items: NavEntry[] };

export const LOCKED_REASON = 'Turn on two-step sign-in first';

function resolve(item: NavItem, access: Access, alwaysOpen = false): NavEntry {
  // Until two-step sign-in is on, the server sends every other page back
  // to Security. Those entries are shown closed instead of bouncing.
  if (access.locked && !alwaysOpen) return { ...item, state: 'locked', reason: LOCKED_REASON };
  if (item.feature !== undefined && access.feature(item.feature) === false) {
    return { ...item, state: 'not_in_package', reason: `Not included in your ${access.packageName ?? 'current'} package` };
  }

  return { ...item, state: 'open' };
}

/**
 * The menu for this user: Store Admin groups for a user with a store,
 * the platform group for platform staff, and the user's own account.
 * A page the user holds no permission for is left out.
 */
export function navigationFor(access: Access, store: NavGroup[] = storeNav): ResolvedGroup[] {
  const groups: ResolvedGroup[] = [];

  if (access.hasStore) {
    for (const group of store) {
      const items = group.items
        .filter((item) => item.permissions === undefined || access.can(item.permissions))
        .map((item) => resolve(item, access));
      if (items.length > 0) groups.push({ label: group.label, platform: false, items });
    }
  }
  if (access.isPlatformStaff) {
    groups.push({ label: platformNav.label, platform: true, items: platformNav.items.map((item) => resolve(item, access)) });
  }
  groups.push({ label: 'Account', platform: false, items: [resolve(accountNav, access, true)] });

  return groups;
}

/** The entry whose page the path belongs to: the longest href that is the path or a parent of it. */
export function activeHref(groups: ResolvedGroup[], path: string): string | null {
  let best: string | null = null;
  for (const item of groups.flatMap((group) => group.items)) {
    const matches = path === item.href || path.startsWith(`${item.href}/`);
    if (matches && (best === null || item.href.length > best.length)) best = item.href;
  }

  return best;
}

/** Breadcrumb trail for a path: Dashboard, the entry's group, the entry. */
export function breadcrumbsFor(groups: ResolvedGroup[], path: string): { label: string; href?: string }[] {
  const href = activeHref(groups, path);
  if (href === null) return [];
  const group = groups.find((g) => g.items.some((item) => item.href === href));
  const item = group?.items.find((i) => i.href === href);
  if (!group || !item) return [];

  return [{ label: 'Dashboard', href: '/' }, { label: group.label }, { label: item.label, href: path === href ? undefined : href }];
}
