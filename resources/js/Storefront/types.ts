/** Shapes of the Module 05 storefront props (StorefrontPresenter / StorefrontExperience). */

export type Money = {
  currency: string;
  amount_minor: number | null;
  max_amount_minor: number | null;
  compare_at_minor: number | null;
  on_sale: boolean;
};

export type Image = { id: string; url: string; alt: string | null; width: number; height: number };

export type ProductCard = {
  id: string;
  slug: string;
  name: string;
  summary: string | null;
  brand: { name: string; slug: string } | null;
  price: Money;
  image: Image | null;
  /** Phase B36: shown on hover; absent in older fixtures. */
  hover_image?: Image | null;
  has_variants?: boolean;
  in_stock: boolean;
  /** Phase B43: in the server's order; absent in older fixtures (then the card falls back to sale / sold out). */
  badges?: ProductBadge[];
  /** Owner decision 15: from approved reviews; null without them or on Basic. */
  rating?: { average: number; count: number } | null;
};

/** Phase B43 (Module 06 §36). */
export type ProductBadge = { type: 'out_of_stock' | 'sale' | 'low_stock' | 'new' | 'bestseller' | 'featured' | 'custom'; label: string | null; tone: string; percent?: number };

export type Availability = 'in_stock' | 'low_stock' | 'out_of_stock' | 'backorder';

export type Variant = {
  id: string;
  sku: string | null;
  options: Record<string, string>;
  price_minor: number | null;
  compare_at_minor: number | null;
  availability: Availability;
  purchasable: boolean;
  image_id: string | null;
};

export type ProductDetail = ProductCard & {
  sku: string | null;
  /** Phase B41: the product's specifications, in the attributes' order. */
  specifications?: { name: string; group: string | null; value: string; colors: { name: string; code: string }[] }[];
  description_html: string | null;
  images: Image[];
  options: { name: string; values: string[] }[];
  variants: Variant[];
  availability: Availability | null;
  purchasable: boolean;
  breadcrumbs: { name: string; slug: string }[];
};

/** Phase P2: the store's loyalty program as shoppers see it. */
export type LoyaltyProgram = {
  earning: boolean;
  currency: string;
  earn_points: number;
  earn_spend_minor: number;
  redeem_points: number;
  redeem_value_minor: number;
  min_redeem_points: number;
  hold_days: number;
  expiry_days: number | null;
  birthday_points: number;
  repeat_every: number | null;
  repeat_points: number;
  tiers: { name: string; min_points: number; bonus_percent: number }[];
};

export type CategoryNode = {
  id: string;
  slug: string;
  name: string;
  description?: string;
  product_count?: number;
  in_menu: boolean;
  children: CategoryNode[];
};

export type Shell = {
  store: {
    name: string;
    slug: string;
    currency: string;
    locale: string | null;
    timezone: string;
    tagline: string | null;
    logo_url: string | null;
    favicon_url: string | null;
    social_links: Record<string, string>;
  };
  // Phase B36: the resolved presentation (Module 17/18); layout and motion are absent in older test fixtures.
  theme: {
    key?: string;
    name?: string;
    tokens: Record<string, string>;
    layout?: Partial<import('./theme').ThemeLayout>;
    motion?: Partial<import('./theme').ThemeMotion>;
    custom_css: string | null;
  };
  announcement: string | null;
  // Phase B38: the page language and the languages the store offers (absent in older fixtures).
  language?: { current: string; default: string; dir: 'ltr' | 'rtl'; offered: { code: string; name: string; native: string; dir: string; tag: string }[] };
  navigation: { categories: CategoryNode[]; pages: { slug: string; title: string }[] };
  base_path: string;
  preview: boolean;
  /** Phase B32 (Module 17 §19): until when this visit shows the draft theme; null for the published one. */
  theme_preview?: string | null;
  /** Phase B57 (Module 28 §12): arrived through an affiliate link: a token the server signed, kept for the attribution window. */
  referral?: { token: string; code: string; days: number } | null;
  /** Phase B57: the store's affiliate program takes applications (the footer links to its page). */
  affiliate_join?: boolean;
  /** Phase P2: how the store's loyalty points are earned and used, while it gives them (absent or null: no program). */
  loyalty?: LoyaltyProgram | null;
  /** Phase B55 (Module 25): the store's installable app; absent in older test fixtures. */
  pwa?: {
    enabled: boolean;
    manifest_url: string;
    worker_url: string;
    scope: string;
    cache_prefix?: string;
    theme_color: string;
    icon: string | null;
    install_prompt: boolean;
    mobile_nav: 'installed' | 'always' | 'off';
    /** The platform's push key when the store offers push; delivery (Module 21) is not built yet. */
    push_key: string | null;
  };
};

export type Seo = {
  title: string;
  description: string | null;
  canonical: string;
  og_title: string;
  og_description: string | null;
  og_image: string | null;
  robots: string;
};

export type StorefrontPageProps = { storefront: Shell; seo: Seo };
