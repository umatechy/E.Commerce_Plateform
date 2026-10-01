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
export const ATTRIBUTE_TYPES = ['select', 'multi_select', 'boolean', 'numeric', 'text'] as const;

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
export type Attribute = { id: number; name: string; key: string; type: string; values?: string[] };
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
