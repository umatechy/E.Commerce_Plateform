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
      },
      borderRadius: {
        sf: 'var(--sf-radius, 0.5rem)',
      },
    },
  },
  plugins: [],
} satisfies Config;
