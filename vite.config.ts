import { defineConfig } from 'vitest/config';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import path from 'path';

// Approved Stack: React + TypeScript + Inertia.js + Tailwind + shadcn/ui.
// laravel-vite-plugin supplies the entry points (there is no index.html)
// and writes public/build/manifest.json for the @vite Blade directive.
export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.tsx'],
      refresh: true,
    }),
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
