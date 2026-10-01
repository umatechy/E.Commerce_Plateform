import '../css/app.css';
import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';
import { setDisplayTimezone, timezoneFromProps } from '@/lib/datetime';

// Approved Stack: Inertia.js SPA bootstrap, Sanctum SPA cookie-session
// mode (ADR-002 Surface A) — no client-side token handling here; the
// browser's session cookie carries authentication automatically.
createInertiaApp({
  // Each page is its own chunk (Phase B31): the admin has many pages, and a
  // visitor of one should not download all of them.
  resolve: async (name) => {
    const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx');
    const load = pages[`./Pages/${name}.tsx`];
    if (!load) throw new Error(`Unknown page: ${name}`);

    return (await load()).default;
  },
  setup({ el, App, props }) {
    // Dates are shown in the store's timezone (Module 33 §50.3). The
    // server shares it with every page; keep it current across visits.
    setDisplayTimezone(timezoneFromProps(props.initialPage.props));
    router.on('navigate', (event) => setDisplayTimezone(timezoneFromProps(event.detail.page.props)));

    createRoot(el).render(<App {...props} />);
  },
});
