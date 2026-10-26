import type { IconName } from '@/Components/ui/Icon';
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
  /** Phase B51: only the store's Owner (closing the store is not a permission others can be given). */
  ownerOnly?: boolean;
};

/**
 * UI-26 — a section's colour (set as --tone by `toneClass`): the sidebar
 * chip, the page's eyebrow, the section tabs and the card headings use it,
 * so the colour says which part of the store a page belongs to.
 */
export type Tone = 'brand' | 'blue' | 'emerald' | 'violet' | 'pink' | 'amber' | 'cyan' | 'teal' | 'slate';

/** The class that sets a section's colour (app.css: .tone-*). */
export function toneClass(tone: Tone | undefined): string {
  return `tone-${tone ?? 'brand'}`;
}

/**
 * A section of the menu. UI-26: the sidebar shows the section once (its
 * icon and colour, opening its first page) and its pages only while it is
 * open, and every page of the section shows the others as tabs. A `hub`
 * section (Settings) opens one page that lists the others instead of a
 * long list.
 */
export type NavGroup = { label: string; icon?: IconName; tone?: Tone; hub?: string; items: NavItem[] };

export const storeNav: NavGroup[] = [
  {
    label: 'Orders',
    icon: 'cart',
    tone: 'blue',
    items: [
      { href: '/orders', label: 'Orders', description: 'Orders, their status, payments and deliveries.', permissions: ['orders.view'] },
      { href: '/shipments', label: 'Shipments', description: 'Deliveries and their tracking.', permissions: ['shipments.view'] },
      { href: '/returns', label: 'Returns', description: 'Return requests, inspection, refunds and replacements.', permissions: ['returns.view'] },
      { href: '/payments', label: 'Payments', description: 'Payments received, confirmations and refunds.', permissions: ['payments.view'] },
    ],
  },
  {
    label: 'Products',
    icon: 'box',
    tone: 'emerald',
    items: [
      { href: '/products', label: 'Products', description: 'Products, prices, variants and images.', permissions: ['products.view'] },
      { href: '/inventory', label: 'Stock', description: 'How many of each product you have, and every change to it.', permissions: ['inventory.view'] },
      { href: '/categories', label: 'Categories', description: 'The category tree of your store.', permissions: ['categories.manage', 'products.view'] },
      { href: '/collections', label: 'Collections', description: 'Hand-picked or rule-based groups of products.', permissions: ['collections.manage', 'products.view'] },
      { href: '/brands', label: 'Brands', description: 'The brands you sell.', permissions: ['brands.manage', 'products.view'] },
      { href: '/reviews', label: 'Reviews', description: 'Ratings and reviews from customers who bought.', permissions: ['reviews.manage'], feature: 'reviews.product' },
      { href: '/badges', label: 'Badges', description: 'Labels on product cards: automatic and your own.', permissions: ['collections.manage', 'products.view'] },
      { href: '/attributes', label: 'Product options', description: 'Options such as size and colour.', permissions: ['attributes.manage', 'products.view'] },
      { href: '/warehouses', label: 'Stock locations', description: 'Where your stock is kept.', permissions: ['warehouses.manage', 'inventory.view'] },
    ],
  },
  {
    label: 'Customers',
    icon: 'users',
    tone: 'violet',
    items: [
      { href: '/customers', label: 'Customers', description: 'Your customers, their orders, groups, tags and notes.', permissions: ['customers.view'] },
      { href: '/segments', label: 'Customer groups', description: 'Groups of customers, for campaigns and offers.', permissions: ['marketing.view'] },
      { href: '/support', label: 'Support', description: 'Customer tickets and your requests to Umar Techy.', permissions: ['support.view', 'support.reply', 'support.manage', 'support.platform'] },
    ],
  },
  {
    label: 'Marketing',
    icon: 'megaphone',
    tone: 'pink',
    items: [
      { href: '/promotions', label: 'Discounts', description: 'Discounts and coupon codes.', permissions: ['promotions.view'] },
      { href: '/campaigns', label: 'Campaigns', description: 'Email campaigns to your customers.', permissions: ['marketing.view'] },
      { href: '/notifications', label: 'Customer messages', description: 'Messages sent to customers: email, WhatsApp & SMS, and their templates.', permissions: ['notifications.view'] },
      { href: '/affiliates', label: 'Affiliates', description: 'People who promote your store and earn commission.', permissions: ['affiliates.view'], feature: 'affiliate.programs' },
    ],
  },
  {
    label: 'Online store',
    icon: 'palette',
    tone: 'amber',
    items: [
      { href: '/storefront/theme', label: 'Theme', description: 'Colours, logo and home page sections.', permissions: ['theme.view'] },
      { href: '/content/pages', label: 'Pages', description: 'Content pages such as About or Returns.', permissions: ['seo.view'] },
      { href: '/domains', label: 'Domains', description: 'Your store addresses.', permissions: ['domains.view'] },
      { href: '/storefront/app', label: 'Mobile app', description: 'Your shop on customers’ home screens, like an app.', permissions: ['theme.view'], feature: 'pwa.enabled' },
      { href: '/content/seo', label: 'SEO', description: 'Search engine titles and descriptions.', permissions: ['seo.view'] },
      { href: '/content/redirects', label: 'Redirects', description: 'Send old addresses to new ones.', permissions: ['seo.view'] },
    ],
  },
  {
    label: 'Reports',
    icon: 'chart',
    tone: 'cyan',
    items: [
      { href: '/reports', label: 'Reports', description: 'Sales, products, payments and more.', permissions: ['analytics.view'] },
      { href: '/store-health', label: 'Store health', description: 'Health checks for your store.', permissions: ['store_health.view'] },
    ],
  },
  {
    label: 'Settings',
    icon: 'settings',
    tone: 'teal',
    hub: '/settings',
    items: [
      { href: '/settings/general', label: 'Store details', description: 'Name, contact, currency, timezone, language and checkout.', permissions: ['settings.view'] },
      { href: '/settings/payments', label: 'Payment methods', description: 'Cash on delivery, bank transfer, JazzCash and Easypaisa.', permissions: ['payments.view', 'payments.provider_config'] },
      { href: '/shipping', label: 'Delivery', description: 'Where you deliver, delivery methods and charges.', permissions: ['shipping_config.manage'] },
      { href: '/settings/tax', label: 'Tax', description: 'Tax classes, rates and how tax is charged.', permissions: ['tax.manage', 'settings.view'] },
      { href: '/team', label: 'Team', description: 'Invite staff, assign roles, suspend or remove members.', permissions: ['users.view'] },
      { href: '/team/roles', label: 'Roles', description: 'What each role may do.', permissions: ['roles.view'] },
      { href: '/billing', label: 'Package & billing', description: 'Your package, subscription and invoices.', permissions: ['billing.view'] },
      { href: '/backups', label: 'Backups', description: 'Backups of your store data.', permissions: ['backups.view'] },
      { href: '/apps', label: 'Apps', description: 'Connect your store to other services from the marketplace.', permissions: ['developer_platform.view'] },
      { href: '/settings/developer', label: 'Developer', description: 'API keys and webhooks.', permissions: ['developer_platform.view'] },
      { href: '/settings/audit-log', label: 'Audit log', description: 'Who did what in your store.', permissions: ['audit.view'] },
      { href: '/settings/close-store', label: 'Close store', description: 'Download your data, close or reopen the store.', ownerOnly: true },
    ],
  },
];

/** The signed-in user's own account. Always reachable, also while the admin is closed for two-step sign-in. */
export const accountNav: NavItem = { href: '/security', label: 'Security', description: 'Two-step sign-in for your account.' };

/** Phase B58: a reseller's own portal — its clients' stores, commissions and payouts. */
export const partnerNav: NavItem = { href: '/partner', label: 'Partner portal', description: 'Your clients’ stores, your commissions and payouts.' };

/**
 * Umar Techy Super Admin: pages for platform staff only. The server refuses
 * everyone else, whatever the menu shows. UI-28: grouped like the store menu
 * — six sections, each with its colour, only the open one unfolded.
 */

export const platformNav: NavGroup[] = [
  {
    label: 'Overview',
    icon: 'activity',
    tone: 'violet',
    items: [
      { href: '/super-admin', label: 'Platform overview', description: 'Stores, orders and payments across the platform.' },
      { href: '/super-admin/monitoring', label: 'Monitoring', description: 'Infrastructure, store health and queues.' },
    ],
  },
  {
    label: 'Stores & users',
    icon: 'store',
    tone: 'blue',
    items: [
      { href: '/super-admin/stores', label: 'Stores', description: 'Every store, its package and its health.' },
      { href: '/super-admin/users', label: 'Users', description: 'Every account on the platform.' },
      { href: '/super-admin/resellers', label: 'Resellers', description: 'Partners who sell Umar Techy: tiers, their stores, commissions and payouts.' },
    ],
  },
  {
    label: 'Plans & payments',
    icon: 'receipt',
    tone: 'emerald',
    items: [
      { href: '/super-admin/packages', label: 'Packages', description: 'Packages and what they include.' },
      { href: '/super-admin/billing', label: 'Platform billing', description: 'Prices, invoices and payments received.' },
      { href: '/super-admin/payments', label: 'Payment providers', description: 'JazzCash, Easypaisa: live access, health, lookups and findings.' },
    ],
  },
  {
    label: 'Messages & support',
    icon: 'chat',
    tone: 'pink',
    items: [
      { href: '/super-admin/messaging', label: 'WhatsApp & SMS', description: 'Alert account and template, SMS providers’ live access, delivery.' },
      { href: '/super-admin/support', label: 'Platform support', description: 'Requests from every store to the platform.' },
      { href: '/super-admin/help', label: 'Help center', description: 'Help articles, their review, feedback, searches and release notes.' },
    ],
  },
  {
    label: 'Themes & apps',
    icon: 'palette',
    tone: 'amber',
    items: [
      { href: '/super-admin/catalog', label: 'Themes & domains', description: 'Themes, domains and developer applications.' },
      { href: '/super-admin/starter-templates', label: 'Starter templates', description: 'Ready-made store structures new stores start from.' },
      { href: '/super-admin/marketplace', label: 'Marketplace', description: 'Apps stores can install: review, publish and the kill switch.' },
    ],
  },
  {
    label: 'Security & data',
    icon: 'shield',
    tone: 'teal',
    items: [
      { href: '/super-admin/security', label: 'Security', description: 'Sign-in alerts, staff roles and sessions, incidents, emergency controls.' },
      { href: '/super-admin/audit-log', label: 'Platform audit log', description: 'The audit trail of every store and the platform.' },
      { href: '/super-admin/backups', label: 'Platform backups', description: 'Backups, restore rehearsals and restore requests.' },
      { href: '/super-admin/settings', label: 'Platform settings', description: 'Platform-wide configuration.' },
    ],
  },
];

export type NavEntryState = 'open' | 'locked' | 'not_in_package';
export type NavEntry = NavItem & { state: NavEntryState; reason?: string };
export type ResolvedGroup = { label: string; platform: boolean; icon?: IconName; tone?: Tone; hub?: string; items: NavEntry[] };

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
 * The menu for this user: Store Admin sections for a user with a store,
 * the platform sections for platform staff, and the user's own account.
 * A page the user holds no permission for is left out.
 */
export function navigationFor(access: Access, store: NavGroup[] = storeNav): ResolvedGroup[] {
  const groups: ResolvedGroup[] = [];

  if (access.hasStore) {
    for (const group of store) {
      const items = group.items
        .filter((item) => (item.ownerOnly ? access.isOwner : item.permissions === undefined || access.can(item.permissions)))
        .map((item) => resolve(item, access));
      if (items.length > 0) groups.push({ label: group.label, platform: false, icon: group.icon, tone: group.tone, hub: group.hub, items });
    }
  }
  if (access.isPlatformStaff) {
    for (const group of platformNav) {
      groups.push({ label: group.label, platform: true, icon: group.icon, tone: group.tone, items: group.items.map((item) => resolve(item, access)) });
    }
  }
  groups.push({ label: 'Account', platform: false, icon: 'user', tone: 'slate', items: [...(access.isReseller ? [resolve(partnerNav, access, true)] : []), resolve(accountNav, access, true)] });

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

/** UI-26: the section a path belongs to — by its entry, or because it is the section's hub page. */
export function sectionFor(groups: ResolvedGroup[], path: string): ResolvedGroup | null {
  const hub = groups.find((group) => group.hub === path);
  if (hub) return hub;
  const href = activeHref(groups, path);

  return href === null ? null : groups.find((group) => group.items.some((item) => item.href === href)) ?? null;
}

/** Where a section leads: its hub, or its first page the user can open. */
export function sectionHref(group: ResolvedGroup): string | null {
  return group.hub ?? group.items.find((item) => item.state === 'open')?.href ?? null;
}

/** Breadcrumb trail for a path: Dashboard, the entry's section (a link to it), the entry. */
export function breadcrumbsFor(groups: ResolvedGroup[], path: string): { label: string; href?: string }[] {
  const href = activeHref(groups, path);
  if (href === null) return [];
  const group = groups.find((g) => g.items.some((item) => item.href === href));
  const item = group?.items.find((i) => i.href === href);
  if (!group || !item) return [];
  const sectionLink = sectionHref(group);
  // A section named like its page (Orders › Orders) is said once.
  const section = group.label === item.label ? [] : [{ label: group.label, href: sectionLink !== null && sectionLink !== path ? sectionLink : undefined }];

  return [{ label: 'Dashboard', href: '/' }, ...section, { label: item.label, href: path === href ? undefined : href }];
}

/** UI-2: the icon of each menu entry (by its address); an entry without one shows none. */
export const NAV_ICONS: Record<string, IconName> = {
  '/': 'grid',
  '/orders': 'cart', '/payments': 'creditCard', '/returns': 'undo', '/shipments': 'truck', '/customers': 'users',
  '/products': 'box', '/collections': 'layers', '/badges': 'award', '/reviews': 'star', '/categories': 'folder', '/brands': 'tag', '/attributes': 'sliders', '/inventory': 'database', '/warehouses': 'warehouse',
  '/promotions': 'percent', '/campaigns': 'megaphone', '/affiliates': 'handshake', '/segments': 'users', '/notifications': 'chat',
  '/storefront/theme': 'palette', '/content/pages': 'file', '/content/seo': 'search', '/content/redirects': 'link', '/domains': 'globe', '/storefront/app': 'phone', '/shipping': 'truck',
  '/reports': 'chart', '/store-health': 'activity',
  '/team': 'users', '/team/roles': 'key', '/billing': 'receipt', '/settings': 'settings', '/settings/general': 'store', '/settings/payments': 'wallet', '/settings/tax': 'percent', '/settings/audit-log': 'clock',
  '/apps': 'puzzle', '/settings/developer': 'code', '/backups': 'database', '/settings/close-store': 'lock', '/support': 'lifebuoy',
  '/partner': 'handshake', '/security': 'shieldCheck',
  '/super-admin': 'grid', '/super-admin/stores': 'store', '/super-admin/users': 'users', '/super-admin/packages': 'box', '/super-admin/billing': 'receipt',
  '/super-admin/payments': 'wallet', '/super-admin/messaging': 'bell', '/super-admin/security': 'shield', '/super-admin/monitoring': 'activity', '/super-admin/audit-log': 'clock',
  '/super-admin/catalog': 'palette', '/super-admin/starter-templates': 'layers', '/super-admin/settings': 'settings', '/super-admin/backups': 'database', '/super-admin/support': 'lifebuoy',
  '/super-admin/marketplace': 'puzzle', '/super-admin/resellers': 'handshake', '/super-admin/help': 'question',
};
