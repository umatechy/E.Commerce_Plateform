import { useSyncExternalStore } from 'react';
import type { Shell } from './types';
import { storefrontFetch } from './api';

/**
 * Phase B55 (gap G16; Module 25) — the store's installable app in the page:
 * the service worker, whether the shop's server can be reached, a waiting
 * new version, and the browser's install offer.
 *
 * Business rules stay on the server (§4): being offline only ever stops
 * actions (placing an order); it never decides a price, stock or a state.
 */

type State = {
  /** The browser has a network and the server answered the last check (§21). */
  online: boolean;
  /** A new version of the app is ready; it takes over when the shopper says so (§23). */
  updateReady: boolean;
  /** The browser offers to install the app (Android/desktop Chrome, Edge). */
  canInstall: boolean;
  /** Opened from the home screen. */
  standalone: boolean;
};

type InstallEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> };

let state: State = { online: true, updateReady: false, canInstall: false, standalone: false };
const listeners = new Set<() => void>();
let installEvent: InstallEvent | null = null;
let waiting: ServiceWorker | null = null;
let started = false;
let pinging: number | null = null;

function set(next: Partial<State>): void {
  state = { ...state, ...next };
  listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);

  return () => listeners.delete(listener);
}

export function useApp(): State {
  return useSyncExternalStore(subscribe, () => state, () => state);
}

/** Only the browser's word is not enough (§21): ask the server too. */
async function reachable(): Promise<boolean> {
  try {
    const response = await fetch('/api/v1/public/health', { method: 'GET', cache: 'no-store', headers: { Accept: 'application/json' } });

    return response.status > 0; // any answer means the server is there
  } catch {
    return false;
  }
}

function startPinging(): void {
  if (pinging !== null) return;
  pinging = window.setInterval(async () => {
    if (navigator.onLine && (await reachable())) {
      window.clearInterval(pinging ?? undefined);
      pinging = null;
      set({ online: true });
    }
  }, 15000);
}

function isStandalone(): boolean {
  return window.matchMedia?.('(display-mode: standalone)').matches === true || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

/** Counted per store and day, with no device identifier (§68–69). Never blocks anything. */
export function countEvent(shell: Shell, event: 'installed' | 'launched' | 'offline_view'): void {
  storefrontFetch(shell, '/storefront/app/events', { method: 'POST', body: { event } }).catch(() => undefined);
}

/** Started once per page load by the store layout. */
export function startApp(shell: Shell): void {
  if (started || typeof window === 'undefined') return;
  started = true;
  const pwa = shell.pwa;

  set({ online: navigator.onLine, standalone: isStandalone() });
  window.addEventListener('offline', () => {
    set({ online: false });
    if (pwa?.enabled) countEvent(shell, 'offline_view');
    startPinging();
  });
  window.addEventListener('online', async () => {
    if (await reachable()) set({ online: true });
    else startPinging();
  });
  if (!navigator.onLine) startPinging();

  if (!('serviceWorker' in navigator) || !window.isSecureContext) return;

  if (!pwa?.enabled) {
    // The app was switched off or the shop closed (§83): a worker from before steps aside and
    // this store's saved pages go (its own update does the same). Other stores' caches stay.
    navigator.serviceWorker
      .getRegistrations()
      .then((all) => Promise.all(all.filter((r) => r.scope.endsWith(pwa?.scope ?? `${shell.base_path}/`)).map((r) => r.unregister())))
      .then(() => (pwa?.cache_prefix && 'caches' in window ? caches.keys() : []))
      .then((names) => Promise.all(names.filter((name) => pwa?.cache_prefix && name.startsWith(pwa.cache_prefix)).map((name) => caches.delete(name))))
      .catch(() => undefined);

    return;
  }

  if (isStandalone()) {
    try {
      // Once per session, not per page.
      if (!window.sessionStorage.getItem('ut-app-launched')) {
        window.sessionStorage.setItem('ut-app-launched', '1');
        countEvent(shell, 'launched');
      }
    } catch {
      // Storage blocked: the launch is simply not counted.
    }
  }

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault(); // the store decides when to offer it (§56)
    installEvent = event as InstallEvent;
    set({ canInstall: pwa.install_prompt });
  });
  window.addEventListener('appinstalled', () => {
    installEvent = null;
    set({ canInstall: false });
    countEvent(shell, 'installed');
  });

  navigator.serviceWorker
    .register(pwa.worker_url, { scope: pwa.scope })
    .then((registration) => {
      const watch = (worker: ServiceWorker | null) => {
        worker?.addEventListener('statechange', () => {
          // A new version is installed while an old one runs this page: ask before switching (§23–24).
          if (worker.state === 'installed' && navigator.serviceWorker.controller) {
            waiting = worker;
            set({ updateReady: true });
          }
        });
      };
      if (registration.waiting && navigator.serviceWorker.controller) {
        waiting = registration.waiting;
        set({ updateReady: true });
      }
      watch(registration.installing);
      registration.addEventListener('updatefound', () => watch(registration.installing));
    })
    .catch(() => undefined);

  let reloading = false;
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (waiting !== null && !reloading) {
      reloading = true;
      window.location.reload();
    }
  });
}

/** Switch to the new version: the page reloads once it has taken over. */
export function applyUpdate(): void {
  waiting?.postMessage('skip-waiting');
}

export async function install(): Promise<boolean> {
  if (!installEvent) return false;
  await installEvent.prompt();
  const choice = await installEvent.userChoice;
  installEvent = null;
  set({ canInstall: false });

  return choice.outcome === 'accepted';
}

/** Signing out: the app forgets the pages kept from this session (§66). */
export function forgetPages(): void {
  if ('serviceWorker' in navigator) navigator.serviceWorker.controller?.postMessage('forget-pages');
}

/** iPhones and iPads install from the Share menu; they never offer an install event. */
export function isIos(): boolean {
  return /iPhone|iPad|iPod/.test(navigator.userAgent) && !/CriOS|FxiOS/.test(navigator.userAgent);
}

