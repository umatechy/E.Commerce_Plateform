/**
 * Module 17/18 admin (Phase B36): the store theme's configuration as the
 * server stores it (ThemeConfigValidator), and the choices each field has
 * (App\Domain\Theme\Support\ThemeOptions — the server checks them again,
 * with the package).
 */
export type SectionItem = Record<string, string>;
// Phase B38: config.translations = { [locale]: { field: text, items: [...] } }.
export type SectionTranslations = Record<string, Record<string, string | SectionItem[]>>;
export type Section = { type: string; position: number; is_visible: boolean; config: Record<string, string | number | SectionItem[] | SectionTranslations> };
export type Branding = { logo_url?: string; favicon_url?: string; tagline?: string; social_links?: Record<string, string>; translations?: Record<string, { tagline?: string }> };
export type Layout = Record<string, string | number | boolean>;
export type Motion = Record<string, string | boolean>;
export type Config = { theme?: string; tokens: Record<string, string>; layout?: Layout; motion?: Motion; branding: Branding; sections: Section[] };
export type StoreTheme = { theme: string; draft_config: Config; published_config: Config; custom_css: string | null; is_published: boolean; published_at: string | null; translation_languages?: { code: string; name: string; native: string; dir: string }[] };
export type LibraryTheme = {
  key: string;
  name: string;
  description: string | null;
  tier: 'basic' | 'business' | 'premium';
  version: string;
  included: boolean;
  published: boolean;
  in_draft: boolean;
  tokens: Record<string, string>;
  layout: Layout;
  motion: Motion;
};

export const TIER_LABEL: Record<LibraryTheme['tier'], string> = { basic: 'Every package', business: 'Business and Premium', premium: 'Premium' };

export const FONTS = ['system-ui', 'Inter', 'Roboto', 'Poppins', 'Montserrat', 'DM Sans', 'Georgia', 'Playfair Display', 'Merriweather', 'Lora'];

type Choice = { value: string; label: string; feature?: string };

export const RADIUS: Choice[] = [{ value: 'none', label: 'Square' }, { value: 'sm', label: 'Small' }, { value: 'md', label: 'Medium' }, { value: 'lg', label: 'Large' }];
export const SHADOW: Choice[] = [{ value: 'none', label: 'None' }, { value: 'subtle', label: 'Subtle' }, { value: 'medium', label: 'Medium' }, { value: 'strong', label: 'Strong' }];
export const DENSITY: Choice[] = [{ value: 'compact', label: 'Compact' }, { value: 'standard', label: 'Standard' }, { value: 'comfortable', label: 'Comfortable (more space)' }];

/** Layout fields; `feature` marks a premium layout (`layout.advanced`). */
export const LAYOUT_FIELDS: { key: string; label: string; hint?: string; choices: Choice[] }[] = [
  {
    key: 'header_style',
    label: 'Header',
    choices: [
      { value: 'classic', label: 'Classic — logo, search, menu below' },
      { value: 'minimal', label: 'Minimal — one slim row' },
      { value: 'centered', label: 'Centred logo', feature: 'layout.advanced' },
      { value: 'split', label: 'Coloured bar', feature: 'layout.advanced' },
    ],
  },
  {
    key: 'hero_style',
    label: 'Hero (top of the home page)',
    choices: [
      { value: 'simple', label: 'Simple' },
      { value: 'centered', label: 'Centred' },
      { value: 'split', label: 'Split — text beside a colour panel or image', feature: 'layout.advanced' },
      { value: 'fullbleed', label: 'Full width colour', feature: 'layout.advanced' },
    ],
  },
  {
    key: 'product_card',
    label: 'Product cards',
    choices: [
      { value: 'standard', label: 'Standard — bordered' },
      { value: 'minimal', label: 'Minimal — image and text' },
      { value: 'elevated', label: 'Elevated — soft shadow', feature: 'layout.advanced' },
      { value: 'overlay', label: 'Overlay — name on the image', feature: 'layout.advanced' },
    ],
  },
  { key: 'grid_columns', label: 'Products per row (large screens)', choices: [{ value: '3', label: '3' }, { value: '4', label: '4' }] },
  { key: 'container', label: 'Page width', choices: [{ value: 'narrow', label: 'Narrow' }, { value: 'standard', label: 'Standard' }, { value: 'wide', label: 'Wide' }] },
  { key: 'footer_style', label: 'Footer', choices: [{ value: 'simple', label: 'Simple' }, { value: 'columns', label: 'Columns — shop, help, account' }] },
];

/** Module 18 §8 profiles; `feature` is what the package needs. */
export const MOTION_PROFILES: (Choice & { description: string })[] = [
  { value: 'none', label: 'None', description: 'No animation at all.' },
  { value: 'minimal', label: 'Minimal', description: 'Quick fades and a small image zoom on hover.' },
  { value: 'standard', label: 'Standard', description: 'Polished: cards lift on hover, pages and sections ease in.', feature: 'animation.advanced' },
  { value: 'premium', label: 'Premium', description: 'Slower, smoother entries with a refined feel.', feature: 'animation.premium' },
  { value: 'playful', label: 'Playful', description: 'Lively, with a gentle spring. For bold, youthful stores.', feature: 'animation.premium' },
];
export const MOTION_INTENSITY: Choice[] = [{ value: 'low', label: 'Low' }, { value: 'medium', label: 'Medium' }, { value: 'high', label: 'High', feature: 'animation.premium' }];

/** The fields each section type has (ThemeConfigValidator::validateSectionConfig). */
export const SECTION_FIELDS: Record<string, { key: string; label: string; kind?: 'url' | 'number' | 'textarea' | 'image' | 'source' | 'collection' }[]> = {
  announcement_bar: [{ key: 'message', label: 'Message' }],
  hero: [{ key: 'heading', label: 'Heading' }, { key: 'subheading', label: 'Subheading' }, { key: 'cta_label', label: 'Button text' }, { key: 'cta_url', label: 'Button link', kind: 'url' }, { key: 'image_url', label: 'Image', kind: 'image' }],
  promotional_banner: [{ key: 'heading', label: 'Heading' }, { key: 'subheading', label: 'Text' }, { key: 'cta_label', label: 'Button text' }, { key: 'cta_url', label: 'Link', kind: 'url' }, { key: 'image_url', label: 'Image', kind: 'image' }],
  newsletter: [{ key: 'heading', label: 'Heading' }, { key: 'subheading', label: 'Subheading' }],
  // Phase B39: which products — the newest, those marked featured, or one collection's.
  featured_products: [{ key: 'heading', label: 'Heading' }, { key: 'limit', label: 'How many (1–50)', kind: 'number' }, { key: 'source', label: 'Products', kind: 'source' }, { key: 'collection', label: 'Collection', kind: 'collection' }],
  featured_categories: [{ key: 'heading', label: 'Heading' }, { key: 'limit', label: 'How many (1–50)', kind: 'number' }],
  best_sellers: [{ key: 'heading', label: 'Heading' }, { key: 'limit', label: 'How many (1–50)', kind: 'number' }],
  sale_products: [{ key: 'heading', label: 'Heading' }, { key: 'limit', label: 'How many (1–50)', kind: 'number' }],
  featured_brands: [{ key: 'heading', label: 'Heading' }, { key: 'limit', label: 'How many (1–50)', kind: 'number' }],
  testimonials: [{ key: 'heading', label: 'Heading' }],
  faq: [{ key: 'heading', label: 'Heading' }],
  rich_text: [{ key: 'heading', label: 'Heading' }, { key: 'text', label: 'Text', kind: 'textarea' }],
  trust_badges: [],
  header: [],
  footer: [],
};

/** Sections with a list of entries: fields, the most entries, and the required ones. */
export const SECTION_ITEMS: Record<string, { max: number; noun: string; fields: { key: string; label: string; kind?: 'textarea' | 'icon'; required?: boolean }[] }> = {
  testimonials: { max: 6, noun: 'testimonial', fields: [{ key: 'quote', label: 'What they said', kind: 'textarea', required: true }, { key: 'name', label: 'Name', required: true }, { key: 'detail', label: 'City or detail' }] },
  faq: { max: 12, noun: 'question', fields: [{ key: 'question', label: 'Question', required: true }, { key: 'answer', label: 'Answer', kind: 'textarea', required: true }] },
  trust_badges: { max: 4, noun: 'badge', fields: [{ key: 'icon', label: 'Icon', kind: 'icon', required: true }, { key: 'title', label: 'Title', required: true }, { key: 'text', label: 'Short text' }] },
};

export const BADGE_ICONS: Choice[] = [
  { value: 'delivery', label: 'Delivery van' },
  { value: 'cod', label: 'Cash on delivery' },
  { value: 'returns', label: 'Easy returns' },
  { value: 'secure', label: 'Secure / trusted' },
  { value: 'support', label: 'Customer support' },
  { value: 'quality', label: 'Quality star' },
];

export const BASIC_SECTIONS = ['announcement_bar', 'hero', 'featured_products', 'featured_categories', 'promotional_banner', 'newsletter'];
export const ADVANCED_SECTIONS = ['trust_badges', 'best_sellers', 'sale_products', 'featured_brands', 'testimonials', 'faq', 'rich_text'];

/** The fixed order of a store that may not reorder sections (ThemeOptions::CANONICAL_ORDER). */
export const CANONICAL_ORDER = ['announcement_bar', 'header', 'hero', 'trust_badges', 'featured_categories', 'featured_products', 'best_sellers', 'sale_products', 'promotional_banner', 'featured_brands', 'testimonials', 'rich_text', 'faq', 'newsletter', 'footer'];

export function canonicalSort(sections: Section[]): Section[] {
  return sections
    .map((section, index) => ({ section, index }))
    .sort((a, b) => CANONICAL_ORDER.indexOf(a.section.type) - CANONICAL_ORDER.indexOf(b.section.type) || a.index - b.index)
    .map(({ section }) => section);
}

/** WCAG relative luminance contrast of two #RRGGBB colours (Module 17 §5: warn below 4.5:1). */
export function contrast(a: string, b: string): number | null {
  const lum = (hex: string) => {
    if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return null;
    const [r, g, bl] = [1, 3, 5].map((i) => {
      const c = parseInt(hex.slice(i, i + 2), 16) / 255;

      return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
  };
  const [la, lb] = [lum(a), lum(b)];
  if (la === null || lb === null) return null;

  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}
