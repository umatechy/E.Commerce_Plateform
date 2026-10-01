/**
 * The admin pages that exist today (routes/web.php, auth group). Used by
 * the header navigation and the dashboard. Showing a link is a convenience
 * only: each page's API enforces permissions server-side.
 */
export type AdminNavItem = { href: string; label: string; description: string };

export const adminNav: AdminNavItem[] = [
  { href: '/orders', label: 'Orders', description: 'View orders, their status, and cancel where allowed.' },
  { href: '/inventory', label: 'Inventory', description: 'Stock levels and adjustments.' },
  { href: '/team', label: 'Team', description: 'Invite staff, assign roles, suspend or remove members.' },
  { href: '/billing', label: 'Billing', description: 'Your package, subscription and invoices.' },
  { href: '/store-health', label: 'Store health', description: 'Health checks for your store.' },
  { href: '/support', label: 'Support', description: 'Customer tickets and your requests to the platform.' },
  { href: '/security', label: 'Security', description: 'Two-step sign-in for your account.' },
];

/** Pages for platform staff only. The server refuses everyone else, whatever the menu shows. */
export const platformNav: AdminNavItem[] = [
  { href: '/super-admin/support', label: 'Platform support', description: 'Requests from every store to the platform.' },
  { href: '/super-admin/backups', label: 'Platform backups', description: 'Backups, restore rehearsals and restore requests.' },
];
