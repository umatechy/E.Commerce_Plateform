import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

// Approved Stack: React + TypeScript + Inertia.js + Tailwind + shadcn/ui.
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: { '@': path.resolve(__dirname, 'resources/js') },
  },
  build: {
    outDir: 'public/build',
    manifest: true,
  },
});
