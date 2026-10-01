import { describe, expect, it } from 'vitest';
import { accessFrom, type AuthProps } from './access';
import { activeHref, breadcrumbsFor, LOCKED_REASON, navigationFor, type NavGroup } from './adminNav';

const user = { id: '01JUSER', name: 'Sara', email: 'sara@example.com' };
const store = { id: 5, name: 'Ittar Waly', slug: 'ittar-waly' };

function auth(overrides: Partial<AuthProps> = {}): AuthProps {
  return { user, activeStore: store, permissions: [], is_owner: false, features: {}, package: { code: 'basic', name: 'Basic' }, stores: [store], ...overrides };
}

const hrefs = (props: AuthProps) => navigationFor(accessFrom(props)).flatMap((group) => group.items.map((item) => item.href));

describe('access', () => {
  it('lets an owner do everything and anyone else only what the role holds', () => {
    expect(accessFrom(auth({ is_owner: true })).can('payments.refund')).toBe(true);

    const staff = accessFrom(auth({ permissions: ['orders.view'] }));
    expect(staff.can('orders.view')).toBe(true);
    expect(staff.can('orders.cancel')).toBe(false);
    expect(staff.can(['orders.cancel', 'orders.view'])).toBe(true); // any one of them
  });

  it('tells an included feature from one the package leaves out and one it does not mention', () => {
    const access = accessFrom(auth({ features: { 'theme.custom_css': false, 'orders.basic': true } }));

    expect(access.feature('orders.basic')).toBe(true);
    expect(access.feature('theme.custom_css')).toBe(false);
    expect(access.feature('unknown.feature')).toBeUndefined();
  });
});

describe('navigation', () => {
  it('shows a staff member only the pages the role opens', () => {
    const shown = hrefs(auth({ permissions: ['orders.view', 'products.view'] }));

    expect(shown).toContain('/orders');
    expect(shown).toContain('/products');
    expect(shown).toContain('/categories'); // viewable with products.view
    expect(shown).not.toContain('/payments');
    expect(shown).not.toContain('/billing');
    expect(shown).not.toContain('/settings/audit-log');
    expect(shown).toContain('/security'); // the user's own account, always
    expect(shown.some((href) => href.startsWith('/super-admin'))).toBe(false);
  });

  it('shows an owner every store page and no platform page', () => {
    const shown = hrefs(auth({ is_owner: true }));

    expect(shown).toEqual(expect.arrayContaining(['/orders', '/products', '/billing', '/settings', '/backups', '/team/roles']));
    expect(shown.some((href) => href.startsWith('/super-admin'))).toBe(false);
  });

  it('keeps the platform section apart and only for platform staff', () => {
    const groups = navigationFor(accessFrom(auth({ user: { ...user, is_platform_staff: true }, activeStore: null, stores: [] })));

    expect(groups.map((group) => group.label)).toEqual(['Platform administration', 'Account']);
    expect(groups[0].platform).toBe(true);
    expect(groups[0].items.every((item) => item.href.startsWith('/super-admin'))).toBe(true);
  });

  it('closes every entry except Security while two-step sign-in is owed', () => {
    const groups = navigationFor(accessFrom(auth({ is_owner: true, mfa_enrollment_required: true })));
    const items = groups.flatMap((group) => group.items);

    expect(items.filter((item) => item.state === 'open').map((item) => item.href)).toEqual(['/security']);
    expect(items.find((item) => item.href === '/orders')).toMatchObject({ state: 'locked', reason: LOCKED_REASON });
  });

  it('marks an entry of a feature the package leaves out as unavailable, with the package named', () => {
    const groups: NavGroup[] = [{ label: 'Extras', items: [{ href: '/extra', label: 'Extra', description: '', feature: 'extra.enabled' }, { href: '/plain', label: 'Plain', description: '' }] }];
    const items = navigationFor(accessFrom(auth({ is_owner: true, features: { 'extra.enabled': false } })), groups)[0].items;

    expect(items[0]).toMatchObject({ state: 'not_in_package', reason: 'Not included in your Basic package' });
    expect(items[1].state).toBe('open');
    // A feature the package does not mention is not treated as missing.
    expect(navigationFor(accessFrom(auth({ is_owner: true })), groups)[0].items[0].state).toBe('open');
  });

  it('finds the entry a path belongs to and its breadcrumb trail', () => {
    const groups = navigationFor(accessFrom(auth({ is_owner: true })));

    expect(activeHref(groups, '/team/roles')).toBe('/team/roles'); // not /team
    expect(activeHref(groups, '/products/01JABC')).toBe('/products');
    expect(activeHref(groups, '/nowhere')).toBeNull();
    expect(breadcrumbsFor(groups, '/products/01JABC')).toEqual([{ label: 'Dashboard', href: '/' }, { label: 'Catalog' }, { label: 'Products', href: '/products' }]);
    expect(breadcrumbsFor(groups, '/products').at(-1)).toEqual({ label: 'Products', href: undefined });
  });
});
