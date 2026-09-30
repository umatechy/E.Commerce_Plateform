import { defineConfig } from 'vitest/config';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import path from 'path';

// Approved Stack: React + TypeScript + Inertia.js + Tailwind + shadcn/ui.
// laravel-vite-plugin supplies the entry points (there is no index.html)
// and writes public/build/manifest.json for the @vite Blade directive.
// It is skipped under Vitest: tests need no entry points, and the plugin
// refuses to start a dev server when CI=true, which aborted the CI run.
export default defineConfig({
  plugins: [
    ...(process.env.VITEST
      ? []
      : [
          laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
          }),
        ]),
    react(),
  ],
  resolve: {
    alias: { '@': path.resolve(__dirname, 'resources/js') },
  },
  test: {
    environment: 'jsdom',
    include: ['resources/js/**/*.test.{ts,tsx}'],
  },
});
