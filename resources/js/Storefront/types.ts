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
  in_stock: boolean;
};

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
  description_html: string | null;
  images: Image[];
  options: { name: string; values: string[] }[];
  variants: Variant[];
  availability: Availability | null;
  purchasable: boolean;
  breadcrumbs: { name: string; slug: string }[];
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
  theme: { tokens: Record<string, string>; custom_css: string | null };
  announcement: string | null;
  navigation: { categories: CategoryNode[]; pages: { slug: string; title: string }[] };
  base_path: string;
  preview: boolean;
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
