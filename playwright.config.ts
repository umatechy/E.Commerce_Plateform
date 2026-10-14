import { defineConfig, devices } from '@playwright/test';

/**
 * Phase B53 (SRS TEST-009): end-to-end tests of the critical journeys,
 * against a running app prepared with `php artisan e2e:prepare`.
 * Locally: start `php artisan serve`, then `npm run e2e`. In CI: the e2e job.
 */
export default defineConfig({
  testDir: 'tests/e2e',
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
