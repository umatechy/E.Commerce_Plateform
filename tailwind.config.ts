import type { Config } from 'tailwindcss';

export default {
  content: ['./resources/js/**/*.{ts,tsx}'],
  theme: {
    extend: {
      // Module 05 storefront: a store's published theme tokens arrive as
      // CSS variables on the storefront root (StoreLayout); these are the
      // fallbacks when a store has not set one.
      colors: {
        // UI-22: slate (the neutral) reads CSS variables so the admin's dark mode can turn its scale around
        // (app.css). Outside a dark admin the values are Tailwind's own slate.
        slate: Object.fromEntries([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((n) => [n, `rgb(var(--slate-${n}) / <alpha-value>)`])),
        // UI-7: the admin theme colour (Indigo, Emerald or Slate, chosen by each user; lib/theme.ts). CSS variables in app.css.
        brand: {
          50: 'rgb(var(--brand-50) / <alpha-value>)',
          100: 'rgb(var(--brand-100) / <alpha-value>)',
          200: 'rgb(var(--brand-200) / <alpha-value>)',
          300: 'rgb(var(--brand-300) / <alpha-value>)',
          400: 'rgb(var(--brand-400) / <alpha-value>)',
          500: 'rgb(var(--brand-500) / <alpha-value>)',
          600: 'rgb(var(--brand-600) / <alpha-value>)',
          700: 'rgb(var(--brand-700) / <alpha-value>)',
          800: 'rgb(var(--brand-800) / <alpha-value>)',
          900: 'rgb(var(--brand-900) / <alpha-value>)',
          950: 'rgb(var(--brand-950) / <alpha-value>)',
        },
        'sf-primary': 'var(--sf-primary, #111827)',
        'sf-accent': 'var(--sf-accent, #2563eb)',
        'sf-bg': 'var(--sf-background, #ffffff)',
        'sf-surface': 'var(--sf-surface, #f9fafb)',
        'sf-text': 'var(--sf-text, #111827)',
        'sf-muted': 'var(--sf-muted, #6b7280)',
        'sf-border': 'var(--sf-border, #e5e7eb)',
        'sf-success': 'var(--sf-success, #15803d)',
        'sf-warning': 'var(--sf-warning, #b45309)',
        'sf-error': 'var(--sf-error, #b91c1c)',
        'sf-secondary': 'var(--sf-secondary, #6b7280)',
      },
      borderRadius: {
        sf: 'var(--sf-radius, 0.5rem)',
        'sf-lg': 'var(--sf-radius-lg, 0.75rem)',
      },
      // Phase B36 (Module 17 §8, §12): theme fonts and elevation.
      fontFamily: {
        'sf-heading': 'var(--sf-font-heading, system-ui)',
        'sf-body': 'var(--sf-font-body, system-ui)',
      },
      // UI-6: light motion for the admin; used with motion-safe: so reduced motion turns it off.
      // Fill mode 'backwards', never 'both': a transform left on a wrapper after the animation would
      // make it the containing block of fixed and absolute children (dialogs, sticky bars, sr-only labels).
      keyframes: {
        'fade-in': { from: { opacity: '0' }, to: { opacity: '1' } },
        'fade-in-up': { from: { opacity: '0', transform: 'translateY(6px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
        'scale-in': { from: { opacity: '0', transform: 'scale(0.97)' }, to: { opacity: '1', transform: 'scale(1)' } },
        'slide-in-right': { from: { opacity: '0', transform: 'translateX(24px)' }, to: { opacity: '1', transform: 'translateX(0)' } },
      },
      animation: {
        'fade-in': 'fade-in 200ms ease-out backwards',
        'fade-in-up': 'fade-in-up 280ms cubic-bezier(0.2, 0.8, 0.2, 1) backwards',
        'scale-in': 'scale-in 180ms cubic-bezier(0.2, 0.8, 0.2, 1) backwards',
        'slide-in-right': 'slide-in-right 240ms cubic-bezier(0.2, 0.8, 0.2, 1) backwards',
      },
      boxShadow: {
        sf: 'var(--sf-shadow, none)',
        'sf-hover': 'var(--sf-shadow-hover, none)',
      },
    },
  },
  plugins: [],
} satisfies Config;
