import type { Config } from 'tailwindcss';

export default {
  content: ['./resources/js/**/*.{ts,tsx}'],
  theme: {
    extend: {
      // Module 05 storefront: a store's published theme tokens arrive as
      // CSS variables on the storefront root (StoreLayout); these are the
      // fallbacks when a store has not set one.
      colors: {
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
      boxShadow: {
        sf: 'var(--sf-shadow, none)',
        'sf-hover': 'var(--sf-shadow-hover, none)',
      },
    },
  },
  plugins: [],
} satisfies Config;
