import '../css/app.css';
import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { setDisplayTimezone, timezoneFromProps } from '@/lib/datetime';

// Approved Stack: Inertia.js SPA bootstrap, Sanctum SPA cookie-session
// mode (ADR-002 Surface A) — no client-side token handling here; the
// browser's session cookie carries authentication automatically.
createInertiaApp({
  resolve: (name) => {
    const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
    return (pages[`./Pages/${name}.tsx`] as { default: unknown }).default;
  },
  setup({ el, App, props }) {
    // Dates are shown in the store's timezone (Module 33 §50.3). The
    // server shares it with every page; keep it current across visits.
    setDisplayTimezone(timezoneFromProps(props.initialPage.props));
    router.on('navigate', (event) => setDisplayTimezone(timezoneFromProps(event.detail.page.props)));

    createRoot(el).render(<App {...props} />);
  },
});
