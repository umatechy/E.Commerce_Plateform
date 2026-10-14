import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, type Page } from '@playwright/test';

/**
 * Phase B53 (SRS TEST-009): what the journeys share — the data written by
 * `php artisan e2e:prepare` (random passwords, MFA secret and API key; never
 * in Git) and the two-step code an authenticator app would show.
 */
export type E2EData = {
  shop: string;
  store_name: string;
  product: { slug: string; name: string };
  owner: { email: string; password: string; mfa_secret: string };
  customer: { email: string; password: string };
  api_key: string;
};

export const data: E2EData = JSON.parse(readFileSync(join(process.cwd(), 'storage/app/e2e/credentials.json'), 'utf8'));

/** RFC 6238 TOTP (SHA-1, 30 s, 6 digits) from a base32 secret, as authenticator apps compute it. */
export function totp(secret: string, at: number = Date.now()): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((b) => parseInt(b, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 30000)));
  const hmac = createHmac('sha1', key).update(counter).digest();
  const offset = hmac[hmac.length - 1] & 0xf;
  const value = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

  return value.toString().padStart(6, '0');
}

/** Adds the product to the cart and places a cash-on-delivery order; returns the order number. */
export async function placeCodOrder(page: Page, contact: { name?: string; email?: string } = {}): Promise<string> {
  await page.goto(`${data.shop}/products/${data.product.slug}`);
  // Wait for the cart to accept the item before moving on.
  const added = page.waitForResponse((r) => r.url().includes('/cart/items') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click();
  expect((await added).ok()).toBe(true);
  await page.goto(`${data.shop}/checkout`);
  if (contact.name) await page.getByLabel('Full name').fill(contact.name);
  if (contact.email) await page.getByRole('textbox', { name: 'Email' }).fill(contact.email);
  await page.getByRole('textbox', { name: 'Phone' }).fill('0300 1234567');
  await page.getByLabel('Street address').fill('5 Mall Road');
  await page.getByLabel('City').fill('Lahore');
  await page.getByLabel('Province or region').fill('Punjab');
  await page.getByLabel('Country code').fill('PK');
  await expect(page.locator('aside').getByText('Total', { exact: true })).toBeVisible();
  await page.getByRole('radio', { name: /Cash on delivery/ }).check();
  await page.getByRole('button', { name: 'Place order', exact: true }).click();
  const placed = page.getByText(/has been placed/);
  await expect(placed).toBeVisible({ timeout: 30_000 });

  return ((await placed.innerText()).match(/order (\S+) has/) ?? [])[1] ?? '';
}
