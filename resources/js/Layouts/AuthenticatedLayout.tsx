import { PropsWithChildren, useEffect, useMemo, useRef, useState } from 'react';
import BrandLogo from '@/Components/Brand';
import Icon from '@/Components/ui/Icon';
import CommandPalette, { paletteEntries } from '@/Components/CommandPalette';
import { Link, usePage } from '@inertiajs/react';
import { adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { accessFrom, type AuthProps } from '@/lib/access';
import { activeHref, navigationFor, NAV_ICONS, type NavEntry, type ResolvedGroup } from '@/lib/adminNav';
import { FOCUS_RING } from '@/Components/ui/Button';
import { toast, Toaster } from '@/Components/ui/toast';
import StepUpDialog from '@/Components/StepUpDialog';
import { HelpButton } from '@/Components/Help/HelpPanel';
import { setDisplayTimezone } from '@/lib/datetime';

/**
 * The admin shell (Phase B31, gap G6): sidebar navigation, top bar with
 * the store and the account, and the page.
 *
 * Reads the signed-in user, the active store and what the user may open
 * from Inertia's shared props (HandleInertiaRequests::share()).
 *
 * IMPORTANT: everything permission- or package-based here (hiding an
 * entry, showing one as unavailable) is a convenience only. The actual
 * enforcement is server-side, on every API call. Hiding a link never
 * substitutes for that.
 *
 * Store Admin and Umar Techy Super Admin are two separate sections of
 * the menu: store pages act on the active store only; platform pages are
 * shown to platform staff only and are marked as such.
 */
const SIDEBAR_PREFERENCE = 'admin.sidebar.collapsed';

function readCollapsed(): boolean {
  try {
    return window.localStorage.getItem(SIDEBAR_PREFERENCE) === '1';
  } catch {
    return false;
  }
}

function NavLink({ item, active, onNavigate }: { item: NavEntry; active: boolean; onNavigate?: () => void }) {
  // An entry the user cannot use right now is shown closed, with the
  // reason, instead of a link that would only bounce back.
  if (item.state !== 'open') {
    return (
      <span aria-disabled="true" title={item.reason} className="flex cursor-not-allowed items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm text-slate-400">
        {NAV_ICONS[item.href] && <Icon name={NAV_ICONS[item.href]} size={18} />}
        <span className="flex-1">{item.label}</span>
        <span className="text-[10px] uppercase tracking-wide">{item.state === 'locked' ? 'Locked' : 'Upgrade'}</span>
        <span className="sr-only">. {item.reason}</span>
      </span>
    );
  }

  return (
    <Link
      href={item.href}
      onClick={onNavigate}
      aria-current={active ? 'page' : undefined}
      className={`group flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm ${FOCUS_RING} ${active ? 'bg-indigo-50 font-medium text-indigo-800' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900'}`}
    >
      {NAV_ICONS[item.href] && <Icon name={NAV_ICONS[item.href]} size={18} className={active ? 'text-indigo-600' : 'text-slate-400 group-hover:text-slate-600'} />}
      <span>{item.label}</span>
    </Link>
  );
}

const FOLDED_PREFERENCE = 'admin.sidebar.folded';

function readFolded(): string[] {
  try {
    const value = JSON.parse(window.localStorage.getItem(FOLDED_PREFERENCE) ?? '[]');

    return Array.isArray(value) ? value.filter((v): v is string => typeof v === 'string') : [];
  } catch {
    return [];
  }
}

function SidebarNav({ groups, current, path, locked, onNavigate }: { groups: ResolvedGroup[]; current: string | null; path: string; locked: boolean; onNavigate?: () => void }) {
  // UI-2: groups fold away; the choice is remembered on this device.
  const [folded, setFolded] = useState<string[]>([]);
  useEffect(() => setFolded(readFolded()), []);
  function toggleFold(label: string) {
    setFolded((current) => {
      const next = current.includes(label) ? current.filter((l) => l !== label) : [...current, label];
      try {
        window.localStorage.setItem(FOLDED_PREFERENCE, JSON.stringify(next));
      } catch {
        // No storage: the choice lasts for this page only.
      }

      return next;
    });
  }

  return (
    <nav aria-label="Admin" className="flex-1 space-y-5 overflow-y-auto px-3 py-4">
      <div>
        {locked ? (
          <span aria-disabled="true" title="Turn on two-step sign-in first" className="block cursor-not-allowed rounded-md px-3 py-1.5 text-sm text-slate-400">
            Dashboard
          </span>
        ) : (
          <Link
            href="/"
            onClick={onNavigate}
            aria-current={path === '/' ? 'page' : undefined}
            className={`flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm ${FOCUS_RING} ${path === '/' ? 'bg-indigo-50 font-medium text-indigo-800' : 'text-slate-700 hover:bg-slate-100'}`}
          >
            <Icon name="grid" size={18} className={path === '/' ? 'text-indigo-600' : 'text-slate-400'} />
            <span>Dashboard</span>
          </Link>
        )}
      </div>
      {groups.map((group) => {
        const holdsCurrent = group.items.some((item) => item.href === current);
        const isFolded = !holdsCurrent && folded.includes(group.label);

        return (
          <div key={group.label} className={group.platform ? 'rounded-xl border border-violet-200 bg-violet-50/60 p-2' : ''}>
            <h2 className={`text-xs font-semibold uppercase tracking-wide ${group.platform ? 'text-violet-800' : 'text-slate-500'}`}>
              <button
                type="button"
                onClick={() => toggleFold(group.label)}
                aria-expanded={!isFolded}
                className={`flex w-full items-center justify-between rounded px-3 pb-1 text-left uppercase ${FOCUS_RING}`}
              >
                {group.label}
                <Icon name="chevronDown" size={14} className={`transition-transform motion-reduce:transition-none ${isFolded ? '-rotate-90' : ''}`} />
              </button>
            </h2>
            {group.platform && !isFolded && <p className="px-3 pb-1 text-[11px] text-violet-800">Umar Techy staff only. Acts across all stores.</p>}
            {!isFolded && (
              <ul className="space-y-0.5">
                {group.items.map((item) => (
                  <li key={item.href}>
                    <NavLink item={item} active={current === item.href} onNavigate={onNavigate} />
                  </li>
                ))}
              </ul>
            )}
          </div>
        );
      })}
    </nav>
  );
}

function StoreSwitcher({ auth }: { auth: AuthProps }) {
  const [busy, setBusy] = useState(false);
  const stores = auth.stores ?? [];

  if (stores.length <= 1) {
    return auth.activeStore ? (
      <span className="max-w-[12rem] truncate rounded bg-slate-100 px-2 py-1 text-sm text-slate-700" title="The store you are managing">
        {auth.activeStore.name}
      </span>
    ) : null;
  }

  // Switching is explicit and reloads the whole admin, so no figure or
  // list of the previous store stays on screen.
  function switchTo(value: string) {
    if (value === '' || Number(value) === auth.activeStore?.id) return;
    setBusy(true);
    adminFetch('/store/switch', { method: 'POST', body: { store_id: Number(value) } })
      .then(() => window.location.assign('/'))
      .catch((e) => {
        toast.error(adminErrorMessage(e, 'Could not switch store.'));
        setBusy(false);
      });
  }

  return (
    <label className="flex items-center gap-2 text-sm text-slate-600">
      <span className="hidden sm:inline">Store</span>
      <select
        aria-label="Store you are managing"
        value={auth.activeStore ? String(auth.activeStore.id) : ''}
        disabled={busy}
        onChange={(e) => switchTo(e.target.value)}
        className={`max-w-[11rem] rounded-md border border-slate-300 bg-white px-2 py-1 text-sm text-slate-900 ${FOCUS_RING}`}
      >
        {!auth.activeStore && <option value="">Choose a store…</option>}
        {stores.map((store) => (
          <option key={store.id} value={store.id}>
            {store.name}
          </option>
        ))}
      </select>
    </label>
  );
}

function AccountMenu({ auth, onLogout }: { auth: AuthProps; onLogout: () => void }) {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const close = (event: MouseEvent) => {
      if (box.current && !box.current.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);

    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  if (!auth.user) return <Link href="/login">Sign in</Link>;

  return (
    <div ref={box} className="relative" onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}>
      <button
        type="button"
        aria-haspopup="true"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        className={`flex max-w-[14rem] items-center gap-2 rounded-lg px-1.5 py-1 text-sm text-slate-700 hover:bg-slate-100 ${FOCUS_RING}`}
      >
        <span aria-hidden="true" className="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 text-xs font-bold text-white">
          {auth.user.name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase()}
        </span>
        <span className="hidden truncate sm:inline">{auth.user.name}</span>
        <Icon name="chevronDown" size={14} className="text-slate-400" />
      </button>
      {open && (
        <div className="absolute right-0 z-40 mt-1 w-56 rounded-md border border-slate-200 bg-white p-1 text-sm shadow-lg">
          <p className="truncate px-3 py-2 text-xs text-slate-500">{auth.user.email}</p>
          <Link href="/security" className={`flex items-center gap-2 rounded px-3 py-1.5 text-slate-700 hover:bg-slate-100 ${FOCUS_RING}`} onClick={() => setOpen(false)}>
            <Icon name="shieldCheck" size={16} className="text-slate-400" />Security
          </Link>
          <button type="button" onClick={onLogout} className={`flex w-full items-center gap-2 rounded px-3 py-1.5 text-left text-slate-700 hover:bg-slate-100 ${FOCUS_RING}`}>
            <Icon name="logOut" size={16} className="text-slate-400" />Log out
          </button>
        </div>
      )}
    </div>
  );
}

export default function AuthenticatedLayout({ children }: PropsWithChildren) {
  const page = usePage<{ auth: AuthProps }>();
  const { auth } = page.props;
  const path = page.url.split('?')[0];
  const access = useMemo(() => accessFrom(auth), [auth]);
  const groups = useMemo(() => navigationFor(access), [access]);
  const current = activeHref(groups, path);
  const locked = access.locked;

  const [collapsed, setCollapsed] = useState(false);
  const [drawer, setDrawer] = useState(false);
  const [palette, setPalette] = useState(false);
  const entries = useMemo(
    () =>
      paletteEntries(groups, NAV_ICONS, [
        { href: '/', label: 'Dashboard', hint: 'Your store at a glance', icon: 'grid', group: 'Go to' },
        ...(access.can('products.create') ? [{ href: '/products/new', label: 'Add a product', hint: 'Name, price, pictures and stock', icon: 'box' as const, group: 'Quick action' }] : []),
        ...(access.can('orders.create') ? [{ href: '/orders/new', label: 'Create an order', hint: 'For a customer, by phone or in person', icon: 'cart' as const, group: 'Quick action' }] : []),
      ]),
    [groups, access],
  );

  // UI-2: Ctrl+K / ⌘K opens "Go to…".
  useEffect(() => {
    if (!auth.user || locked) return;
    const onKey = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setPalette((open) => !open);
      }
    };
    window.addEventListener('keydown', onKey);

    return () => window.removeEventListener('keydown', onKey);
  }, [auth.user, locked]);

  useEffect(() => setCollapsed(readCollapsed()), []);
  // A changed store timezone reaches the page through the shared props.
  useEffect(() => setDisplayTimezone(auth.timezone ?? undefined), [auth.timezone]);

  function toggleSidebar() {
    // Small screens open the drawer; large screens fold the sidebar away.
    if (window.matchMedia('(min-width: 1024px)').matches) {
      setCollapsed((value) => {
        try {
          window.localStorage.setItem(SIDEBAR_PREFERENCE, value ? '0' : '1');
        } catch {
          // A browser without storage: the choice lasts for this page only.
        }

        return !value;
      });
    } else {
      setDrawer(true);
    }
  }

  // The logout endpoint is a JSON API, not an Inertia one. A full load
  // afterwards drops the old session from the shared props.
  function logout() {
    adminFetch('/auth/logout', { method: 'POST' })
      .catch(() => undefined)
      .finally(() => window.location.assign('/login'));
  }

  const brand = (
    <div className="flex h-14 items-center border-b border-slate-200 px-5">
      <Link href="/" className={`rounded ${FOCUS_RING}`}>
        <BrandLogo size={30} />
      </Link>
    </div>
  );

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900">
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-[70] focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:shadow">
        Skip to content
      </a>

      {auth.user && !collapsed && (
        <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-slate-200 bg-white lg:flex">
          {brand}
          <SidebarNav groups={groups} current={current} path={path} locked={locked} />
        </aside>
      )}

      {auth.user && drawer && (
        <div className="fixed inset-0 z-40 flex bg-slate-900/50 lg:hidden" onMouseDown={(e) => e.target === e.currentTarget && setDrawer(false)} onKeyDown={(e) => e.key === 'Escape' && setDrawer(false)}>
          <div role="dialog" aria-modal="true" aria-label="Menu" className="flex h-full w-72 max-w-[85vw] flex-col bg-white shadow-xl">
            <div className="flex items-center justify-between border-b border-slate-200 pr-2">
              {brand}
              <button type="button" autoFocus onClick={() => setDrawer(false)} aria-label="Close menu" className={`rounded-md px-2 py-1 text-slate-600 hover:bg-slate-100 ${FOCUS_RING}`}>
                ✕
              </button>
            </div>
            <SidebarNav groups={groups} current={current} path={path} locked={locked} onNavigate={() => setDrawer(false)} />
          </div>
        </div>
      )}

      <div className={auth.user && !collapsed ? 'lg:pl-64' : ''}>
        <header className="sticky top-0 z-20 flex h-14 items-center justify-between gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur">
          <div className="flex min-w-0 items-center gap-3">
            {auth.user && (
              <button type="button" onClick={toggleSidebar} aria-label="Menu" aria-expanded={drawer || !collapsed} className={`rounded-md border border-slate-300 px-2 py-1 text-slate-700 hover:bg-slate-100 ${FOCUS_RING}`}>
                <Icon name="menu" size={18} />
              </button>
            )}
            {(collapsed || !auth.user) && (
              <Link href="/" className={`hidden rounded sm:inline ${FOCUS_RING}`}>
                <BrandLogo size={28} />
              </Link>
            )}
            <StoreSwitcher auth={auth} />
            {access.hasStore && auth.activeStore && !locked && (
              <a href={`/shop/${auth.activeStore.slug}`} target="_blank" rel="noreferrer" className={`hidden items-center gap-1 rounded text-sm font-medium text-indigo-700 hover:underline md:inline-flex ${FOCUS_RING}`}>
                View storefront<Icon name="externalLink" size={14} /><span className="sr-only"> (opens in a new tab)</span>
              </a>
            )}
          </div>
          <div className="flex items-center gap-1">
            {auth.user && !locked && (
              <button
                type="button"
                onClick={() => setPalette(true)}
                className={`mr-1 hidden items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-slate-500 hover:border-slate-300 hover:text-slate-700 sm:flex ${FOCUS_RING}`}
              >
                <Icon name="search" size={16} />
                <span>Go to…</span>
                <kbd className="rounded border border-slate-200 bg-white px-1 text-[10px] text-slate-500">Ctrl K</kbd>
              </button>
            )}
            {auth.user && !locked && <HelpButton path={path} unseenRelease={auth.help_unseen_release ?? null} canAskPlatform={access.hasStore && access.can('support.platform')} />}
            <AccountMenu auth={auth} onLogout={logout} />
          </div>
        </header>

        {locked && (
          <div role="status" className="border-b border-amber-300 bg-amber-50">
            <p className="mx-auto max-w-7xl px-4 py-3 text-sm text-amber-900">
              <strong>One step before you can manage your store:</strong> turn on two-step sign-in below. Store owners must have it. The other pages
              open as soon as it is on.
            </p>
          </div>
        )}

        {!locked && <StoreStateBanner auth={auth} isOwner={access.isOwner} />}

        <main id="main" className="mx-auto max-w-7xl px-4 py-6">
          {children}
        </main>
      </div>

      <Toaster />
      <CommandPalette entries={entries} open={palette} onClose={() => setPalette(false)} />
      {auth.user && <StepUpDialog mfaEnabled={auth.user.mfa_enabled === true} />}
    </div>
  );
}

/**
 * Phase B51 (Module 03 §33–36): on every admin page, why the store is
 * read-only and what can still be done — so a refused change never comes
 * as a surprise.
 */
function StoreStateBanner({ auth, isOwner }: { auth: AuthProps; isOwner: boolean }) {
  const store = auth.activeStore;
  const status = store?.status;
  if (!store || !status || ['active', 'pending_setup'].includes(status)) return null;
  const until = store.retention_ends_at ? new Date(store.retention_ends_at).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : null;
  const text =
    status === 'suspended'
      ? 'This store is suspended by Umar Techy. Its shop is closed and nothing can be changed; you can still look and download your data.'
      : status === 'cancelled'
        ? `This store is closed and read-only.${until ? ` Its data is kept until ${until}.` : ''}`
        : 'This store is archived. Its data is no longer shown here.';

  return (
    <div role="status" className={`border-b ${status === 'suspended' ? 'border-red-300 bg-red-50 text-red-900' : 'border-amber-300 bg-amber-50 text-amber-900'}`}>
      <p className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm">
        <span>{text}</span>
        {isOwner && status === 'cancelled' && <Link href="/settings/close-store" className="font-semibold underline">Reopen or download your data</Link>}
        {status !== 'cancelled' && <Link href="/support" className="font-semibold underline">Contact Umar Techy support</Link>}
      </p>
    </div>
  );
}
