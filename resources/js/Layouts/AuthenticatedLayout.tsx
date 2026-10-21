import { PropsWithChildren, useEffect, useMemo, useRef, useState } from 'react';
import BrandLogo from '@/Components/Brand';
import Icon from '@/Components/ui/Icon';
import CommandPalette, { paletteEntries } from '@/Components/CommandPalette';
import { Link, usePage } from '@inertiajs/react';
import { adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { accessFrom, type AuthProps } from '@/lib/access';
import { activeHref, LOCKED_REASON, navigationFor, NAV_ICONS, sectionFor, sectionHref, toneClass, type NavEntry, type ResolvedGroup } from '@/lib/adminNav';
import { FOCUS_RING } from '@/Components/ui/Button';
import { toast, Toaster } from '@/Components/ui/toast';
import StepUpDialog from '@/Components/StepUpDialog';
import { HelpButton } from '@/Components/Help/HelpPanel';
import AttentionBell from '@/Components/AttentionBell';
import { setDisplayTimezone } from '@/lib/datetime';
import { isDark, isModeId, isThemeId, MODES, onModeChange, onThemeChange, setMode, setTheme, storedMode, storedTheme, THEMES, type ModeId, type ThemeId } from '@/lib/adminTheme';
import { leave, stay, useLeaveGuard, usePendingLeave } from '@/lib/leaveGuard';
import { ADMIN_LANGUAGES, setAdminLanguage, useAdminLanguage, useAdminT, type AdminTranslate } from '@/lib/adminI18n';
import { loadFont } from '@/Storefront/fonts';
import ErrorBoundary from '@/Components/ErrorBoundary';
import { installErrorReporter } from '@/lib/errorReporter';
import { ConfirmDialog } from '@/Components/ui/Dialog';

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
 *
 * UI-7: the theme colour (brand-*) and a theme picker in the account menu;
 * UI-8: a calmer frame — a taller header, an accent on the current entry,
 * and each page easing in (off with reduced motion).
 */
const SIDEBAR_PREFERENCE = 'admin.sidebar.collapsed';

function readCollapsed(): boolean {
  try {
    return window.localStorage.getItem(SIDEBAR_PREFERENCE) === '1';
  } catch {
    return false;
  }
}

/** A closed entry's reason in the admin language (adminNav writes it in English). */
function reasonText(t: AdminTranslate, reason: string | undefined): string | undefined {
  const pkg = reason?.match(/^Not included in your (.+) package$/);

  return pkg ? t('Not included in your {package} package', { package: pkg[1] === 'current' ? t('current') : pkg[1] }) : reason && t(reason);
}

/** A section's own list, under a name that is not the section's again (Orders › All orders). */
const ALL_OF: Record<string, string> = { Orders: 'All orders', Products: 'All products', Customers: 'All customers', Reports: 'All reports' };

function NavLink({ item, active, onNavigate, label }: { item: NavEntry; active: boolean; onNavigate?: () => void; label?: string }) {
  const t = useAdminT();
  // An entry the user cannot use right now is shown closed, with the
  // reason, instead of a link that would only bounce back.
  if (item.state !== 'open') {
    return (
      <span aria-disabled="true" title={reasonText(t, item.reason)} className="flex cursor-not-allowed items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-slate-400">
        <span className="flex-1 truncate">{t(label ?? item.label)}</span>
        <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-slate-500">{item.state === 'locked' ? t('Locked') : t('Upgrade')}</span>
        <span className="sr-only">. {reasonText(t, item.reason)}</span>
      </span>
    );
  }

  return (
    <Link
      href={item.href}
      onClick={onNavigate}
      aria-current={active ? 'page' : undefined}
      className={`relative flex items-center rounded-lg px-3 py-1.5 text-sm transition-colors duration-150 motion-reduce:transition-none ${FOCUS_RING} ${active ? 'tone-text font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'}`}
    >
      {active && <span aria-hidden="true" className="tone-bar absolute -start-[13px] inset-y-1 w-[3px] rounded-full" />}
      <span className="truncate">{t(label ?? item.label)}</span>
    </Link>
  );
}

/** One row of the sidebar: a section's icon in its colour, its name, and (when it has pages to show) a fold button. */
function SectionRow({ group, active, open, onToggle, onNavigate, locked }: { group: ResolvedGroup; active: boolean; open: boolean; onToggle?: () => void; onNavigate?: () => void; locked: boolean }) {
  const t = useAdminT();
  const href = sectionHref(group);
  const icon = group.icon ?? 'layers';
  const body = (
    <>
      <span aria-hidden="true" className={`flex h-8 w-8 flex-none items-center justify-center rounded-lg transition-transform duration-150 group-hover:scale-105 motion-reduce:transform-none ${active ? 'tone-solid shadow-sm' : 'tone-chip'}`}>
        <Icon name={icon} size={17} />
      </span>
      <span className="truncate">{t(group.label)}</span>
    </>
  );

  return (
    <div className={`flex items-center rounded-xl ${active ? 'tone-soft' : ''}`}>
      {href === null || locked ? (
        <span aria-disabled="true" title={locked ? t(LOCKED_REASON) : undefined} className="flex flex-1 cursor-not-allowed items-center gap-3 px-2 py-1.5 text-sm text-slate-400 opacity-70">{body}</span>
      ) : (
        <Link
          href={href}
          onClick={onNavigate}
          aria-current={active && !onToggle ? 'page' : undefined}
          className={`group flex min-w-0 flex-1 items-center gap-3 rounded-xl px-2 py-1.5 text-sm transition-colors duration-150 motion-reduce:transition-none ${FOCUS_RING} ${active ? 'tone-text font-semibold' : 'font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900'}`}
        >
          {body}
        </Link>
      )}
      {onToggle && !locked && (
        <button
          type="button"
          onClick={onToggle}
          aria-expanded={open}
          aria-label={open ? t('Hide the pages of {section}', { section: t(group.label) }) : t('Show the pages of {section}', { section: t(group.label) })}
          className={`me-1 rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-200/60 hover:text-slate-700 motion-reduce:transition-none ${FOCUS_RING}`}
        >
          <Icon name="chevronDown" size={15} className={`transition-transform duration-200 motion-reduce:transition-none ${open ? '' : '-rotate-90 rtl:rotate-90'}`} />
        </button>
      )}
    </div>
  );
}

/**
 * UI-26 — the sidebar: Dashboard, then one row per section (in its colour).
 * Only the section the user is in shows its pages; another opens with its
 * arrow. Settings opens one page that lists all settings. The user's own
 * account (Security) sits at the bottom.
 */
function SidebarNav({ groups, current, path, locked, onNavigate }: { groups: ResolvedGroup[]; current: string | null; path: string; locked: boolean; onNavigate?: () => void }) {
  const t = useAdminT();
  const here = sectionFor(groups, path);
  // Folded or unfolded by hand; otherwise only the current section is open.
  const [toggled, setToggled] = useState<Record<string, boolean>>({});
  // Arriving in another section forgets the hand-made choices.
  useEffect(() => setToggled({}), [here?.label]);
  const sections = groups.filter((group) => group.label !== 'Account');
  // Security is in the account menu and on the Settings page; the sidebar shows it only while it is the one page open.
  const accountGroup = groups.find((group) => group.label === 'Account');
  const accountItems = (accountGroup?.items ?? []).filter((item) => item.href !== '/security' || locked);
  const account = accountItems.length > 0 ? { ...accountGroup!, items: accountItems } : undefined;

  return (
    <nav aria-label="Admin" className="flex flex-1 flex-col overflow-y-auto px-3 py-4">
      <div className="tone-brand mb-2">
        {locked ? (
          <span aria-disabled="true" title={t('Turn on two-step sign-in first')} className="flex cursor-not-allowed items-center gap-3 rounded-xl px-2 py-1.5 text-sm text-slate-400">
            <span className="tone-chip flex h-8 w-8 items-center justify-center rounded-lg opacity-70"><Icon name="grid" size={17} /></span>
            {t('Dashboard')}
          </span>
        ) : (
          <Link
            href="/"
            onClick={onNavigate}
            aria-current={path === '/' ? 'page' : undefined}
            className={`group flex items-center gap-3 rounded-xl px-2 py-1.5 text-sm transition-colors duration-150 motion-reduce:transition-none ${FOCUS_RING} ${path === '/' ? 'tone-soft tone-text font-semibold' : 'font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900'}`}
          >
            <span aria-hidden="true" className={`flex h-8 w-8 items-center justify-center rounded-lg ${path === '/' ? 'tone-solid shadow-sm' : 'tone-chip'}`}><Icon name="grid" size={17} /></span>
            <span>{t('Dashboard')}</span>
          </Link>
        )}
      </div>

      <ul className="space-y-1">
        {sections.map((group) => {
          const active = here?.label === group.label;
          const foldable = !group.hub && group.items.length > 1;
          const open = foldable && (toggled[group.label] ?? active);

          return (
            <li key={group.label} className={`${toneClass(group.tone)} ${group.platform ? 'mt-4 border-t border-slate-200 pt-4' : ''}`}>
              {group.platform && <p className="mb-1.5 px-2 text-[11px] font-semibold uppercase tracking-wider text-violet-700">{t('Umar Techy staff only')}</p>}
              <SectionRow
                group={group}
                active={active}
                open={open}
                locked={locked && !group.platform}
                onNavigate={onNavigate}
                onToggle={foldable ? () => setToggled((value) => ({ ...value, [group.label]: !(value[group.label] ?? active) })) : undefined}
              />
              {open && (
                <ul className="mb-2 ms-[1.65rem] mt-1 space-y-0.5 border-s border-slate-200 ps-3 motion-safe:animate-fade-in">
                  {group.items.map((item) => (
                    <li key={item.href}>
                      <NavLink item={item} active={current === item.href} onNavigate={onNavigate} label={item.label === group.label ? ALL_OF[group.label] : undefined} />
                    </li>
                  ))}
                </ul>
              )}
            </li>
          );
        })}
      </ul>

      {account && (
        <ul className="tone-slate mt-auto space-y-0.5 border-t border-slate-200 pt-3">
          {account.items.map((item) => (
            <li key={item.href}>
              <Link
                href={item.href}
                onClick={onNavigate}
                aria-current={current === item.href ? 'page' : undefined}
                className={`flex items-center gap-3 rounded-xl px-2 py-1.5 text-sm transition-colors motion-reduce:transition-none ${FOCUS_RING} ${current === item.href ? 'tone-soft tone-text font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'}`}
              >
                <span aria-hidden="true" className="tone-chip flex h-8 w-8 items-center justify-center rounded-lg"><Icon name={NAV_ICONS[item.href] ?? 'user'} size={17} /></span>
                {t(item.label)}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </nav>
  );
}

function StoreSwitcher({ auth }: { auth: AuthProps }) {
  const t = useAdminT();
  const [busy, setBusy] = useState(false);
  const stores = auth.stores ?? [];

  if (stores.length <= 1) {
    return auth.activeStore ? (
      <span className="inline-flex max-w-[14rem] items-center gap-2 truncate rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-sm font-medium text-slate-700" title={t('The store you are managing')}>
        <Icon name="store" size={16} className="flex-none text-brand-600" />
        <span className="truncate">{auth.activeStore.name}</span>
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
        toast.error(adminErrorMessage(e, t('Could not switch store.')));
        setBusy(false);
      });
  }

  return (
    <label className="flex items-center gap-2 text-sm text-slate-600">
      <span className="hidden sm:inline">{t('Store')}</span>
      <select
        aria-label={t('Store you are managing')}
        value={auth.activeStore ? String(auth.activeStore.id) : ''}
        disabled={busy}
        onChange={(e) => switchTo(e.target.value)}
        className={`max-w-[12rem] rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-sm font-medium text-slate-900 hover:border-slate-300 ${FOCUS_RING}`}
      >
        {!auth.activeStore && <option value="">{t('Choose a store…')}</option>}
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
  const t = useAdminT();
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

  if (!auth.user) return <Link href="/login">{t('Sign in')}</Link>;

  return (
    <div ref={box} className="relative" onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}>
      <button
        type="button"
        aria-haspopup="true"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        className={`flex max-w-[14rem] items-center gap-2 rounded-xl px-1.5 py-1 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100 motion-reduce:transition-none ${FOCUS_RING}`}
      >
        <span aria-hidden="true" className="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-700 text-xs font-bold text-white ring-2 ring-white">
          {auth.user.name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase()}
        </span>
        <span className="hidden truncate sm:inline">{auth.user.name}</span>
        <Icon name="chevronDown" size={14} className="text-slate-400" />
      </button>
      {open && (
        <div className="absolute end-0 z-40 mt-2 w-64 origin-top-right rounded-xl border border-slate-200 bg-white p-1.5 text-sm shadow-xl shadow-slate-900/10 motion-safe:animate-scale-in">
          <div className="border-b border-slate-100 px-3 pb-2.5 pt-2">
            <p className="truncate font-semibold text-slate-900">{auth.user.name}</p>
            <p className="truncate text-xs text-slate-500">{auth.user.email}</p>
          </div>
          <ThemePicker />
          <ModePicker />
          <LanguagePicker />
          <div className="border-t border-slate-100 pt-1">
            <Link href="/security" className={`flex items-center gap-2.5 rounded-lg px-3 py-2 text-slate-700 transition-colors hover:bg-slate-100 motion-reduce:transition-none ${FOCUS_RING}`} onClick={() => setOpen(false)}>
              <Icon name="shieldCheck" size={16} className="text-slate-400" />{t('Security')}
            </Link>
            <button type="button" onClick={onLogout} className={`flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-start text-slate-700 transition-colors hover:bg-red-50 hover:text-red-700 motion-reduce:transition-none ${FOCUS_RING}`}>
              <Icon name="logOut" size={16} className="text-slate-400" />{t('Log out')}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

/** UI-7: the admin colour theme, kept on this device (lib/adminTheme). */
function ThemePicker() {
  const t = useAdminT();
  const [theme, setCurrent] = useState<ThemeId>('indigo');
  useEffect(() => {
    setCurrent(storedTheme());

    return onThemeChange(setCurrent);
  }, []);

  return (
    <fieldset className="px-3 py-2.5">
      <legend className="mb-2 text-xs font-medium text-slate-500">{t('Theme colour')}</legend>
      <div className="flex gap-2">
        {THEMES.map((option) => (
          <label
            key={option.id}
            className={`flex flex-1 cursor-pointer flex-col items-center gap-1 rounded-lg border px-2 py-1.5 text-[11px] font-medium transition-colors motion-reduce:transition-none has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-600 ${theme === option.id ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50'}`}
          >
            <input type="radio" name="admin-theme" value={option.id} checked={theme === option.id} onChange={() => setTheme(option.id)} className="sr-only" />
            <span aria-hidden="true" className="flex h-5 w-5 items-center justify-center rounded-full text-white shadow-inner" style={{ backgroundColor: option.swatch }}>
              {theme === option.id && <Icon name="check" size={12} strokeWidth={3} />}
            </span>
            {t(option.label)}
          </label>
        ))}
      </div>
    </fieldset>
  );
}

/** UI-22: light, dark or the device's setting (lib/adminTheme). */
function ModePicker() {
  const t = useAdminT();
  const mode = useAdminMode();
  const icons: Record<ModeId, Parameters<typeof Icon>[0]['name']> = { light: 'sun', dark: 'moon', system: 'monitor' };

  return (
    <fieldset className="border-t border-slate-100 px-3 py-2.5">
      <legend className="sr-only">{t('Appearance')}</legend>
      <p aria-hidden="true" className="mb-2 text-xs font-medium text-slate-500">{t('Appearance')}</p>
      <div className="flex gap-1 rounded-lg bg-slate-100 p-1">
        {MODES.map((option) => (
          <label
            key={option.id}
            className={`flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold transition-colors motion-reduce:transition-none has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-600 ${mode === option.id ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
          >
            <input type="radio" name="admin-mode" value={option.id} checked={mode === option.id} onChange={() => setMode(option.id)} className="sr-only" />
            <Icon name={icons[option.id]} size={13} />
            {t(option.label)}
          </label>
        ))}
      </div>
    </fieldset>
  );
}

/** The admin mode, following changes (and the device's setting under "system"). */
function useAdminMode(): ModeId {
  const [mode, setCurrent] = useState<ModeId>('light');
  useEffect(() => {
    setCurrent(storedMode());

    return onModeChange(setCurrent);
  }, []);

  return mode;
}

/** UI-18: the admin language, kept on this device (lib/adminI18n). */
function LanguagePicker() {
  const t = useAdminT();
  const language = useAdminLanguage();

  return (
    <fieldset className="border-t border-slate-100 px-3 py-2.5">
      <legend className="sr-only">{t('Language')}</legend>
      <p aria-hidden="true" className="mb-2 text-xs font-medium text-slate-500">{t('Language')}</p>
      <div className="flex gap-2">
        {ADMIN_LANGUAGES.map((option) => (
          <label
            key={option.id}
            lang={option.id}
            className={`flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-lg border px-2 py-1.5 text-xs font-semibold transition-colors motion-reduce:transition-none has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-600 ${language === option.id ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50'}`}
          >
            <input type="radio" name="admin-language" value={option.id} checked={language === option.id} onChange={() => setAdminLanguage(option.id)} className="sr-only" />
            {language === option.id && <Icon name="check" size={12} strokeWidth={3} />}
            {option.native}
          </label>
        ))}
      </div>
    </fieldset>
  );
}

/**
 * UI-19 — on phones, the most used places one thumb away: a bar at the
 * bottom with Dashboard, Orders, Products, a "+" for adding (a product or
 * an order) and More (the full menu). Each entry only when the user may
 * open it; hidden on large screens, where the sidebar is.
 */
function MobileTabBar({ path, canOrders, canProducts, canAddProduct, canAddOrder, onMore }: { path: string; canOrders: boolean; canProducts: boolean; canAddProduct: boolean; canAddOrder: boolean; onMore: () => void }) {
  const t = useAdminT();
  const [adding, setAdding] = useState(false);
  const tab = (href: string, label: string, icon: Parameters<typeof Icon>[0]['name'], active: boolean) => (
    <Link
      href={href}
      aria-current={active ? 'page' : undefined}
      className={`flex flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-[11px] font-medium transition-colors motion-reduce:transition-none ${FOCUS_RING} ${active ? 'text-brand-700' : 'text-slate-500 hover:text-slate-800'}`}
    >
      <span className={`flex h-7 w-12 items-center justify-center rounded-full transition-colors ${active ? 'bg-brand-50' : ''}`}><Icon name={icon} size={20} /></span>
      {t(label)}
    </Link>
  );
  const canAdd = canAddProduct || canAddOrder;

  return (
    <>
      {adding && (
        <div className="fixed inset-0 z-40 bg-slate-950/30 backdrop-blur-[2px] motion-safe:animate-fade-in lg:hidden" onMouseDown={(e) => e.target === e.currentTarget && setAdding(false)}>
          <div role="dialog" aria-modal="true" aria-label={t('Add a product or an order')} className="absolute inset-x-3 bottom-[calc(5rem+env(safe-area-inset-bottom))] rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl motion-safe:animate-fade-in-up">
            {canAddProduct && (
              <Link href="/products/new" onClick={() => setAdding(false)} className={`flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium text-slate-800 hover:bg-slate-50 \${FOCUS_RING}`}>
                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon name="box" size={20} /></span>
                {t('Add product')}
              </Link>
            )}
            {canAddOrder && (
              <Link href="/orders/new" onClick={() => setAdding(false)} className={`flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium text-slate-800 hover:bg-slate-50 \${FOCUS_RING}`}>
                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"><Icon name="cart" size={20} /></span>
                {t('New order')}
              </Link>
            )}
          </div>
        </div>
      )}
      <nav aria-label={t('Quick menu')} className="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-4px_16px_rgba(15,23,42,0.06)] backdrop-blur-md lg:hidden">
        <div className="mx-auto flex max-w-lg items-stretch px-1">
          {tab('/', 'Dashboard', 'grid', path === '/')}
          {canOrders && tab('/orders', 'Orders', 'cart', path.startsWith('/orders'))}
          {canAdd && (
            <div className="flex flex-1 items-center justify-center">
              <button
                type="button"
                onClick={() => setAdding((v) => !v)}
                aria-expanded={adding}
                aria-label={t('Add a product or an order')}
                className={`-mt-6 flex h-14 w-14 items-center justify-center rounded-full bg-brand-600 text-white shadow-lg shadow-brand-900/25 ring-4 ring-white transition active:scale-95 motion-reduce:transform-none ${FOCUS_RING}`}
              >
                <Icon name={adding ? 'x' : 'plus'} size={26} strokeWidth={2.2} />
              </button>
            </div>
          )}
          {canProducts && tab('/products', 'Products', 'box', path.startsWith('/products'))}
          <button type="button" onClick={onMore} className={`flex flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-[11px] font-medium text-slate-500 hover:text-slate-800 \${FOCUS_RING}`}>
            <span className="flex h-7 w-12 items-center justify-center rounded-full"><Icon name="menu" size={20} /></span>
            {t('More')}
          </button>
        </div>
      </nav>
    </>
  );
}

/** UI-13: the question shown when a link would leave a page with unsaved changes (lib/leaveGuard). */
function LeaveDialog() {
  const t = useAdminT();
  const pending = usePendingLeave();

  return (
    <ConfirmDialog open={pending !== null} title={t('Leave without saving?')} confirmLabel={t('Leave page')} cancelLabel={t('Stay on this page')} onConfirm={leave} onClose={stay}>
      <p>{t('Your changes on this page are not saved yet. If you leave now, they are lost.')}</p>
    </ConfirmDialog>
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
  const t = useAdminT();
  const language = useAdminLanguage();
  const urdu = language === 'ur';
  // UI-22: dark when chosen, or when the device prefers it under "system" (and when that changes).
  const mode = useAdminMode();
  const [systemDark, setSystemDark] = useState(false);
  useEffect(() => {
    if (mode !== 'system' || typeof window.matchMedia !== 'function') return;
    const query = window.matchMedia('(prefers-color-scheme: dark)');
    setSystemDark(query.matches);
    const changed = (event: MediaQueryListEvent) => setSystemDark(event.matches);
    query.addEventListener?.('change', changed);

    return () => query.removeEventListener?.('change', changed);
  }, [mode]);
  const dark = mode === 'system' ? systemDark : isDark(mode);
  // UI-19: the phone tab bar, for store users with the admin open.
  const tabBar = Boolean(auth.user) && access.hasStore && !locked;
  // UI-20: the account's saved theme and language win over this browser's (they follow the user to every device).
  // Only when the server's values change, so a choice just made here is not turned back.
  const saved = auth.user?.preferences;
  useEffect(() => {
    if (saved?.theme && isThemeId(saved.theme) && saved.theme !== storedTheme()) setTheme(saved.theme, false);
    if ((saved?.language === 'en' || saved?.language === 'ur') && saved.language !== language) setAdminLanguage(saved.language, false);
    if (isModeId(saved?.mode) && saved.mode !== storedMode()) setMode(saved.mode, false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [saved?.theme, saved?.language, saved?.mode]);
  // UI-18: Urdu is shown in Nastaliq, loaded only when chosen.
  useEffect(() => {
    if (urdu) loadFont('Noto Nastaliq Urdu');
  }, [urdu]);

  const [collapsed, setCollapsed] = useState(false);
  const [drawer, setDrawer] = useState(false);
  const [palette, setPalette] = useState(false);
  const entries = useMemo(
    () =>
      paletteEntries(groups, NAV_ICONS, [
        { href: '/', label: 'Dashboard', hint: 'Your store at a glance', icon: 'grid', group: 'Go to' },
        ...(access.can('products.create') ? [{ href: '/products/new', label: 'Add a product', hint: 'Name, price, pictures and stock', icon: 'box' as const, group: 'Quick action' }] : []),
        ...(access.can('orders.create') ? [{ href: '/orders/new', label: 'Create an order', hint: 'For a customer, by phone or in person', icon: 'cart' as const, group: 'Quick action' }] : []),
      ]).map((entry) => ({ ...entry, label: t(entry.label), hint: t(entry.hint), group: t(entry.group), keywords: `${entry.label} ${entry.hint}` })),
    [groups, access, t],
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
  // UI-25: script errors on admin pages are reported to Umar Techy (signed-in users only).
  useEffect(() => {
    if (auth.user) installErrorReporter();
  }, [auth.user]);
  useLeaveGuard();
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
    <div className="flex h-16 items-center border-b border-slate-100 px-5">
      <Link href="/" className={`rounded ${FOCUS_RING}`}>
        <BrandLogo size={32} />
      </Link>
    </div>
  );

  return (
    <div dir={urdu ? 'rtl' : 'ltr'} lang={urdu ? 'ur' : 'en'} data-mode={dark ? 'dark' : 'light'} className="admin-root min-h-screen bg-slate-50 text-slate-900 antialiased">
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:start-2 focus:top-2 focus:z-[70] focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:shadow">
        {t('Skip to content')}
      </a>

      {auth.user && !collapsed && (
        <aside className="fixed inset-y-0 start-0 z-30 hidden w-64 flex-col border-e border-slate-200/80 bg-white lg:flex">
          {brand}
          <SidebarNav groups={groups} current={current} path={path} locked={locked} />
        </aside>
      )}

      {auth.user && drawer && (
        <div className="fixed inset-0 z-40 flex bg-slate-950/40 backdrop-blur-[2px] motion-safe:animate-fade-in lg:hidden" onMouseDown={(e) => e.target === e.currentTarget && setDrawer(false)} onKeyDown={(e) => e.key === 'Escape' && setDrawer(false)}>
          <div role="dialog" aria-modal="true" aria-label={t('Menu')} className="flex h-full w-72 max-w-[85vw] flex-col bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-200 pe-2">
              {brand}
              <button type="button" autoFocus onClick={() => setDrawer(false)} aria-label={t('Close menu')} className={`rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800 ${FOCUS_RING}`}>
                <Icon name="x" size={18} />
              </button>
            </div>
            <SidebarNav groups={groups} current={current} path={path} locked={locked} onNavigate={() => setDrawer(false)} />
          </div>
        </div>
      )}

      <div className={auth.user && !collapsed ? 'lg:ps-64' : ''}>
        <header className="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b border-slate-200/80 bg-white/85 px-4 backdrop-blur-md sm:px-6">
          <div className="flex min-w-0 items-center gap-3">
            {auth.user && (
              <button type="button" onClick={toggleSidebar} aria-label={t('Menu')} aria-expanded={drawer || !collapsed} className={`rounded-lg p-2 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 motion-reduce:transition-none ${FOCUS_RING}`}>
                <Icon name="menu" size={20} />
              </button>
            )}
            {(collapsed || !auth.user) && (
              <Link href="/" className={`hidden rounded sm:inline ${FOCUS_RING}`}>
                <BrandLogo size={28} />
              </Link>
            )}
            <StoreSwitcher auth={auth} />
            {access.hasStore && auth.activeStore && !locked && (
              <a href={`/shop/${auth.activeStore.slug}`} target="_blank" rel="noreferrer" className={`hidden items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm font-medium text-brand-700 transition-colors hover:bg-brand-50 md:inline-flex motion-reduce:transition-none ${FOCUS_RING}`}>
                {t('View storefront')}<Icon name="externalLink" size={14} /><span className="sr-only"> {t('(opens in a new tab)')}</span>
              </a>
            )}
          </div>
          <div className="flex items-center gap-1">
            {auth.user && !locked && (
              <button
                type="button"
                onClick={() => setPalette(true)}
                className={`me-1 hidden min-w-[13rem] items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 transition-colors hover:border-slate-300 hover:bg-white hover:text-slate-700 sm:flex motion-reduce:transition-none ${FOCUS_RING}`}
              >
                <Icon name="search" size={16} />
                <span className="flex-1 text-start">{t('Go to…')}</span>
                <kbd className="rounded-md border border-slate-200 bg-white px-1.5 py-0.5 font-sans text-[10px] font-medium text-slate-500">Ctrl K</kbd>
              </button>
            )}
            {auth.user && !locked && access.hasStore && <AttentionBell />}
            {auth.user && !locked && <HelpButton path={path} unseenRelease={auth.help_unseen_release ?? null} canAskPlatform={access.hasStore && access.can('support.platform')} />}
            <AccountMenu auth={auth} onLogout={logout} />
          </div>
        </header>

        {locked && (
          <div role="status" className="border-b border-amber-200 bg-amber-50">
            <p className="mx-auto max-w-7xl px-4 py-3 text-sm text-amber-900 sm:px-6 lg:px-8">
              <strong>{t('One step before you can manage your store:')}</strong> {t('turn on two-step sign-in below. Store owners must have it. The other pages open as soon as it is on.')}
            </p>
          </div>
        )}

        {!locked && <StoreStateBanner auth={auth} isOwner={access.isOwner} />}

        <main id="main" className={`mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8 ${tabBar ? 'pb-28 lg:pb-8' : ''}`}>
          {/* Each page eases in (keyed by the address, so it runs on every visit). */}
          <div key={path} className="motion-safe:animate-fade-in-up">
            <ErrorBoundary
              fallback={(retry) => (
                <div role="alert" className="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                  <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-red-50 text-red-600"><Icon name="alert" size={26} /></span>
                  <h1 className="mt-4 text-lg font-semibold text-slate-900">{t('This page could not be shown')}</h1>
                  <p className="mt-1 text-sm text-slate-600">{t('Something went wrong while drawing it. Umar Techy has been told. Try again, or go back to the dashboard.')}</p>
                  <div className="mt-5 flex flex-wrap justify-center gap-2">
                    <button type="button" onClick={retry} className="inline-flex min-h-10 items-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">{t('Try again')}</button>
                    <Link href="/" className="inline-flex min-h-10 items-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">{t('Go to the dashboard')}</Link>
                  </div>
                </div>
              )}
            >
              {children}
            </ErrorBoundary>
          </div>
        </main>
      </div>

      {tabBar && (
        <MobileTabBar
          path={path}
          canOrders={access.can('orders.view')}
          canProducts={access.can('products.view')}
          canAddProduct={access.can('products.create')}
          canAddOrder={access.can('orders.create')}
          onMore={() => setDrawer(true)}
        />
      )}
      <Toaster />
      <LeaveDialog />
      <CommandPalette
        entries={entries}
        open={palette}
        onClose={() => setPalette(false)}
        search={{ orders: access.hasStore && access.can('orders.view'), products: access.hasStore && access.can('products.view'), customers: access.hasStore && access.can('customers.view') }}
      />
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
  const t = useAdminT();
  const store = auth.activeStore;
  const status = store?.status;
  if (!store || !status || ['active', 'pending_setup'].includes(status)) return null;
  const until = store.retention_ends_at ? new Date(store.retention_ends_at).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : null;
  const text =
    status === 'suspended'
      ? t('This store is suspended by Umar Techy. Its shop is closed and nothing can be changed; you can still look and download your data.')
      : status === 'cancelled'
        ? `${t('This store is closed and read-only.')}${until ? ` ${t('Its data is kept until {date}.', { date: until })}` : ''}`
        : t('This store is archived. Its data is no longer shown here.');

  return (
    <div role="status" className={`border-b ${status === 'suspended' ? 'border-red-300 bg-red-50 text-red-900' : 'border-amber-300 bg-amber-50 text-amber-900'}`}>
      <p className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm">
        <span>{text}</span>
        {isOwner && status === 'cancelled' && <Link href="/settings/close-store" className="font-semibold underline">{t('Reopen or download your data')}</Link>}
        {status !== 'cancelled' && <Link href="/support" className="font-semibold underline">{t('Contact Umar Techy support')}</Link>}
      </p>
    </div>
  );
}
