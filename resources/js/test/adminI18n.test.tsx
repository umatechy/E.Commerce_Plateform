import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { adminTranslate, resetAdminLanguage, setAdminLanguage } from '@/lib/adminI18n';
import { adminUr } from '@/lib/adminUr';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { owner, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-18: the admin in Urdu. */
afterEach(() => {
  cleanup();
  window.localStorage.clear();
  resetAdminLanguage();
});

/** Every text passed to t('…') in a file that uses the admin translator. */
function adminTexts(): string[] {
  const files: string[] = [];
  const walk = (dir: string) => {
    for (const name of readdirSync(dir)) {
      const path = join(dir, name);
      if (statSync(path).isDirectory()) walk(path);
      else if (/\.tsx?$/.test(name) && !name.includes('.test.')) files.push(path);
    }
  };
  walk(join(process.cwd(), 'resources/js'));
  const keys = new Set<string>();
  for (const file of files) {
    const source = readFileSync(file, 'utf8');
    if (!source.includes('@/lib/adminI18n')) continue;
    for (const match of source.matchAll(/\bt\(\s*'((?:[^'\\]|\\.)*)'/g)) keys.add(match[1].replace(/\\'/g, "'"));
    for (const match of source.matchAll(/\bt\(\s*"((?:[^"\\]|\\.)*)"/g)) keys.add(match[1]);
    // t(cond ? 'a' : 'b'): both texts.
    for (const match of source.matchAll(/\bt\([^)]*?\?\s*'((?:[^'\\]|\\.)*)'\s*:\s*'((?:[^'\\]|\\.)*)'/g)) {
      keys.add(match[1]);
      keys.add(match[2]);
    }
  }

  // One-character pieces of a ternary inside t(…) are not texts.
  return [...keys].filter((key) => key.length > 1);
}

/** Texts the shared components translate when a page passes them (menu, cards, statuses…). */
function sharedTexts(): string[] {
  const nav = readFileSync(join(process.cwd(), 'resources/js/lib/adminNav.ts'), 'utf8');
  const fromNav = [...nav.matchAll(/(?:label|description): '((?:[^'\\]|\\.)*)'/g)].map((m) => m[1]);

  return [
    ...fromNav,
    // Dashboard figures, cards and setup steps (the server's check messages).
    'Revenue', 'Orders', 'Average order', 'New customers', 'Waiting for confirmation', 'Completed orders', 'Payments collected', 'Low on stock now',
    'Revenue by day', 'Recent orders', 'Store health', 'Package usage', 'OK',
    'Products', 'Team seats', 'Orders this month', 'Storage (GB)',
    'Today', 'Yesterday', 'Last 7 days', 'Last 30 days', 'This month', 'Last month', 'This year',
    // Order statuses as badges.
    'Pending confirmation', 'Confirmed', 'Processing', 'Ready to fulfill', 'Fulfilling', 'Shipped', 'Delivered', 'Completed', 'Cancelled', 'Failed',
    'Unpaid', 'Pending', 'Paid', 'Partially paid', 'Refunded', 'Unfulfilled', 'Fulfilled', 'Partially fulfilled', 'Active', 'Draft',
    // Order page.
    'Status', 'Payment', 'Fulfilment', 'Items', 'Customer', 'Timeline', 'Payments', 'Shipments', 'Item', 'Qty', 'Unit price', 'Discount', 'Total',
    'Order', 'Actions', 'Every order of your store. Open one to see its items, payments and deliveries.',
    'No orders match', 'Try another search or status.', 'No orders yet', 'Orders appear here once customers start buying.', 'Search', 'Order number, guest name or email', 'Any status', 'Any payment status',
    // Product and quick start fields.
    'Contact email', 'Phone or WhatsApp', 'Product name', 'How many you have', 'Your password', 'Current 6-digit code', 'Message', 'Message language', 'Text',
    'Ship to', 'Bill to', 'Created', 'Status changed', 'Payment status changed', 'Storefront', 'Admin', 'Indigo', 'Emerald', 'Slate',
  ];
}

describe('admin in Urdu', () => {
  it('has an Urdu text for every text the admin passes to t()', () => {
    const texts = adminTexts();
    expect(texts.length).toBeGreaterThan(150);
    expect(texts.filter((text) => !(text in adminUr))).toEqual([]);
  });

  it('has an Urdu text for the menu, figures, statuses and fields the shared components show', () => {
    expect(sharedTexts().filter((text) => !(text in adminUr))).toEqual([]);
  });

  it('keeps placeholders in the Urdu texts', () => {
    const broken = Object.entries(adminUr).filter(([en, ur]) => {
      const names = (s: string) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(',');

      return names(en) !== names(ur);
    });
    expect(broken.map(([en]) => en)).toEqual([]);
  });

  it('switches the whole admin to Urdu, right to left, from the account menu', () => {
    setPage(owner, '/orders');
    const { container } = render(<AuthenticatedLayout>content</AuthenticatedLayout>);
    expect(container.querySelector('.admin-root')?.getAttribute('dir')).toBe('ltr');

    fireEvent.click(screen.getByRole('button', { name: /GM Awan/ }));
    act(() => fireEvent.click(screen.getByLabelText('اردو')));

    expect(container.querySelector('.admin-root')?.getAttribute('dir')).toBe('rtl');
    expect(container.querySelector('.admin-root')?.getAttribute('lang')).toBe('ur');
    const nav = screen.getByRole('navigation', { name: 'Admin' });
    expect(within(nav).getByRole('link', { name: adminUr.Orders })).toBeTruthy();
    act(() => setAdminLanguage('en'));
  });

  it('fills placeholders and falls back to English', () => {
    expect(adminTranslate('ur', '{total} in total', { total: 4 })).toBe(adminUr['{total} in total'].replace('{total}', '4'));
    // Untranslated: English, isolated left to right inside the Urdu text.
    expect(adminTranslate('ur', 'A text nobody translated')).toBe('⁦A text nobody translated⁩');
    expect(adminTranslate('en', 'Orders')).toBe('Orders');
  });
});
