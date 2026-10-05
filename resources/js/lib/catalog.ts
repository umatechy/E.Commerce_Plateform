/**
 * Shapes of the catalog API (ProductResource, ProductVariantResource,
 * CategoryResource, BrandResource, AttributeResource) and the values its
 * requests accept (StoreProductRequest and friends). Kept in one place so
 * every catalog page reads the same contract.
 */
export const PRODUCT_TYPES = ['simple', 'variable', 'digital', 'service', 'bundle'] as const;
export const PRODUCT_STATUSES = ['draft', 'active', 'scheduled', 'hidden', 'archived'] as const;
export const PRODUCT_VISIBILITIES = ['public', 'catalog_only', 'search_only', 'hidden', 'private', 'scheduled'] as const;
export const VARIANT_STATUSES = ['active', 'hidden', 'archived'] as const;
export const CATEGORY_STATUSES = ['draft', 'active', 'hidden', 'scheduled', 'archived'] as const;
export const CATEGORY_VISIBILITIES = ['public', 'navigation_only', 'search_only', 'hidden', 'private', 'scheduled'] as const;
export const ATTRIBUTE_TYPES = ['select', 'multi_select', 'color', 'boolean', 'numeric', 'text'] as const;

export type Variant = {
  id: string;
  /** The numeric key the stock and order APIs take. */
  internal_id: number;
  sku: string | null;
  barcode: string | null;
  price_minor: number | null;
  sale_price_minor: number | null;
  effective_price_minor: number | null;
  /** Present only for users who may see cost prices. */
  cost_price_minor?: number | null;
  weight: string | number | null;
  status: string;
  option_values: Record<string, string> | null;
};

export type Product = {
  id: string;
  internal_id: number;
  type: string;
  name: string;
  slug: string;
  sku: string | null;
  short_description: string | null;
  description: string | null;
  status: string;
  visibility: string;
  brand?: { id: string; name: string } | null;
  brand_id: number | null;
  primary_category_id: number | null;
  category_ids?: number[];
  price_minor: number | null;
  sale_price_minor: number | null;
  effective_price_minor: number | null;
  currency: string | null;
  cost_price_minor?: number | null;
  variants?: Variant[];
  /** Phase B39. */
  is_featured?: boolean;
  tags?: string[];
  collection_ids?: string[];
};

export type Category = {
  id: number;
  parent_id: number | null;
  name: string;
  slug: string;
  description: string | null;
  status: string;
  visibility: string;
  sort_order: number;
};

export type Brand = { id: number; name: string; slug: string; description: string | null };
export type AttributeOption = { id: number; value: string; slug: string; color_code: string | null; is_active: boolean };
export type Attribute = {
  id: number;
  name: string;
  key: string;
  type: string;
  /** Active values only. */
  values?: string[];
  /** Phase B41. */
  group?: string | null;
  unit?: string | null;
  is_active?: boolean;
  sort_order?: number;
  options?: AttributeOption[];
};
export type ProductImage = { id: string; url: string; alt: string | null; position: number; variant_id: string | null };

/** Categories in tree order, each with its depth, for an indented list or select. */
export function categoryTree(categories: Category[]): { category: Category; depth: number }[] {
  const byParent = new Map<number | null, Category[]>();
  const known = new Set(categories.map((category) => category.id));
  for (const category of categories) {
    // A parent that is not in the list (deleted) makes the category a root.
    const parent = category.parent_id !== null && known.has(category.parent_id) ? category.parent_id : null;
    byParent.set(parent, [...(byParent.get(parent) ?? []), category]);
  }
  const result: { category: Category; depth: number }[] = [];
  const seen = new Set<number>();
  const walk = (parent: number | null, depth: number) => {
    for (const category of byParent.get(parent) ?? []) {
      if (seen.has(category.id)) continue;
      seen.add(category.id);
      result.push({ category, depth });
      walk(category.id, depth + 1);
    }
  };
  walk(null, 0);
  // Categories that point at each other in a circle have no root. The server
  // refuses such a tree; if one ever exists it is still listed, not lost.
  for (const category of categories) {
    if (!seen.has(category.id)) {
      seen.add(category.id);
      result.push({ category, depth: 0 });
    }
  }

  return result;
}

/** "Colour: Red, Size: M" for a variant's options, or its SKU. */
export function variantLabel(variant: Pick<Variant, 'sku' | 'option_values'>): string {
  const values = Object.entries(variant.option_values ?? {});
  if (values.length > 0) return values.map(([key, value]) => `${key}: ${value}`).join(', ');

  return variant.sku ?? 'Variant';
}

/** "Size: M" lines as an object. A line without a colon is reported, not guessed. */
export function parseOptionLines(text: string): { values: Record<string, string>; error?: string } {
  const values: Record<string, string> = {};
  for (const line of text.split('\n').map((l) => l.trim()).filter((l) => l !== '')) {
    const at = line.indexOf(':');
    if (at <= 0 || at === line.length - 1) return { values, error: `Write each option as "Name: value" (problem: "${line}").` };
    values[line.slice(0, at).trim()] = line.slice(at + 1).trim();
  }

  return { values };
}

/* Phase B39 (gap G15): collections, tags, relations and bulk changes. */
export const COLLECTION_SORTS = ['manual', 'newest', 'price_asc', 'price_desc', 'name', 'best_selling'] as const;
export const RULE_FIELDS = ['category', 'brand', 'tag', 'price_min', 'price_max', 'on_sale', 'in_stock', 'featured', 'new_within_days'] as const;
export const RELATION_TYPES = ['related', 'cross_sell', 'up_sell', 'alternative'] as const;
export const RELATION_LABELS: Record<(typeof RELATION_TYPES)[number], { title: string; hint: string }> = {
  related: { title: 'Related products', hint: 'Shown as “You may also like”. Without any, other products of the same category are shown.' },
  cross_sell: { title: 'Goes well with', hint: 'Products bought together with this one.' },
  up_sell: { title: 'Better options', hint: 'A more complete or premium choice.' },
  alternative: { title: 'Alternatives', hint: 'Similar products, shown with the related ones.' },
};

export type CollectionRule = { field: (typeof RULE_FIELDS)[number]; value: number | boolean | number[] };

export type Collection = {
  id: string;
  /** The numeric key promotion targets take. */
  internal_id: number;
  name: string;
  slug: string;
  description: string | null;
  type: 'manual' | 'rule';
  rules: CollectionRule[];
  match: 'all' | 'any';
  sort: (typeof COLLECTION_SORTS)[number];
  status: 'draft' | 'active';
  is_visible: boolean;
  is_live: boolean;
  starts_at: string | null;
  ends_at: string | null;
  sort_order: number;
  product_count: number;
  products?: { id: string; name: string; status: string; price_minor: number | null }[];
  preview_count?: number;
};

export type Tag = { id: number; name: string; slug: string; product_count: number };

export type ProductRef = { id: string; name: string; status: string };

export type BulkResult = { affected: number; skipped: { id: string; name: string; reason: string }[] };

/** Tags typed as "Eid, Gift" → ["Eid", "Gift"] (the server trims and de-duplicates too). */
export function parseTags(text: string): string[] {
  return text.split(',').map((tag) => tag.trim()).filter((tag) => tag !== '');
}

/* Phase B40 (Module 06 §48–51): product import and export. */
export type ProductImportPreview = {
  id: string;
  totals: { rows: number; create: number; update: number; invalid: number; variants: number };
  rows: { row: number; kind: 'product' | 'variant'; label: string; sku: string; status: 'create' | 'update' | 'invalid'; messages: string[] }[];
  new_brands: string[];
  new_categories: string[];
  notices: string[];
  columns: string[];
};

export type ProductImportResult = { created: number; updated: number; variants_created: number; variants_updated: number; skipped: { row: number; reason: string }[] };

export const PRODUCT_IMPORT_TEMPLATE = [
  'sku,name,status,visibility,price,sale_price,currency,brand,category,tags,featured,parent_sku,options,attributes',
  'LAWN-01,Printed Lawn Suit,active,public,4500,3999,PKR,Gul Ahmed,Clothing > Women,Eid; Summer,yes,,,',
  'LAWN-01-S,,active,,4500,,,,,,,LAWN-01,Size: S,',
  'LAWN-01-M,,active,,4500,,,,,,,LAWN-01,Size: M,',
  'ATR-12,Rose Attar 12 ml,draft,public,1200,,PKR,,Fragrance,,no,,,',
].join('\n');

/* Phase B41 (Module 07 §18–19, §41–49): attribute sets, category attributes, specifications. */
export type AttributeSet = { id: number; name: string; attributes: number[] };
export type CategoryAttributeItem = { attribute_id: number; is_required: boolean; is_filter: boolean };
export type SpecificationValue = number | number[] | boolean | string | null;
export type Specifications = { values: { attribute_id: number; value: SpecificationValue }[]; suggested: { attribute_id: number; is_required: boolean }[] };

/** Types whose values are chosen from the attribute's list. */
export const hasValues = (type: string) => type === 'select' || type === 'multi_select' || type === 'color';
