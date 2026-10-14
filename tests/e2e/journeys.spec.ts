import { expect, test } from '@playwright/test';
import { data, placeCodOrder, totp } from './support';

/**
 * Phase B53 (SRS TEST-009: "end-to-end tests shall cover critical customer
 * journeys"; Module 31 §66) — in a real browser against the running app:
 * a guest buys, a customer buys and finds the order in their account, the
 * store owner signs in with two-step sign-in and finds the order, and an
 * integration reads the developer API.
 */
test.describe.configure({ mode: 'serial' });

let guestOrder = '';

test('a guest finds a product, adds it to the cart and places a cash-on-delivery order', async ({ page }) => {
  await page.goto(data.shop);
  await expect(page).toHaveTitle(new RegExp(data.store_name));
  guestOrder = await placeCodOrder(page, { name: 'Ayesha Guest', email: `guest.${Date.now()}@example.test` });
  expect(guestOrder).toMatch(/\S+/);
  await expect(page.getByText(/Cash on delivery|Pay when your order arrives|confirmation has been sent/i).first()).toBeVisible();
});

test('a customer signs in, orders and finds the order in their account', async ({ page }) => {
  await page.goto(`${data.shop}/account/login`);
  await page.getByLabel('Email').fill(data.customer.email);
  await page.getByLabel('Password').fill(data.customer.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`${data.shop}/account`));

  const number = await placeCodOrder(page);
  await page.goto(`${data.shop}/account/orders`);
  await expect(page.getByRole('heading', { name: 'Your orders' })).toBeVisible();
  await expect(page.getByText(number)).toBeVisible();
});

test('the store owner signs in with two-step sign-in and finds the guest order', async ({ page }) => {
  await page.goto('/login');
  await page.locator('#email').fill(data.owner.email);
  await page.locator('#password').fill(data.owner.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.locator('#code').fill(totp(data.owner.mfa_secret));
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.waitForURL((url) => !/login/.test(url.pathname));

  await page.goto('/orders');
  await expect(page.getByText(guestOrder).first()).toBeVisible();
});

test('an integration reads the developer API and gets the documented error shape', async ({ request }) => {
  const products = await request.get('/api/dev/v1/products?per_page=5', { headers: { Authorization: `Bearer ${data.api_key}`, Accept: 'application/json' } });
  expect(products.status()).toBe(200);
  const body = await products.json();
  expect(Array.isArray(body.data)).toBe(true);
  expect(body.meta.per_page).toBe(5);
  expect(products.headers()['x-request-id']).toBeTruthy();

  const refused = await request.get('/api/dev/v1/customers', { headers: { Authorization: `Bearer ${data.api_key}`, Accept: 'application/json' } });
  expect(refused.status()).toBe(403);
  expect(await refused.json()).toMatchObject({ code: 'insufficient_scope', message: expect.any(String), details: {}, requestId: refused.headers()['x-request-id'] });

  const spec = await (await request.get('/api/dev/v1/openapi.json')).json();
  expect(spec.openapi).toBe('3.1.0');
  expect(Object.keys(spec.paths)).toContain('/products');
});
