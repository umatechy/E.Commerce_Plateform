import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { translate } from '@/Storefront/i18n';
import { ur } from '@/Storefront/i18n/ur';
import { categoryLabel, STORE_CATEGORIES, STATUSES, statusLabel } from '@/lib/support';
import { REASON_LABELS, RESOLUTION_LABELS, RETURN_STATUS_LABELS } from '@/lib/returns';
import { paymentMethodLabel, statusLabel as orderStatusLabel } from '@/Storefront/orders';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import Price from '@/Components/Storefront/Price';
import { owner, setPage } from '@/test/inertiaMock';
import type { Seo, Shell } from '@/Storefront/types';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

afterEach(cleanup);

/** Every text passed to t('…') in a file that uses the storefront translator. */
function storefrontTexts(): string[] {
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
    if (!source.includes('@/Storefront/i18n')) continue;
    for (const match of source.matchAll(/\bt\('((?:[^'\\]|\\.)*)'/g)) keys.add(match[1].replace(/\\'/g, "'"));
  }

  return [...keys];
}

describe('storefront translations (Phase B38)', () => {
  it('has an Urdu text for every text the storefront passes to t()', () => {
    const texts = storefrontTexts();
    expect(texts.length).toBeGreaterThan(250);
    expect(texts.filter((text) => !(text in ur))).toEqual([]);
  });

  it('has an Urdu text for the labels shown from shared lists', () => {
    const labels = [
      ...STORE_CATEGORIES.map((category) => categoryLabel(category)),
      ...STATUSES.map((status) => statusLabel(status)),
      ...Object.values(RETURN_STATUS_LABELS),
      ...Object.values(RESOLUTION_LABELS),
      ...Object.values(REASON_LABELS),
      ...['pending_confirmation', 'confirmed', 'processing', 'ready_to_fulfill', 'fulfilling', 'shipped', 'delivered', 'completed', 'cancelled', 'return_requested', 'refund_pending', 'refunded'].map(orderStatusLabel),
      ...['cod', 'bank_transfer', 'mock_redirect'].map(paymentMethodLabel),
      'Overview', 'Orders', 'Addresses', 'Wishlist', 'Support', 'Profile & security',
    ];
    expect(labels.filter((label) => !(label in ur))).toEqual([]);
  });

  it('keeps placeholders in every Urdu text that has them in English', () => {
    const broken = Object.entries(ur).filter(([english, urdu]) => {
      const wanted = english.match(/\{\w+\}/g) ?? [];

      return wanted.some((placeholder) => !urdu.includes(placeholder));
    });
    expect(broken).toEqual([]);
  });

  it('fills placeholders and falls back to English for a missing text or language', () => {
    expect(translate('ur', '{count} products', { count: 5 })).toBe('5 مصنوعات');
    expect(translate('ur', 'A text nobody translated')).toBe('A text nobody translated');
    expect(translate('en', 'Add to cart')).toBe('Add to cart');
    expect(translate(undefined, 'Page {page} of {last}', { page: 2, last: 9 })).toBe('Page 2 of 9');
  });
});

describe('an Urdu storefront page', () => {
  const shell = {
    store: { name: 'Demo', slug: 'demo', currency: 'PKR', locale: 'ur', timezone: 'Asia/Karachi', tagline: null, logo_url: null, favicon_url: null, social_links: {} },
    theme: { tokens: {}, custom_css: null },
    announcement: null,
    language: {
      current: 'ur',
      default: 'en',
      dir: 'rtl',
      offered: [
        { code: 'en', name: 'English', native: 'English', dir: 'ltr', tag: 'en-PK' },
        { code: 'ur', name: 'Urdu', native: 'اردو', dir: 'rtl', tag: 'ur-PK' },
      ],
    },
    navigation: { categories: [], pages: [] },
    base_path: '/shop/demo',
    preview: false,
  } as unknown as Shell;
  const seo = { title: 'Demo', description: null, canonical: '/', robots: 'index, follow' } as unknown as Seo;

  it('is right to left in Urdu, links to the English page, and shows the interface in Urdu', () => {
    setPage({ ...owner }, '/shop/demo');
    const { container } = render(
      <PageProps storefront={shell}>
        <StoreLayout shell={shell} seo={seo}>
          <Price price={{ currency: 'PKR', amount_minor: null, max_amount_minor: null, compare_at_minor: null, on_sale: false }} />
        </StoreLayout>
      </PageProps>,
    );

    const root = container.querySelector('.sf-root') as HTMLElement;
    expect(root.getAttribute('dir')).toBe('rtl');
    expect(root.getAttribute('lang')).toBe('ur');
    expect(document.documentElement.dir).toBe('rtl');
    expect(screen.getByRole('link', { name: 'English' }).getAttribute('href')).toContain('lang=en');
    expect(screen.getByRole('link', { name: 'کارٹ' })).toBeTruthy();
    expect(screen.getByText('قیمت درخواست پر')).toBeTruthy();
  });
});

/** Sets the Inertia page props for the components below (the mock reads them through usePage). */
function PageProps({ storefront, children }: { storefront: Shell; children: React.ReactNode }) {
  setPageProps({ storefront });

  return <>{children}</>;
}

function setPageProps(props: Record<string, unknown>) {
  setPage({ ...owner }, '/shop/demo', props);
}
