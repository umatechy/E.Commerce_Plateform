import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import Theme, { toPayload } from '@/Pages/Storefront/Theme';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import ProductCard from '@/Components/Storefront/ProductCard';
import Reveal from '@/Components/Storefront/Reveal';
import { StorefrontThemeContext, themeStyle } from '@/Storefront/theme';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';
import type { LibraryTheme, StoreTheme } from '@/lib/theme';
import type { ProductCard as Card, Seo, Shell } from '@/Storefront/types';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

const BASIC_FEATURES = { 'themes.business': false, 'themes.premium': false, 'layout.advanced': false, 'homepage.advanced_sections': false, 'homepage.reorder': false, 'animation.advanced': false, 'animation.premium': false, 'theme.custom_css': false };
const PREMIUM_FEATURES = Object.fromEntries(Object.keys(BASIC_FEATURES).map((key) => [key, true]));

const libraryTheme = (key: string, name: string, tier: LibraryTheme['tier'], included: boolean, extra: Partial<LibraryTheme> = {}): LibraryTheme => ({
  key, name, tier, included, description: `${name} theme`, version: '1.0.0', published: key === 'default', in_draft: key === 'default',
  tokens: { primary: '#111827', accent: '#1D4ED8', background: '#FFFFFF', surface: '#F9FAFB', text: '#111827', secondary: '#6B7280', border: '#E5E7EB', radius: 'md', heading_font: 'Inter', font_family: 'Inter' },
  layout: { header_style: 'classic', product_card: 'standard', hero_style: 'simple', grid_columns: 4, container: 'standard', footer_style: 'simple', sticky_header: false },
  motion: { profile: 'minimal', intensity: 'medium', reveal_on_scroll: false, hover_effects: true },
  ...extra,
});

function library(premium: boolean): LibraryTheme[] {
  return [
    libraryTheme('default', 'Classic', 'basic', true),
    libraryTheme('minimal', 'Minimal', 'basic', true),
    libraryTheme('modern', 'Modern', 'business', premium),
    libraryTheme('boutique', 'Boutique', 'premium', premium),
    libraryTheme('bold', 'Bold', 'premium', premium),
  ];
}

const storeTheme = (overrides: Partial<StoreTheme['draft_config']> = {}): StoreTheme => {
  const config = { theme: 'default', tokens: {}, branding: {}, sections: [{ type: 'header', position: 0, is_visible: true, config: {} }, { type: 'hero', position: 1, is_visible: true, config: { heading: 'Hi' } }, { type: 'footer', position: 2, is_visible: true, config: {} }], ...overrides };

  return { theme: 'default', draft_config: config, published_config: config, custom_css: null, is_published: true, published_at: '2026-10-01T10:00:00Z' };
};

function openThemePage(premium: boolean, extraRoutes: Parameters<typeof routeFetch>[0] = {}, theme = storeTheme()) {
  setPage({ ...owner, features: premium ? PREMIUM_FEATURES : BASIC_FEATURES }, '/theme');
  const fetchMock = routeFetch({
    '/store/theme': () => json(200, { data: theme }),
    '/store/themes': () => json(200, { data: library(premium) }),
    '/store/theme/publications': () => json(200, { data: [] }),
    ...extraRoutes,
  });
  vi.stubGlobal('fetch', fetchMock);
  render(<Theme />);

  return fetchMock;
}

beforeEach(() => {
  window.history.replaceState(null, '', '/');
});

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

describe('theme library (admin)', () => {
  it('shows every theme, locks those outside the package and puts a chosen one in the draft', async () => {
    let chosen: unknown = null;
    openThemePage(false, {
      'POST /store/theme/select': (_url, init) => {
        chosen = JSON.parse(init.body as string);

        return json(200, { data: storeTheme({ theme: 'minimal' }) });
      },
    });

    const cards = await screen.findAllByRole('heading', { level: 3 });
    expect(cards.map((h) => h.textContent)).toEqual(['Classic', 'Minimal', 'Modern', 'Boutique', 'Bold']);
    expect(screen.getAllByText(/Needs the (Premium|Business and Premium) package/)).toHaveLength(3);
    expect(screen.queryByRole('button', { name: /Use this theme.*Boutique/ })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: /Use this theme.*Minimal/ }));
    await waitFor(() => expect(chosen).toEqual({ theme: 'minimal' }));
  });

  it('marks premium layouts and animation as unavailable on Basic', async () => {
    openThemePage(false);
    await screen.findAllByRole('heading', { level: 3 });

    fireEvent.click(screen.getByRole('tab', { name: 'Layout' }));
    const header = screen.getByLabelText('Header') as HTMLSelectElement;
    const centred = Array.from(header.options).find((o) => o.value === 'centered');
    expect(centred?.disabled).toBe(true);
    expect(centred?.textContent).toContain('(Premium)');
    expect(Array.from(header.options).find((o) => o.value === 'minimal')?.disabled).toBe(false);

    fireEvent.click(screen.getByRole('tab', { name: 'Animation' }));
    expect((screen.getByRole('radio', { name: /Premium/ }) as HTMLInputElement).disabled).toBe(true);
    expect((screen.getByRole('radio', { name: /Minimal/ }) as HTMLInputElement).disabled).toBe(false);
    expect((screen.getByLabelText(/ease in as customers scroll/) as HTMLInputElement).disabled).toBe(true);
  });

  it('lets a Premium store reorder sections and add testimonials, and saves what it shows', async () => {
    let saved: { config: ReturnType<typeof toPayload> } | null = null;
    openThemePage(true, {
      'PUT /store/theme/draft': (_url, init) => {
        saved = JSON.parse(init.body as string);

        return json(200, { data: storeTheme() });
      },
    });
    await screen.findAllByRole('heading', { level: 3 });
    fireEvent.click(screen.getByRole('tab', { name: 'Home page' }));

    expect(screen.getByRole('button', { name: 'Move Hero up' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Add a section'), { target: { value: 'testimonials' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add' }));
    const added = document.querySelector('[data-section="testimonials"]') as HTMLElement;
    fireEvent.click(within(added).getByRole('button', { name: 'Add a testimonial' }));
    fireEvent.change(within(added).getByLabelText('What they said'), { target: { value: 'Lovely' } });
    fireEvent.change(within(added).getByLabelText('Name'), { target: { value: 'Sana' } });
    fireEvent.click(screen.getByRole('button', { name: 'Move Hero up' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));

    await waitFor(() => expect(saved).not.toBeNull());
    const sections = saved!.config.sections;
    expect(sections.map((s) => s.type)).toEqual(['hero', 'header', 'footer', 'testimonials']);
    expect(sections[3].config.items).toEqual([{ quote: 'Lovely', name: 'Sana' }]);
  }, 15000); // a long UI flow: over 5 s when the whole suite runs in parallel on a slow machine

  it('keeps the fixed order on Basic: no move buttons, advanced sections offered but disabled', async () => {
    openThemePage(false);
    await screen.findAllByRole('heading', { level: 3 });
    fireEvent.click(screen.getByRole('tab', { name: 'Home page' }));

    expect(screen.queryByRole('button', { name: /Move Hero/ })).toBeNull();
    const add = screen.getByLabelText('Add a section') as HTMLSelectElement;
    expect(Array.from(add.options).find((o) => o.value === 'faq')?.disabled).toBe(true);
    expect(screen.getByText(/More sections come with the Business and Premium packages/)).toBeTruthy();
    expect(screen.getByText(/On your package sections appear in a fixed order/)).toBeTruthy();
  });

  it('builds the payload without empty values or empty list entries', () => {
    const payload = toPayload({
      theme: 'bold',
      tokens: { primary: '', accent: '#123456' },
      layout: { header_style: 'split' },
      motion: {},
      branding: {},
      sections: [{ type: 'faq', position: 5, is_visible: true, config: { heading: '', items: [{ question: 'Q', answer: 'A' }, { question: '' }] } }],
    });
    expect(payload).toEqual({
      theme: 'bold',
      tokens: { accent: '#123456' },
      layout: { header_style: 'split' },
      branding: {},
      sections: [{ type: 'faq', position: 0, is_visible: true, config: { items: [{ question: 'Q', answer: 'A' }] } }],
    });
  });
});

const seo = { title: 'Shop', description: null, canonical: '/', robots: 'index, follow' } as unknown as Seo;
const shellWith = (layout: Record<string, unknown> = {}, motion: Record<string, unknown> = {}): Shell =>
  ({
    store: { name: 'Acme', slug: 'acme', currency: 'PKR', locale: null, timezone: 'UTC', tagline: 'Good things', logo_url: null, favicon_url: null, social_links: {} },
    theme: { key: 'boutique', name: 'Boutique', tokens: { primary: '#3F2A1D', heading_font: 'Poppins', font_family: 'DM Sans', radius: 'sm', shadow: 'subtle', density: 'comfortable' }, layout, motion, custom_css: null },
    announcement: null,
    navigation: { categories: [{ id: 'c1', slug: 'attar', name: 'Attar', product_count: 3, in_menu: true, children: [] }], pages: [] },
    base_path: '/shop/acme',
    preview: false,
  }) as unknown as Shell;

const product = (overrides: Partial<Card> = {}): Card => ({
  id: '01JPRODUCT0000000000000001', slug: 'rose', name: 'Rose Attar', summary: null, brand: null,
  price: { currency: 'PKR', amount_minor: 250000, max_amount_minor: null, compare_at_minor: null, on_sale: false },
  image: { id: 'i1', url: '/a.png', alt: 'Rose', width: 800, height: 800 } as Card['image'],
  hover_image: { id: 'i2', url: '/b.png', alt: null, width: 800, height: 800 } as Card['hover_image'],
  has_variants: false, in_stock: true, ...overrides,
});

describe('storefront theme rendering', () => {
  it('renders the header and footer the layout asks for, with theme variables and motion attributes', () => {
    setPage(owner, '/shop/acme');
    const { container, rerender } = render(<StoreLayout shell={shellWith({ header_style: 'centered', footer_style: 'columns' }, { profile: 'premium' })} seo={seo}>x</StoreLayout>);
    const root = container.querySelector('.sf-root') as HTMLElement;

    expect(container.querySelector('[data-header="centered"]')).toBeTruthy();
    expect(container.querySelector('[data-footer="columns"]')).toBeTruthy();
    expect(root.dataset.motion).toBe('premium');
    expect(root.style.getPropertyValue('--sf-primary')).toBe('#3F2A1D');
    expect(root.style.getPropertyValue('--sf-font-heading')).toContain('Poppins');
    expect(root.style.getPropertyValue('--sf-dur-normal')).toBe('480ms');

    rerender(<StoreLayout shell={shellWith({ header_style: 'split' }, { profile: 'none' })} seo={seo}>x</StoreLayout>);
    expect(container.querySelector('[data-header="split"]')).toBeTruthy();
    expect((container.querySelector('.sf-root') as HTMLElement).style.getPropertyValue('--sf-dur-normal')).toBe('0ms');
    expect((container.querySelector('.sf-root') as HTMLElement).dataset.hover).toBe('off');
  });

  it('scales motion with intensity and never staggers past the cap', () => {
    const high = themeStyle(shellWith({}, { profile: 'playful', intensity: 'high' })).style as Record<string, string>;
    const low = themeStyle(shellWith({}, { profile: 'playful', intensity: 'low' })).style as Record<string, string>;
    expect(parseInt(high['--sf-distance'], 10)).toBeGreaterThan(parseInt(low['--sf-distance'], 10));
  });

  it('adds to the cart from the card and says "Added" only after the server agreed', async () => {
    setPage(owner, '/shop/acme');
    let body: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      'POST /cart/items': (_url, init) => {
        body = JSON.parse(init.body as string);

        return json(201, { data: { token: 't' } });
      },
    }));
    const shell = shellWith({ product_card: 'overlay' });
    render(
      <StorefrontThemeContext.Provider value={{ shell, layout: { header_style: 'classic', container: 'standard', product_card: 'overlay', grid_columns: 4, hero_style: 'simple', sticky_header: false, footer_style: 'simple' }, motion: { profile: 'premium', intensity: 'medium', reveal_on_scroll: false, hover_effects: true }, reveal: false }}>
        <ProductCard product={product()} basePath="/shop/acme" />
      </StorefrontThemeContext.Provider>,
    );

    expect(document.querySelector('[data-card="overlay"]')).toBeTruthy();
    expect(screen.getByText('Rs. 2,500')).toBeTruthy(); // the price stays visible on the image
    fireEvent.click(screen.getByRole('button', { name: /Add to cart/ }));
    expect(await screen.findByRole('button', { name: /Added/ })).toBeTruthy();
    expect(body).toEqual({ product: '01JPRODUCT0000000000000001', quantity: 1 });
  });

  it('sends a product with variants to its page and shows a sold-out one as such', () => {
    setPage(owner, '/shop/acme');
    const shell = shellWith();
    const context = { shell, layout: { header_style: 'classic', container: 'standard', product_card: 'standard', grid_columns: 4, hero_style: 'simple', sticky_header: false, footer_style: 'simple' } as const, motion: { profile: 'minimal', intensity: 'medium', reveal_on_scroll: false, hover_effects: true } as const, reveal: false };
    render(
      <StorefrontThemeContext.Provider value={context}>
        <ProductCard product={product({ has_variants: true })} basePath="/shop/acme" />
        <ProductCard product={product({ id: 'x', slug: 'gone', name: 'Gone', in_stock: false })} basePath="/shop/acme" />
      </StorefrontThemeContext.Provider>,
    );
    expect(screen.getByRole('link', { name: /Choose options/ }).getAttribute('href')).toBe('/shop/acme/products/rose');
    expect((screen.getAllByRole('button', { name: 'Sold out' })[0] as HTMLButtonElement).disabled).toBe(true);
  });

  it('reveals on scroll only when enabled, and never hides anything for reduced motion', () => {
    const observe = vi.fn();
    vi.stubGlobal('IntersectionObserver', vi.fn(() => ({ observe, unobserve: vi.fn(), disconnect: vi.fn() })));
    const shell = shellWith({}, { profile: 'standard', reveal_on_scroll: true });
    const context = (reveal: boolean) => ({ shell, layout: { header_style: 'classic', container: 'standard', product_card: 'standard', grid_columns: 4, hero_style: 'simple', sticky_header: false, footer_style: 'simple' } as const, motion: { profile: 'standard', intensity: 'medium', reveal_on_scroll: reveal, hover_effects: true } as const, reveal });

    window.matchMedia = vi.fn(() => ({ matches: false }) as unknown as MediaQueryList);
    const { container, unmount } = render(<StorefrontThemeContext.Provider value={context(true)}><Reveal index={20}>A</Reveal></StorefrontThemeContext.Provider>);
    const element = container.firstElementChild as HTMLElement;
    expect(element.classList.contains('sf-reveal-pending')).toBe(true);
    expect(element.style.getPropertyValue('--sf-delay')).toBe(`${7 * 60}ms`); // capped at 8 steps
    unmount();

    window.matchMedia = vi.fn(() => ({ matches: true }) as unknown as MediaQueryList);
    const reduced = render(<StorefrontThemeContext.Provider value={context(true)}><Reveal>B</Reveal></StorefrontThemeContext.Provider>);
    expect((reduced.container.firstElementChild as HTMLElement).classList.contains('sf-reveal-pending')).toBe(false);
    reduced.unmount();

    const off = render(<StorefrontThemeContext.Provider value={context(false)}><Reveal>C</Reveal></StorefrontThemeContext.Provider>);
    expect((off.container.firstElementChild as HTMLElement).classList.contains('sf-reveal-pending')).toBe(false);
  });
});
