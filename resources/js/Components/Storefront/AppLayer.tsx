import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import type { Shell } from '@/Storefront/types';
import { useT } from '@/Storefront/i18n';
import { applyUpdate, install, isIos, startApp, useApp } from '@/Storefront/pwa';

/**
 * Phase B55 (gap G16; Module 25 §16, §21–23, §29, §55–56, §72) — what the
 * store's app adds to every storefront page:
 *
 * - offline: a notice that saved pages may be out of date, and page links
 *   load through the service worker (saved copy or the offline page)
 *   instead of failing; back online, cart and checkout are checked again
 *   with the server (§22);
 * - a new version: "Refresh" switches to it (§23);
 * - install: a small, dismissible offer after the shopper has looked
 *   around (§56), or how to add it on an iPhone;
 * - the app's bottom navigation on phones (§29), by the store's choice.
 */
const DISMISS_KEY = 'ut-app-install-dismissed';
const VIEWS_KEY = 'ut-app-views';
const LIVE_PAGES = ['/cart', '/checkout'];

function read(key: string): string | null {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

function write(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    // Storage blocked: the choice lasts for this page only.
  }
}

export default function AppLayer({ shell }: { shell: Shell }) {
  const t = useT();
  const app = useApp();
  const page = usePage();
  const path = page.url.split('?')[0];
  const base = shell.base_path;
  const pwa = shell.pwa;

  useEffect(() => startApp(shell), [shell]);

  // Offline, a link loads the page through the service worker rather than
  // asking the server for its data and failing (§16).
  useEffect(() => {
    if (app.online) return;

    return router.on('before', (event) => {
      const visit = event.detail.visit;
      if (visit.method === 'get') {
        event.preventDefault();
        window.location.assign(visit.url.href);
      }
    });
  }, [app.online]);

  // Back online: the server checks the cart and checkout again (§18, §22).
  const [wasOffline, setWasOffline] = useState(false);
  useEffect(() => {
    if (!app.online) setWasOffline(true);
    else if (wasOffline) {
      setWasOffline(false);
      if (LIVE_PAGES.some((live) => path === `${base}${live}`)) router.reload();
    }
  }, [app.online, wasOffline, path, base]);

  return (
    <>
      {!app.online && (
        <div role="status" className="bg-slate-800 px-4 py-2 text-center text-sm text-white">
          {t('You are offline. Pages you saw before may be out of date; prices and stock are checked again when you order.')}
        </div>
      )}
      {app.updateReady && (
        <div role="status" className="flex flex-wrap items-center justify-center gap-3 bg-sf-surface px-4 py-2 text-center text-sm">
          <span>{t('A new version of this store is ready.')}</span>
          <button type="button" onClick={applyUpdate} className="sf-btn rounded-sf bg-sf-primary px-3 py-1 font-medium text-white">
            {t('Refresh')}
          </button>
        </div>
      )}
      {pwa?.enabled && <InstallOffer shell={shell} canInstall={app.canInstall} standalone={app.standalone} />}
      {pwa?.enabled && (pwa.mobile_nav === 'always' || (pwa.mobile_nav === 'installed' && app.standalone)) && <TabBar shell={shell} path={path} />}
    </>
  );
}

function InstallOffer({ shell, canInstall, standalone }: { shell: Shell; canInstall: boolean; standalone: boolean }) {
  const t = useT();
  const [visible, setVisible] = useState(false);
  const ios = typeof navigator !== 'undefined' && isIos();

  useEffect(() => {
    if (standalone || !shell.pwa?.install_prompt) return;
    const dismissed = Number(read(DISMISS_KEY) ?? 0);
    if (dismissed > Date.now() - 30 * 24 * 3600 * 1000) return; // asked at most once a month (§53, §56)
    const views = Number(read(VIEWS_KEY) ?? 0) + 1;
    write(VIEWS_KEY, String(views));
    if (views >= 3) setVisible(true); // only after looking around
  }, [shell.pwa?.install_prompt, standalone]);

  if (!visible || standalone || (!canInstall && !ios)) return null;

  function dismiss() {
    write(DISMISS_KEY, String(Date.now()));
    setVisible(false);
  }

  return (
    <aside aria-label={t('Install the app')} className="fixed inset-x-3 bottom-20 z-40 mx-auto max-w-md rounded-sf border border-sf-border bg-sf-bg p-4 shadow-lg md:bottom-4">
      <div className="flex items-start gap-3">
        {shell.pwa?.icon && <img src={shell.pwa.icon} alt="" className="h-12 w-12 rounded-sf" />}
        <div className="flex-1 text-sm">
          <p className="font-semibold">{t('Get the {store} app', { store: shell.store.name })}</p>
          <p className="mt-1 text-sf-muted">
            {canInstall ? t('Open the shop from your home screen, like an app. No download from a store.') : t('On iPhone: tap Share, then “Add to Home Screen”.')}
          </p>
          <div className="mt-3 flex gap-2">
            {canInstall && (
              <button
                type="button"
                onClick={() => install().then(() => setVisible(false))}
                className="sf-btn rounded-sf bg-sf-primary px-3 py-1.5 font-medium text-white"
              >
                {t('Install')}
              </button>
            )}
            <button type="button" onClick={dismiss} className="rounded-sf border border-sf-border px-3 py-1.5">
              {t('Not now')}
            </button>
          </div>
        </div>
      </div>
    </aside>
  );
}

/** Module 25 §29: Home, Shop, Search, Cart and Account, one tap away on a phone. */
function TabBar({ shell, path }: { shell: Shell; path: string }) {
  const t = useT();
  const base = shell.base_path;
  const tabs = [
    { href: base || '/', label: t('Home'), icon: '⌂', match: (p: string) => p === base || p === `${base}/` },
    { href: `${base}/products`, label: t('Shop'), icon: '▦', match: (p: string) => p.startsWith(`${base}/products`) || p.startsWith(`${base}/categories`) },
    { href: `${base}/search`, label: t('Search'), icon: '⌕', match: (p: string) => p.startsWith(`${base}/search`) },
    { href: `${base}/cart`, label: t('Cart'), icon: '◫', match: (p: string) => p.startsWith(`${base}/cart`) || p.startsWith(`${base}/checkout`) },
    { href: `${base}/account`, label: t('Account'), icon: '◉', match: (p: string) => p.startsWith(`${base}/account`) },
  ];

  return (
    <>
      <div aria-hidden="true" className="h-16 md:hidden" />
      <nav aria-label={t('App navigation')} className="fixed inset-x-0 bottom-0 z-30 border-t border-sf-border bg-sf-bg pb-[env(safe-area-inset-bottom)] md:hidden">
        <ul className="grid grid-cols-5">
          {tabs.map((tab) => {
            const active = tab.match(path);

            return (
              <li key={tab.href}>
                <Link href={tab.href} aria-current={active ? 'page' : undefined} className={`flex min-h-[3.5rem] flex-col items-center justify-center gap-0.5 text-xs ${active ? 'font-semibold text-sf-primary' : 'text-sf-muted'}`}>
                  <span aria-hidden="true" className="text-lg leading-none">
                    {tab.icon}
                  </span>
                  {tab.label}
                </Link>
              </li>
            );
          })}
        </ul>
      </nav>
    </>
  );
}
