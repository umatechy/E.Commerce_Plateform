import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

// Approved Stack: Inertia.js SPA bootstrap, Sanctum SPA cookie-session
// mode (ADR-002 Surface A) — no client-side token handling here; the
// browser's session cookie carries authentication automatically.
createInertiaApp({
  resolve: (name) => {
    const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
    return (pages[`./Pages/${name}.tsx`] as { default: unknown }).default;
  },
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />);
  },
});
