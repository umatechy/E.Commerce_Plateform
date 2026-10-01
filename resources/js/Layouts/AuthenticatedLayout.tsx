import { PropsWithChildren, useEffect, useMemo, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { accessFrom, type AuthProps } from '@/lib/access';
import { activeHref, navigationFor, type NavEntry, type ResolvedGroup } from '@/lib/adminNav';
import { FOCUS_RING } from '@/Components/ui/Button';
import { toast, Toaster } from '@/Components/ui/toast';
import StepUpDialog from '@/Components/StepUpDialog';
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
      <span aria-disabled="true" title={item.reason} className="flex cursor-not-allowed items-center justify-between rounded-md px-3 py-1.5 text-sm text-slate-400">
        <span>{item.label}</span>
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
      className={`block rounded-md px-3 py-1.5 text-sm ${FOCUS_RING} ${active ? 'bg-indigo-50 font-medium text-indigo-800' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900'}`}
    >
      {item.label}
    </Link>
  );
}

function SidebarNav({ groups, current, path, locked, onNavigate }: { groups: ResolvedGroup[]; current: string | null; path: string; locked: boolean; onNavigate?: () => void }) {
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
            className={`block rounded-md px-3 py-1.5 text-sm ${FOCUS_RING} ${path === '/' ? 'bg-indigo-50 font-medium text-indigo-800' : 'text-slate-700 hover:bg-slate-100'}`}
          >
            Dashboard
          </Link>
        )}
      </div>
      {groups.map((group) => (
        <div key={group.label} className={group.platform ? 'rounded-md border border-violet-200 bg-violet-50/60 p-2' : ''}>
          <h2 className={`px-3 pb-1 text-xs font-semibold uppercase tracking-wide ${group.platform ? 'text-violet-800' : 'text-slate-500'}`}>{group.label}</h2>
          {group.platform && <p className="px-3 pb-1 text-[11px] text-violet-800">Umar Techy staff only. Acts across all stores.</p>}
          <ul className="space-y-0.5">
            {group.items.map((item) => (
              <li key={item.href}>
                <NavLink item={item} active={current === item.href} onNavigate={onNavigate} />
              </li>
            ))}
          </ul>
        </div>
      ))}
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
        className={`flex max-w-[12rem] items-center gap-1 rounded-md px-2 py-1 text-sm text-slate-700 hover:bg-slate-100 ${FOCUS_RING}`}
      >
        <span className="truncate">{auth.user.name}</span>
        <span aria-hidden="true">▾</span>
      </button>
      {open && (
        <div className="absolute right-0 z-40 mt-1 w-56 rounded-md border border-slate-200 bg-white p-1 text-sm shadow-lg">
          <p className="truncate px-3 py-2 text-xs text-slate-500">{auth.user.email}</p>
          <Link href="/security" className={`block rounded px-3 py-1.5 text-slate-700 hover:bg-slate-100 ${FOCUS_RING}`} onClick={() => setOpen(false)}>
            Security
          </Link>
          <button type="button" onClick={onLogout} className={`block w-full rounded px-3 py-1.5 text-left text-slate-700 hover:bg-slate-100 ${FOCUS_RING}`}>
            Log out
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
      <Link href="/" className={`rounded font-semibold text-slate-900 ${FOCUS_RING}`}>
        Umar Techy
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
        <header className="sticky top-0 z-20 flex h-14 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4">
          <div className="flex min-w-0 items-center gap-3">
            {auth.user && (
              <button type="button" onClick={toggleSidebar} aria-label="Menu" aria-expanded={drawer || !collapsed} className={`rounded-md border border-slate-300 px-2 py-1 text-slate-700 hover:bg-slate-100 ${FOCUS_RING}`}>
                <span aria-hidden="true">☰</span>
              </button>
            )}
            {(collapsed || !auth.user) && (
              <Link href="/" className={`hidden rounded font-semibold sm:inline ${FOCUS_RING}`}>
                Umar Techy
              </Link>
            )}
            <StoreSwitcher auth={auth} />
            {access.hasStore && auth.activeStore && !locked && (
              <a href={`/shop/${auth.activeStore.slug}`} target="_blank" rel="noreferrer" className={`hidden rounded text-sm text-indigo-700 hover:underline md:inline ${FOCUS_RING}`}>
                View storefront<span className="sr-only"> (opens in a new tab)</span>
              </a>
            )}
          </div>
          <AccountMenu auth={auth} onLogout={logout} />
        </header>

        {locked && (
          <div role="status" className="border-b border-amber-300 bg-amber-50">
            <p className="mx-auto max-w-7xl px-4 py-3 text-sm text-amber-900">
              <strong>One step before you can manage your store:</strong> turn on two-step sign-in below. Store owners must have it. The other pages
              open as soon as it is on.
            </p>
          </div>
        )}

        <main id="main" className="mx-auto max-w-7xl px-4 py-6">
          {children}
        </main>
      </div>

      <Toaster />
      {auth.user && <StepUpDialog mfaEnabled={auth.user.mfa_enabled === true} />}
    </div>
  );
}
