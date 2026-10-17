import { expect, test, type Page } from '@playwright/test';
import { data, totp } from './support';

/**
 * Every store admin page, on a desktop and on a phone: it opens without a
 * script error or a server error, and the page never scrolls sideways
 * (UI-10 found four that did; this keeps them fixed).
 *
 * One sign-in for the whole run. It waits for a fresh two-step code
 * window first, because the journeys already used the current code and a
 * code is accepted only once.
 */
const PAGES = [
  '/', '/orders', '/orders/new', '/payments', '/returns', '/shipments', '/shipping',
  '/customers', '/customers/groups', '/products', '/products/new', '/collections', '/badges', '/reviews',
  '/categories', '/brands', '/attributes', '/inventory', '/warehouses',
  '/promotions', '/campaigns', '/segments', '/affiliates', '/notifications',
  '/content/pages', '/content/redirects', '/content/seo', '/storefront/theme', '/domains', '/storefront/app',
  '/apps', '/reports', '/team', '/team/roles', '/billing', '/backups', '/store-health',
  '/settings', '/settings/audit-log', '/settings/developer', '/settings/tax', '/settings/payments', '/settings/close-store',
  '/security', '/support', '/help',
];

const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'phone', width: 390, height: 844 },
];

async function signIn(page: Page): Promise<void> {
  const left = 30_000 - (Date.now() % 30_000);
  await page.waitForTimeout(left + 1_000);
  await page.goto('/login');
  await page.locator('#email').fill(data.owner.email);
  await page.locator('#password').fill(data.owner.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.locator('#code').fill(totp(data.owner.mfa_secret));
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.waitForURL((url) => !/login/.test(url.pathname));
}

test('every store admin page opens cleanly on a desktop and a phone', async ({ page }) => {
  test.setTimeout(PAGES.length * VIEWPORTS.length * 8_000 + 60_000);
  const problems: string[] = [];
  let current = '';
  page.on('pageerror', (error) => problems.push(`${current}: script error: ${error.message.slice(0, 160)}`));
  page.on('response', (response) => {
    if (response.status() >= 500) problems.push(`${current}: ${response.status()} from ${new URL(response.url()).pathname}`);
  });

  await signIn(page);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    for (const path of PAGES) {
      current = `${path} (${viewport.name})`;
      await page.goto(path, { waitUntil: 'networkidle' });
      await expect(page.locator('main#main')).toBeVisible();
      const sideways = await page.evaluate(() => {
        window.scrollTo(1000, window.scrollY);
        const x = window.scrollX;
        window.scrollTo(0, window.scrollY);

        return x;
      });
      if (sideways > 0) problems.push(`${current}: scrolls sideways by ${sideways}px`);
    }
  }

  expect(problems, problems.join('\n')).toEqual([]);
});
